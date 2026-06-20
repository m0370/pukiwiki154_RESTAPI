<?php
declare(strict_types=1);

require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/RevisionStore.php';
require_once __DIR__ . '/CommitEngine.php';
require_once __DIR__ . '/AtomicWriter.php';
require_once __DIR__ . '/ApiException.php';

/**
 * 管理者専用操作のビジネスロジック。
 *
 * スコープ: admin
 *
 * 提供する機能:
 *   - 期限切れ下書きの一括失効（expireDrafts）
 *   - ページのロールバック（rollback）
 *   - ページのソフト削除（softDelete）
 *
 * 設計原則:
 *   - unlink() は使わない。削除は AtomicWriter::archive() 経由のみ。
 *   - ロールバックは CommitEngine 経由で行い、新しいリビジョンとして記録する。
 *   - 全操作は audit ログに記録する。
 * @version v0.1
 */
final class AdminManager
{
    public function __construct(
        private Ledger        $ledger,
        private RevisionStore $revisions,
        private CommitEngine  $engine,
        private string        $wiki_dir,
    ) {}

    // -------------------------------------------------------------------------
    // 期限切れ下書きの一括失効
    // -------------------------------------------------------------------------

    /**
     * 有効期限を過ぎた open 状態の下書きを 'expired' に変更する。
     *
     * @return int 失効させた件数
     */
    public function expireDrafts(string $actor, int $now): int
    {
        $count = $this->ledger->expireDrafts($now);
        if ($count > 0) {
            $this->ledger->log('drafts_expired', null, $actor, [
                'expired_count' => $count,
                'expired_at'    => $now,
            ]);
        }
        return $count;
    }

    // -------------------------------------------------------------------------
    // ページのロールバック
    // -------------------------------------------------------------------------

    /**
     * ページを指定リビジョンにロールバックする。
     *
     * ロールバックは「古い内容を新しいリビジョンとして追記する」操作。
     * 古いリビジョンを削除しない（追記のみ・ソフトロールバック）。
     *
     * @param string $page        対象ページ名
     * @param int    $target_rev  ロールバック先のリビジョン番号
     * @param string $actor       操作者
     * @param string $reason      理由（監査ログ用）
     * @param int    $now         現在時刻（UNIX タイムスタンプ）
     * @return array {new_rev, new_sha1, committed_at, rolled_back_from, target_rev}
     * @throws ApiException 404 ページまたはリビジョンが存在しない
     * @throws ApiException 409 現在の sha1 が競合（CommitEngine から伝播）
     * @throws ApiException 423 ロックが取れない（CommitEngine から伝播）
     */
    public function rollback(
        string $page,
        int    $target_rev,
        string $actor,
        string $reason,
        int    $now
    ): array {
        // 対象リビジョンを取得
        $revs = $this->ledger->listRevisions($page, 1000);
        $target = null;
        foreach ($revs as $r) {
            if ((int)$r['rev'] === $target_rev) {
                $target = $r;
                break;
            }
        }
        if ($target === null) {
            throw new ApiException(
                404,
                "Revision {$target_rev} not found for page '{$page}'.",
                'revision_not_found'
            );
        }

        // blob からコンテンツを復元
        $target_sha1 = $target['content_sha1'];
        if (!$this->revisions->has($target_sha1)) {
            throw new ApiException(
                404,
                "Blob for revision {$target_rev} (sha1={$target_sha1}) not found in store.",
                'blob_not_found'
            );
        }
        $old_content = $this->revisions->read($target_sha1);

        // 現在のリビジョンを取得（CAS に使う）
        $page_rec = $this->ledger->getPage($page);
        $wiki_file = $this->wiki_dir . '/' . strtoupper(bin2hex($page)) . '.txt';

        if ($page_rec === null && !file_exists($wiki_file)) {
            throw new ApiException(
                404,
                "Page '{$page}' not found.",
                'page_not_found'
            );
        }

        $current_sha1 = file_exists($wiki_file)
            ? sha1((string)file_get_contents($wiki_file))
            : CommitEngine::EMPTY_SHA1;

        // CommitEngine 経由でロールバック内容を新しいリビジョンとしてコミット
        $result = $this->engine->commit(
            $page, $old_content, $current_sha1, $actor,
            [
                'rollback'       => true,
                'target_rev'     => $target_rev,
                'target_sha1'    => $target_sha1,
                'reason'         => $reason,
            ]
        );

        $this->ledger->log('page_rolled_back', $page, $actor, [
            'from_rev'    => $page_rec['current_rev'] ?? 'unknown',
            'to_rev'      => $target_rev,
            'new_rev'     => $result['new_rev'],
            'reason'      => $reason,
        ]);

        return array_merge($result, [
            'rolled_back_from' => $page_rec['current_rev'] ?? null,
            'target_rev'       => $target_rev,
            'target_sha1'      => $target_sha1,
        ]);
    }

    // -------------------------------------------------------------------------
    // ページのソフト削除
    // -------------------------------------------------------------------------

    /**
     * ページをソフト削除する（unlink() は使わない）。
     *
     * - wiki ファイルを wiki/.archive/ へ移動（AtomicWriter::archive）
     * - DB の pages テーブルで status='deleted' に更新
     * - FTS5 インデックスからページを除去
     * - 監査ログに記録
     *
     * @param string $page   対象ページ名
     * @param string $actor  操作者
     * @param int    $now    現在時刻
     * @return array {archived_to, page, status, deleted_at}
     * @throws ApiException 404 ページが存在しない
     * @throws ApiException 409 既に削除済み
     */
    public function softDelete(string $page, string $actor, int $now): array
    {
        $page_rec  = $this->ledger->getPage($page);
        $wiki_file = $this->wiki_dir . '/' . strtoupper(bin2hex($page)) . '.txt';

        if ($page_rec === null && !file_exists($wiki_file)) {
            throw new ApiException(404, "Page '{$page}' not found.", 'page_not_found');
        }
        if ($page_rec !== null && ($page_rec['status'] ?? 'active') === 'deleted') {
            throw new ApiException(409, "Page '{$page}' is already deleted.", 'already_deleted');
        }

        // ファイルのみ存在して pages テーブルに未登録の場合（外部作成や buildIndex 前）は先に登録
        if ($page_rec === null && file_exists($wiki_file)) {
            $file_sha1 = sha1((string)file_get_contents($wiki_file));
            $this->ledger->upsertPage($page, 1, $file_sha1, $now);
        }

        $archive_dir  = $this->wiki_dir . '/.archive';
        $archived_to  = null;

        if (file_exists($wiki_file)) {
            $archived_to = AtomicWriter::archive($wiki_file, $archive_dir);
        }

        $this->ledger->markDeleted($page, $now);
        $this->ledger->deindexPage($page);
        $this->ledger->log('page_soft_deleted', $page, $actor, [
            'archived_to' => $archived_to,
            'deleted_at'  => $now,
        ]);

        return [
            'page'        => $page,
            'status'      => 'deleted',
            'archived_to' => $archived_to,
            'deleted_at'  => $now,
        ];
    }
}
