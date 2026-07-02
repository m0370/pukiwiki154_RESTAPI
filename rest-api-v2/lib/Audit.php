<?php
declare(strict_types=1);

/**
 * 追記専用の監査ログ（JSONL・月別ローテーション）。
 *
 * data/audit/audit-YYYYMM.jsonl に 1 操作 = 1 行の JSON を追記する。
 * 削除・書き換えは行わない。ファイルは data/ 配下なので Web 非公開。
 * @version v2.0
 */
final class Audit
{
    public function __construct(private string $dir) {}

    /**
     * @param string $action 例: 'page_written', 'write_denied', 'auth_failed'
     * @param array  $fields page, actor, ip, base_sha1, new_sha1 など任意
     */
    public function log(string $action, array $fields = []): void
    {
        $now  = time();
        $line = json_encode(
            array_merge(
                ['ts' => $now, 'time' => date('c', $now), 'action' => $action],
                $fields
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $file = $this->dir . '/audit-' . date('Ym', $now) . '.jsonl';
        // FILE_APPEND + LOCK_EX で行単位の原子的追記
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
