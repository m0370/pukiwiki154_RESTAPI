<?php
declare(strict_types=1);

/** CLI とブラウザーから共用するキー管理。生キーは保存しない。 */
final class KeyStore
{
    public function __construct(private string $file) {}
    public function all(): array
    {
        if (!is_file($this->file)) return [];
        $keys = require $this->file;
        if (!is_array($keys)) throw new RuntimeException('キー設定を読み取れません。');
        return $keys;
    }
    private function update(callable $fn): mixed
    {
        $lock = fopen($this->file . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('キー設定をロックできません。');
        try {
            [$keys, $result] = $fn($this->all());
            self::writePhp($this->file, $keys);
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function writePhp(string $file, array $data): void
    {
        $tmp = dirname($file) . '/.' . bin2hex(random_bytes(12)) . '.php';
        $body = "<?php\nreturn " . var_export($data, true) . ";\n";
        if (file_put_contents($tmp, $body, LOCK_EX) !== strlen($body)) {
            @unlink($tmp); throw new RuntimeException('設定ファイルを書き込めません。');
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('設定ファイルを更新できません。'); }
        if (function_exists('opcache_invalidate')) opcache_invalidate($file, true);
    }
    public function create(string $label, string $scope, string $user = '', bool $attachments = false, ?int $expires = null, ?string $ip = null): string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $label) || !in_array($scope, ['read','write'], true)) {
            throw new InvalidArgumentException('キー名は英数字・ドット・ハイフン・下線の64文字以内、権限は read または write を指定してください。');
        }
        if ($attachments && $scope !== 'write') throw new InvalidArgumentException('添付の変更には編集権限が必要です。');
        return $this->update(static function ($keys) use ($label,$scope,$user,$attachments,$expires,$ip) {
            foreach ($keys as $key) if (($key['label'] ?? '') === $label) throw new InvalidArgumentException('同名のキーが既にあります。');
            $raw = 'pkw2_' . bin2hex(random_bytes(24));
            $keys[] = ['label'=>$label,'key_sha256'=>hash('sha256',$raw),'scope'=>$scope,'wiki_user'=>$user,
                'attachments_write'=>$attachments,'expires_at'=>$expires,'ip_allow'=>$ip,'created_at'=>time()];
            return [$keys,$raw];
        });
    }
    public function revoke(string $label): void
    {
        $this->update(static fn($keys) => [array_values(array_filter($keys, static fn($k) => ($k['label'] ?? '') !== $label)),null]);
    }
}
