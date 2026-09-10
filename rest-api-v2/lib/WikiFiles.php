<?php
declare(strict_types=1);

/** PukiWiki 標準バックアップ・添付。ページ認可は全て PageStore に委譲する。 */
final class WikiFiles
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const EXTENSIONS = ['pdf','png','jpg','jpeg','gif','webp','txt','md','csv','docx','xlsx','pptx','zip'];

    public function __construct(private PageStore $pages, private Audit $audit, private array $key) {}

    public function backups(string $page, ?int $age = null): array
    {
        $this->pages->read($page); // 削除済みページは公開しない
        if (!function_exists('get_backup')) throw new ApiException(501, 'PukiWiki backups are unavailable', 'unsupported');
        $backups = get_backup($page);
        if ($age === null) {
            $list = [];
            foreach ($backups as $n => $backup) {
                $list[] = ['age' => (int)$n, 'mtime' => (int)$backup['time'], 'updated_at' => date('c', (int)$backup['time'])];
            }
            return ['page' => $page, 'source' => 'pukiwiki_backup', 'backups' => $list];
        }
        if ($age < 1 || !isset($backups[$age])) throw new ApiException(404, 'Backup not found', 'backup_not_found');
        $content = implode('', $backups[$age]['data']);
        if (strlen($content) > self::MAX_BYTES) throw new ApiException(413, 'Backup is too large', 'content_too_large');
        return ['page' => $page, 'source' => 'pukiwiki_backup', 'age' => $age,
            'content' => $content, 'sha1' => sha1($content), 'mtime' => (int)$backups[$age]['time']];
    }

    private function directory(): string
    {
        if (!function_exists('exist_plugin') || !exist_plugin('attach') || !defined('UPLOAD_DIR')) {
            throw new ApiException(501, 'attach plugin is unavailable', 'unsupported');
        }
        $dir = realpath(UPLOAD_DIR);
        if ($dir === false) throw new ApiException(503, 'Attachment directory is unavailable', 'attachments_unavailable');
        return $dir . '/';
    }

    private function path(string $page, string $name, int $age = 0): string
    {
        if ($name === '' || strlen($name) > 180 || preg_match('/[\\x00-\\x1f\\x7f\/\\\\]/', $name)
            || in_array($name, ['.', '..'], true) || !mb_check_encoding($name, 'UTF-8') || $age < 0) {
            throw new ApiException(400, 'Invalid attachment filename or age', 'invalid_filename');
        }
        $base = $this->directory() . strtoupper(bin2hex($page)) . '_' . strtoupper(bin2hex($name));
        $file = $base . ($age ? '.' . $age : '');
        if (is_link($file) || is_link($base . '.log')) throw new ApiException(403, 'Symlink is not permitted', 'invalid_attachment');
        return $file;
    }

    public function list(string $page): array
    {
        $this->pages->read($page);
        $prefix = strtoupper(bin2hex($page)) . '_';
        $list = [];
        foreach (new DirectoryIterator($this->directory()) as $item) {
            $leaf = $item->getFilename();
            if (!str_starts_with($leaf, $prefix) || $item->isLink() || !$item->isFile()) continue;
            $suffix = substr($leaf, strlen($prefix));
            if (!preg_match('/^((?:[A-F0-9]{2})+)(?:\.([1-9][0-9]*))?$/D', $suffix, $m)) continue;
            $name = hex2bin($m[1]);
            if (!mb_check_encoding($name, 'UTF-8')) continue;
            $age = isset($m[2]) ? (int)$m[2] : 0;
            $list[] = ['name' => $name, 'age' => $age, 'size' => $item->getSize(), 'mtime' => $item->getMTime(),
                'sha256' => hash_file('sha256', $item->getPathname())];
        }
        usort($list, static fn($a, $b) => strcmp($a['name'], $b['name']) ?: $a['age'] <=> $b['age']);
        return ['page' => $page, 'attachments' => $list, 'max_bytes' => self::MAX_BYTES];
    }

    public function read(string $page, string $name, int $age = 0): array
    {
        $this->pages->read($page);
        $file = $this->path($page, $name, $age);
        if (!is_file($file)) throw new ApiException(404, 'Attachment not found', 'attachment_not_found');
        $content = file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
        if ($content === false) throw new ApiException(500, 'Cannot read attachment', 'read_failed');
        if (strlen($content) > self::MAX_BYTES) throw new ApiException(413, 'Attachment exceeds 5 MiB', 'content_too_large');
        $mime = function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->buffer($content) : 'application/octet-stream';
        return ['page' => $page, 'name' => $name, 'age' => $age, 'size' => strlen($content),
            'sha256' => hash('sha256', $content), 'mime_type' => $mime, 'content_base64' => base64_encode($content)];
    }

    private function changing(string $page, callable $operation): array
    {
        if (($this->key['scope'] ?? '') !== 'write' || ($this->key['attachments_write'] ?? false) !== true) {
            throw new ApiException(403, 'Administrator must grant attachment management to this key', 'attachment_scope_required');
        }
        return $this->pages->withWritablePage($page, $operation);
    }

    public function upload(string $page, string $name, string $base64): array
    {
        return $this->changing($page, function () use ($page, $name, $base64) {
            $file = $this->path($page, $name);
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                throw new ApiException(400, 'Unsupported file type', 'unsupported_file_type');
            }
            $limit = min(self::MAX_BYTES, defined('PLUGIN_ATTACH_MAX_FILESIZE') ? PLUGIN_ATTACH_MAX_FILESIZE : self::MAX_BYTES);
            if (strlen($base64) > 4 * ceil($limit / 3)) throw new ApiException(413, 'Attachment too large', 'content_too_large');
            $bytes = base64_decode($base64, true);
            if ($bytes === false || $bytes === '') throw new ApiException(400, 'Expected non-empty Base64 data', 'invalid_base64');
            if (strlen($bytes) > $limit) throw new ApiException(413, 'Attachment too large', 'content_too_large');
            $this->assertUnfrozen($file);
            // 新規作成のみ。既存ファイルの上書きは認めず、標準履歴を維持する。
            $fp = @fopen($file, 'xb');
            if (!$fp) throw new ApiException(file_exists($file) ? 409 : 500, 'Attachment already exists or cannot be created', 'attachment_create_failed');
            try {
                if (fwrite($fp, $bytes) !== strlen($bytes) || !fflush($fp)) {
                    throw new ApiException(500, 'Attachment write failed', 'write_failed');
                }
            } catch (Throwable $e) { fclose($fp); unlink($file); throw $e; }
            fclose($fp);
            chmod($file, defined('PLUGIN_ATTACH_FILE_MODE') ? PLUGIN_ATTACH_FILE_MODE : 0644);
            if (!is_file($file . '.log')) $this->writeLog($file, ['0','0','','']);
            $this->audit->log('attachment_uploaded', ['page' => $page, 'filename' => $name, 'actor' => $this->key['label'], 'sha256' => hash('sha256', $bytes)]);
            return ['page' => $page, 'name' => $name, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        });
    }

    private function writeLog(string $file, array $lines): void
    {
        $data = implode("\n", array_slice(array_pad($lines,4,''),0,4)) . "\n";
        if (file_put_contents($file . '.log', $data, LOCK_EX) !== strlen($data)) {
            throw new ApiException(500, 'Attachment metadata write failed', 'write_failed');
        }
    }

    private function assertUnfrozen(string $file): void
    {
        if (is_file($file . '.log')) {
            $lines = file($file . '.log', FILE_IGNORE_NEW_LINES);
            if (trim($lines[3] ?? '') !== '' && trim($lines[3]) !== '0') {
                throw new ApiException(403, 'Attachment is frozen', 'attachment_frozen');
            }
        }
    }

    public function delete(string $page, string $name, string $sha256): array
    {
        return $this->changing($page, function () use ($page, $name, $sha256) {
            $file = $this->path($page, $name);
            $this->assertUnfrozen($file);
            if (!is_file($file)) throw new ApiException(404, 'Attachment not found', 'attachment_not_found');
            if (!preg_match('/^[0-9a-f]{64}$/D', $sha256) || !hash_equals(hash_file('sha256', $file), $sha256)) {
                throw new ApiException(409, 'Re-read the attachment before deleting', 'attachment_conflict');
            }
            $age = 1;
            while (file_exists($file . '.' . $age) || is_link($file . '.' . $age)) ++$age;
            if (!rename($file, $file . '.' . $age)) throw new ApiException(500, 'Archive failed', 'write_failed');
            $lines = is_file($file . '.log') ? file($file . '.log', FILE_IGNORE_NEW_LINES) : ['0','0','',''];
            $counts = explode(',', $lines[0] ?? '0');
            $counts = array_pad($counts, $age + 1, '0');
            $counts[$age] = $counts[0]; $counts[0] = '0';
            $lines[0] = implode(',', $counts); $lines[1] = (string)$age;
            $this->writeLog($file, $lines);
            $this->audit->log('attachment_deleted', ['page' => $page, 'filename' => $name, 'actor' => $this->key['label'], 'sha256' => $sha256, 'archive_age' => $age]);
            return ['page' => $page, 'name' => $name, 'archived' => true, 'age' => $age];
        });
    }
}
