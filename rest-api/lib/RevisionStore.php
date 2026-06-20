<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicWriter.php';

/**
 * コンテントアドレス型の追記専用 blob ストア。
 *
 * MediaWiki の rev_sha1 と同じ発想: 内容の SHA1 をキーにして
 * {blob_dir}/{sha1[0:2]}/{sha1}.gz に gzip 圧縮して保存する。
 *
 * - 同じ内容を二度書いても重複しない（冪等）
 * - ファイルは絶対に削除しない（ロールバックや再構築に使う）
 * - sha1 を再計算して照合することでビットロットを検出できる
 * @version v0.1
 */
final class RevisionStore
{
    private string $blob_dir;

    public function __construct(string $blob_dir)
    {
        $this->blob_dir = rtrim($blob_dir, '/');
    }

    /**
     * $content を保存する。$page/$rev/$actor はメタとして利用可能だが
     * ストレージキーは sha1 のみ（内容が同じなら何度書いても同じ場所）。
     * @return string 保存した内容の SHA1（40桁 hex）
     */
    public function append(string $content, string $page, int $rev, string $actor): string
    {
        $sha1 = sha1($content);
        $path = $this->blobPath($sha1);

        if (file_exists($path)) {
            return $sha1; // 既存 blob（重複排除）
        }

        $gz = gzencode($content, 6);
        if ($gz === false) {
            throw new \RuntimeException("gzencode failed for page={$page} rev={$rev}");
        }

        AtomicWriter::write($path, $gz);
        return $sha1;
    }

    /** blob が存在するか確認 */
    public function has(string $sha1): bool
    {
        return file_exists($this->blobPath($sha1));
    }

    /** blob を読み込んで本文を返す */
    public function read(string $sha1): string
    {
        $path = $this->blobPath($sha1);
        if (!file_exists($path)) {
            throw new \RuntimeException("Blob not found: sha1={$sha1}");
        }
        $gz = file_get_contents($path);
        if ($gz === false) {
            throw new \RuntimeException("file_get_contents failed: {$path}");
        }
        $content = gzdecode($gz);
        if ($content === false) {
            throw new \RuntimeException("gzdecode failed: sha1={$sha1}");
        }
        return $content;
    }

    /**
     * 整合性検証: 保存された blob の sha1 を再計算して一致するか確認。
     * ビットロット・改ざん検出に使う。
     * 壊れたデータに対する gzdecode() の警告は検証文脈では期待動作なので抑制する。
     */
    public function verify(string $sha1): bool
    {
        if (!$this->has($sha1)) {
            return false;
        }
        try {
            $gz = file_get_contents($this->blobPath($sha1));
            if ($gz === false) {
                return false;
            }
            $content = @gzdecode($gz);
            if ($content === false) {
                return false;
            }
            return sha1($content) === $sha1;
        } catch (\Throwable) {
            return false;
        }
    }

    /** sha1 すべてに対して verify を走らせ、壊れた blob のリストを返す */
    public function auditAll(): array
    {
        $broken = [];
        $prefixes = glob($this->blob_dir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($prefixes as $prefix_dir) {
            foreach (glob($prefix_dir . '/*.gz') ?: [] as $path) {
                $sha1 = basename($path, '.gz');
                if (!$this->verify($sha1)) {
                    $broken[] = $sha1;
                }
            }
        }
        return $broken;
    }

    private function blobPath(string $sha1): string
    {
        $prefix = substr($sha1, 0, 2);
        return "{$this->blob_dir}/{$prefix}/{$sha1}.gz";
    }
}
