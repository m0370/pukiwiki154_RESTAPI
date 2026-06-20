<?php
declare(strict_types=1);

/**
 * 中途状態を残さないファイル書き込みヘルパ。
 *
 * POSIX の rename() は同一ファイルシステム内であれば
 * 「旧ファイルを新ファイルで一息に置き換える」操作であり、
 * 読み手は常に完全な旧版か完全な新版のどちらかを見る。
 * これを利用して temp→fsync→rename の順序で書き込む。
 *
 * 注意: NFS やクラウド同期フォルダ（iCloud Drive 等）では
 * rename() の保証が失われる。本番はローカル FS 上の Web サーバーで運用すること。
 * @version v0.1
 */
final class AtomicWriter
{
    /**
     * $content を $dest へ書き込む。
     * 書き込み中に失敗しても $dest の旧内容は保持される。
     */
    public static function write(string $dest, string $content): void
    {
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("mkdir failed: {$dir}");
        }

        // 同じディレクトリに一時ファイルを作る（= 同一FS = rename が原子的）
        $tmp = tempnam($dir, '.rest_tmp_');
        if ($tmp === false) {
            throw new \RuntimeException("tempnam failed in: {$dir}");
        }

        try {
            if (file_put_contents($tmp, $content, LOCK_EX) === false) {
                throw new \RuntimeException("file_put_contents failed: {$tmp}");
            }

            // fsync: OSバッファをディスクに確定させてから rename する
            $fh = fopen($tmp, 'r');
            if ($fh === false) {
                throw new \RuntimeException("fopen failed: {$tmp}");
            }
            $synced = fsync($fh);
            fclose($fh);
            if (!$synced) {
                throw new \RuntimeException("fsync failed: {$tmp}");
            }

            if (!rename($tmp, $dest)) {
                throw new \RuntimeException("rename failed: {$tmp} → {$dest}");
            }
        } catch (\Throwable $e) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            throw $e;
        }
    }

    /**
     * ソフト削除: $src を $archive_dir へ移動し unlink しない。
     * ロールバックや監査証跡のためにファイルを消さない設計。
     */
    public static function archive(string $src, string $archive_dir): string
    {
        if (!is_dir($archive_dir) && !mkdir($archive_dir, 0755, true) && !is_dir($archive_dir)) {
            throw new \RuntimeException("mkdir failed: {$archive_dir}");
        }
        $dest = $archive_dir . '/' . basename($src) . '.' . time() . '.' . getmypid();
        if (!rename($src, $dest)) {
            throw new \RuntimeException("archive rename failed: {$src} → {$dest}");
        }
        return $dest;
    }
}
