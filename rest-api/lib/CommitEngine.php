<?php
declare(strict_types=1);

require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/RevisionStore.php';
require_once __DIR__ . '/AtomicWriter.php';
require_once __DIR__ . '/Reconciler.php';
require_once __DIR__ . '/ApiException.php';

/**
 * 共有コミットエンジン。
 *
 * ページへの書き込みを「ガバナンスに依存しない単一のエンジン」として実装する。
 * 下書き公開（DraftManager）と将来の直接編集（PUT /pages/{p}）の両方がここを通る。
 * 前段のガバナンス（下書き承認・スコープ確認）はエンジンの外側で行う。
 *
 * 処理順序（ファイル先・台帳後の原則を厳守）:
 *   1. リース取得 → 期限切れ孤児は自動回収
 *   2. CAS 確認  → sha1(現在のファイル) == base_sha1 でなければ 409
 *   3. 権限確認  → is_freeze() / is_editable()（PukiWiki ロード時のみ）
 *   4. ファイル書き込み
 *      - PukiWiki ロード時: page_write()（diff/backup/links_update も実行）
 *      - スタンドアロン時: AtomicWriter::write()（temp→fsync→rename）
 *   5. SQLite トランザクション
 *      → upsertPage / appendRevision / RevisionStore / indexPage / audit
 *   6. リース解放（成否に関わらず finally で実行）
 *
 * 新規ページの作成:
 *   ファイルが存在しない場合、base_sha1 は EMPTY_SHA1（sha1('')）でなければ 409。
 *   AI は存在しないページに対して wiki_read_page を呼び、
 *   「does not exist」と分かった場合に CommitEngine::EMPTY_SHA1 を base_sha1 として使う。
 * @version v0.1
 */
final class CommitEngine
{
    /** sha1('') — 新規ページ作成時の base_sha1 センチネル */
    public const EMPTY_SHA1 = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

    public function __construct(
        private Ledger        $ledger,
        private RevisionStore $revisions,
        private string        $wiki_dir,
    ) {}

    /**
     * ページへ書き込みコミット。
     *
     * @param string $page      対象ページ名
     * @param string $new_body  新しい本文
     * @param string $base_sha1 クライアントが読んだ時の sha1（CAS トークン）
     * @param string $actor     書き込みアクター（監査ログ・版メタ用）
     * @param array  $meta      版メタデータ（source, draft_id 等）
     * @param int    $lock_ttl  リース保持時間（秒）。デフォルト 30 秒
     *
     * @return array{new_rev: int, new_sha1: string, committed_at: int}
     *
     * @throws ApiException
     *   423 page_locked          — 別の書き込みがリース中
     *   409 sha1_conflict        — ファイルが更新されている（競合）
     *   409 page_not_found_as_conflict — 新規ページなのに非空の base_sha1
     *   403 page_frozen          — 凍結ページ（PukiWiki ロード時のみ）
     *   403 page_not_editable    — 編集禁止ページ（PukiWiki ロード時のみ）
     */
    public function commit(
        string $page,
        string $new_body,
        string $base_sha1,
        string $actor,
        array  $meta     = [],
        int    $lock_ttl = 30
    ): array {
        $now      = time();
        $file     = $this->wikiFile($page);
        $lock_key = 'engine:' . bin2hex(random_bytes(8));

        // 1. リース取得
        if (!$this->ledger->acquireLock($page, $lock_key, $lock_ttl, $now)) {
            throw new ApiException(
                423,
                "Page '{$page}' is currently locked by another writer. Please retry later.",
                'page_locked'
            );
        }

        try {
            // 2. CAS 確認（sha1 による楽観的競合検出）
            if (file_exists($file)) {
                $current_sha1 = sha1((string)file_get_contents($file));
                if ($current_sha1 !== $base_sha1) {
                    throw new ApiException(409, implode(' ', [
                        "Conflict: page '{$page}' has been modified since your base.",
                        "Expected sha1={$base_sha1}, current sha1={$current_sha1}.",
                        "Re-read the page and create a new draft based on the current version.",
                    ]), 'sha1_conflict');
                }
            } else {
                // ファイル未存在（新規ページ）: EMPTY_SHA1 のみ許可
                if ($base_sha1 !== self::EMPTY_SHA1) {
                    throw new ApiException(409, implode(' ', [
                        "Page '{$page}' does not exist.",
                        "For new pages use base_sha1=" . self::EMPTY_SHA1 . " (sha1 of empty string).",
                    ]), 'page_not_found_as_conflict');
                }
            }

            // 3. PukiWiki の凍結・編集禁止チェック（ロードされている場合のみ）
            if (function_exists('is_freeze') && is_freeze($page)) {
                throw new ApiException(403, "Page '{$page}' is frozen.", 'page_frozen');
            }
            if (function_exists('is_editable') && !is_editable($page)) {
                throw new ApiException(403, "Page '{$page}' is not editable.", 'page_not_editable');
            }

            // 4. ファイル書き込み（ファイル先・台帳後の原則）
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0755, true);
            }
            if (function_exists('page_write')) {
                // PukiWiki 本体のチョークポイント
                // diff/backup/links_update/pagelist キャッシュ更新を実行
                page_write($page, $new_body);
            } else {
                // スタンドアロンモード
                AtomicWriter::write($file, $new_body);
            }
            $new_sha1 = sha1($new_body);

            // 5. SQLite トランザクション（台帳・版履歴・インデックス・監査）
            $this->ledger->getPdo()->beginTransaction();
            try {
                $page_rec = $this->ledger->getPage($page);
                $new_rev  = ($page_rec !== null ? (int)$page_rec['current_rev'] : 0) + 1;

                $this->ledger->upsertPage($page, $new_rev, $new_sha1, $now);

                // 版 blob（content-addressed snapshot）を保存
                $this->revisions->append($new_body, $page, $new_rev, $actor);

                // 版メタデータを台帳に追記
                $this->ledger->appendRevision($page, $new_rev, $new_sha1, $actor, $meta, $now);

                // 全文検索インデックスを更新
                $this->ledger->indexPage($page, $new_body, $now);

                // 監査ログ
                $this->ledger->log('page_committed', $page, $actor, array_merge($meta, [
                    'new_rev'   => $new_rev,
                    'new_sha1'  => $new_sha1,
                    'base_sha1' => $base_sha1,
                ]));

                $this->ledger->getPdo()->commit();
            } catch (\Throwable $e) {
                $this->ledger->getPdo()->rollBack();
                throw $e;
            }

            return [
                'new_rev'      => $new_rev,
                'new_sha1'     => $new_sha1,
                'committed_at' => $now,
            ];

        } finally {
            // ロックは成否に関わらず解放
            $this->ledger->releaseLock($page, $lock_key);
        }
    }

    private function wikiFile(string $page): string
    {
        return $this->wiki_dir . '/' . Reconciler::encode($page) . '.txt';
    }
}
