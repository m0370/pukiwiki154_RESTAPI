<?php
declare(strict_types=1);

require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/CommitEngine.php';
require_once __DIR__ . '/DiffEngine.php';
require_once __DIR__ . '/Reconciler.php';
require_once __DIR__ . '/ApiException.php';

/**
 * 下書きの承認・却下・差分プレビューを管理する。
 *
 * 承認フロー（下書き固有のガバナンス部分）:
 *   1. 下書きが open かつ未失効であることを確認
 *   2. CommitEngine::commit() で実際の書き込みを実行
 *      → ロック・CAS・freeze チェック・ファイル書き込み・DB 更新は全てエンジンに委譲
 *   3. 下書きステータスを 'published' に変更
 *   4. 監査ログ記録
 *
 * この分離により、共有コミットエンジンは下書き公開以外（将来の PUT /pages/{p} 等）
 * からも同一の実装で呼び出せる。
 * @version v0.1
 */
final class DraftManager
{
    public function __construct(
        private Ledger       $ledger,
        private CommitEngine $engine,
        private string       $wiki_dir,
    ) {}

    /**
     * 下書き詳細を取得し、差分プレビューを生成して返す。
     *
     * @return array{
     *   draft: array,
     *   current_sha1: string,
     *   current_content: string,
     *   is_conflict: bool,
     *   diff: string,
     *   diff_stats: array,
     *   diff_html: string,
     * }
     */
    public function getWithDiff(int $id): array
    {
        $draft = $this->ledger->getDraft($id);
        if ($draft === null) {
            throw new ApiException(404, "Draft #{$id} not found", 'draft_not_found');
        }

        $file            = $this->wikiFile($draft['page']);
        $current_content = file_exists($file) ? (string)file_get_contents($file) : '';
        $current_sha1    = sha1($current_content);
        $is_conflict     = $current_sha1 !== (string)$draft['base_sha1']
                        && !($current_content === '' && $draft['base_sha1'] === CommitEngine::EMPTY_SHA1);

        $label_old = "{$draft['page']} (current, sha1:{$current_sha1})";
        $label_new = "{$draft['page']} (draft #{$id})";
        $diff      = DiffEngine::unified($current_content, (string)$draft['body'], $label_old, $label_new);
        $stats     = DiffEngine::stats($diff);

        $meta = is_string($draft['meta']) ? json_decode($draft['meta'], true) : $draft['meta'];

        return [
            'draft'           => array_merge($draft, ['meta' => $meta]),
            'current_sha1'    => $current_sha1,
            'current_content' => $current_content,
            'is_conflict'     => $is_conflict,
            'diff'            => $diff,
            'diff_stats'      => $stats,
            'diff_html'       => DiffEngine::toHtml($diff),
        ];
    }

    /**
     * 下書きを承認してページへ公開する。
     *
     * ガバナンス確認（open? 失効?) のみここで行い、実際の書き込みは CommitEngine に委譲。
     *
     * @return array{new_rev: int, new_sha1: string} 公開後の版情報
     * @throws ApiException 404/409/410/423/403
     */
    public function approve(int $id, string $approver, int $now): array
    {
        $draft = $this->ledger->getDraft($id);
        if ($draft === null) {
            throw new ApiException(404, "Draft #{$id} not found", 'draft_not_found');
        }
        if ($draft['status'] !== 'open') {
            throw new ApiException(409, "Draft #{$id} is already {$draft['status']}", 'draft_not_open');
        }
        if ($draft['expires_at'] !== null && (int)$draft['expires_at'] < $now) {
            $this->ledger->updateDraftStatus($id, 'expired', $now);
            throw new ApiException(410, "Draft #{$id} has expired", 'draft_expired');
        }

        // 書き込みは共有コミットエンジンへ委譲
        $result = $this->engine->commit(
            (string)$draft['page'],
            (string)$draft['body'],
            (string)$draft['base_sha1'],
            $approver,
            [
                'source'   => 'draft_approved',
                'draft_id' => $id,
                'owner'    => $draft['owner'],
            ]
        );

        // 書き込み成功後に下書きステータスを published に変更
        $this->ledger->updateDraftStatus($id, 'published', $now);
        $this->ledger->log('draft_published', (string)$draft['page'], $approver, [
            'draft_id'  => $id,
            'owner'     => $draft['owner'],
            'new_rev'   => $result['new_rev'],
            'new_sha1'  => $result['new_sha1'],
        ]);

        return ['new_rev' => $result['new_rev'], 'new_sha1' => $result['new_sha1']];
    }

    /**
     * 下書きを却下する。
     *
     * @throws ApiException 404/409
     */
    public function reject(int $id, string $rejector, string $reason, int $now): void
    {
        $draft = $this->ledger->getDraft($id);
        if ($draft === null) {
            throw new ApiException(404, "Draft #{$id} not found", 'draft_not_found');
        }
        if ($draft['status'] !== 'open') {
            throw new ApiException(409, "Draft #{$id} is already {$draft['status']}", 'draft_not_open');
        }

        $this->ledger->updateDraftStatus($id, 'rejected', $now);
        $this->ledger->log('draft_rejected', (string)$draft['page'], $rejector, [
            'draft_id' => $id,
            'owner'    => $draft['owner'],
            'reason'   => $reason,
        ]);
    }

    // -------------------------------------------------------------------------

    private function wikiFile(string $page): string
    {
        return $this->wiki_dir . '/' . Reconciler::encode($page) . '.txt';
    }
}
