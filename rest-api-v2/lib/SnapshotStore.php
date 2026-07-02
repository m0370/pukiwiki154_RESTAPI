<?php
declare(strict_types=1);

/**
 * API 書き込み時の全版スナップショット（追記専用・gzip）。
 *
 * PukiWiki 標準の backup/ は時間間隔制（cycle 内の連続編集は中間版を取りこぼす）
 * のため、API 経由の書き込みは全版をここに保存して補完する。
 *
 * 保存先: {dir}/{ENCODED_PAGE}/{秒}.{マイクロ秒6桁}_{sha1}.txt.gz
 *   - マイクロ秒精度により、同一秒内の連続書き込みでも保存順が一意に定まる
 *   - ファイル名に内容の sha1 を含む（重複排除・改ざん検証が可能）
 *   - 同一 sha1 のスナップショットが既にあれば保存しない（冪等）
 *   - unlink は行わない（追記専用）
 *   - zlib 拡張が無い環境（共有ホスティング等）では非圧縮の .txt で保存する。
 *     読み出しは両形式に対応するため、途中で zlib を有効化/無効化しても混在できる
 * @version v2.0
 */
final class SnapshotStore
{
    public function __construct(private string $dir) {}

    /**
     * 内容を保存する。同一 sha1 が既存なら何もしない。
     * @return string|null 保存したスナップショット ID（"{sec}.{usec}_{sha1}"）。重複時は null
     */
    public function saveIfNew(string $page, string $content): ?string
    {
        $sha1 = sha1($content);
        $pdir = $this->pageDir($page);

        if (!is_dir($pdir) && !mkdir($pdir, 0750, true) && !is_dir($pdir)) {
            throw new \RuntimeException("Cannot create snapshot dir: {$pdir}");
        }

        // 重複排除: 同じ sha1 のスナップショットが既にあればスキップ（.txt.gz / .txt 両対応）
        if (glob($pdir . '/*_' . $sha1 . '.txt') !== []
            || glob($pdir . '/*_' . $sha1 . '.txt.gz') !== []) {
            return null;
        }

        $tod = gettimeofday();
        $id  = sprintf('%d.%06d_%s', $tod['sec'], $tod['usec'], $sha1);

        if (function_exists('gzencode')) {
            $data = gzencode($content, 6);
            if ($data === false) {
                throw new \RuntimeException("gzencode failed for page={$page}");
            }
            $ext = '.txt.gz';
        } else {
            // zlib 無効環境では非圧縮で保存する
            $data = $content;
            $ext  = '.txt';
        }

        // temp → rename の原子的書き込み
        $tmp = $pdir . '/.tmp_' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $data, LOCK_EX) === false) {
            throw new \RuntimeException("snapshot write failed: {$tmp}");
        }
        if (!rename($tmp, $pdir . '/' . $id . $ext)) {
            @unlink($tmp);
            throw new \RuntimeException("snapshot rename failed: {$id}");
        }
        return $id;
    }

    /**
     * ページのスナップショット一覧（新しい順）。
     * @return array<array{id: string, ts: int, time: string, sha1: string, gz_size: int}>
     */
    public function list(string $page): array
    {
        $pdir = $this->pageDir($page);
        if (!is_dir($pdir)) {
            return [];
        }

        $items = [];
        foreach (array_merge(glob($pdir . '/*.txt.gz') ?: [], glob($pdir . '/*.txt') ?: []) as $path) {
            $name = preg_replace('/\.txt(\.gz)?$/', '', basename($path));
            if (!preg_match('/^(\d+)\.(\d{6})_([0-9a-f]{40})$/', $name, $m)) {
                continue;
            }
            $items[] = [
                'id'      => $name,
                'ts'      => (int)$m[1],
                'usec'    => (int)$m[2],
                'time'    => date('c', (int)$m[1]),
                'sha1'    => $m[3],
                'gz_size' => (int)filesize($path),
            ];
        }
        usort($items, static fn($a, $b) => [$b['ts'], $b['usec']] <=> [$a['ts'], $a['usec']]);
        return $items;
    }

    /**
     * スナップショットの本文を返す。sha1 を再計算して整合性を検証する。
     * @param string $id "{sec}.{usec}_{sha1}" 形式（list() の id）
     */
    public function read(string $page, string $id): string
    {
        if (!preg_match('/^\d+\.\d{6}_([0-9a-f]{40})$/', $id, $m)) {
            throw new \ApiException(400, 'Invalid revision id format', 'invalid_revision_id');
        }
        $base = $this->pageDir($page) . '/' . $id;
        $path = is_file($base . '.txt.gz') ? $base . '.txt.gz'
              : (is_file($base . '.txt')   ? $base . '.txt' : null);
        if ($path === null) {
            throw new \ApiException(404, "Revision '{$id}' not found", 'revision_not_found');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read snapshot: {$path}");
        }
        if (str_ends_with($path, '.gz')) {
            if (!function_exists('gzdecode')) {
                throw new \RuntimeException("zlib extension required to read gzip snapshot: {$id}");
            }
            $content = gzdecode($raw);
            if ($content === false) {
                throw new \RuntimeException("gzdecode failed: {$id}");
            }
        } else {
            $content = $raw;
        }
        if (sha1($content) !== $m[1]) {
            throw new \RuntimeException("Snapshot integrity check failed: {$id}");
        }
        return $content;
    }

    private function pageDir(string $page): string
    {
        // PukiWiki と同じエンコード（大文字 hex）でページ毎のディレクトリを作る
        $enc = function_exists('encode') ? encode($page) : strtoupper(bin2hex($page));
        return $this->dir . '/' . $enc;
    }
}
