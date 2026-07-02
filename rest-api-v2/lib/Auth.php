<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';

/**
 * Bearer トークン認証（ファイルベース・2スコープ方式）。
 *
 * キー設定は PHP ファイル（keys.php）に SHA-256 ハッシュで保存する:
 *
 *   <?php
 *   return [
 *       [
 *           'label'      => 'my-editor',   // 監査ログ・#author に記録される名前
 *           'key_sha256' => '64hex...',    // 生キーの SHA-256
 *           'scope'      => 'write',       // 'read' | 'write'（write は read を包含）
 *           'expires_at' => null,          // UNIX time または null（無期限）
 *           'ip_allow'   => null,          // '203.0.113.5' / '198.51.100.0/24' / null
 *       ],
 *   ];
 *
 * 生キーは bin/make-key.php で生成し、一度だけ表示される。平文は保存しない。
 * キーは必ず Authorization: Bearer ヘッダで送る（クエリパラメータ禁止）。
 * @version v2.0
 */
final class Auth
{
    public const SCOPE_READ  = 'read';
    public const SCOPE_WRITE = 'write';

    private ?array $keys = null;

    public function __construct(private string $keys_file) {}

    /**
     * Authorization ヘッダ値を検証し、キーレコードを返す。
     *
     * @param string $authorization  "Bearer xxxx" 形式のヘッダ値
     * @param string $required_scope self::SCOPE_READ | self::SCOPE_WRITE
     * @param string $client_ip      クライアント IP（ip_allow 照合用）
     * @return array{label: string, scope: string}
     * @throws ApiException 401/403
     */
    public function authenticate(
        string $authorization,
        string $required_scope,
        string $client_ip = ''
    ): array {
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $m)) {
            throw new ApiException(401, 'Authorization header missing or malformed', 'missing_token');
        }
        $raw_key  = $m[1];
        $key_hash = hash('sha256', $raw_key);
        $now      = time();

        $matched = null;
        foreach ($this->loadKeys() as $key) {
            if (hash_equals((string)($key['key_sha256'] ?? ''), $key_hash)) {
                $matched = $key;
                break;
            }
        }
        if ($matched === null) {
            throw new ApiException(401, 'Invalid API key', 'invalid_token');
        }

        $expires = $matched['expires_at'] ?? null;
        if ($expires !== null && (int)$expires < $now) {
            throw new ApiException(401, 'API key has expired', 'expired_token');
        }

        $scope = (string)($matched['scope'] ?? self::SCOPE_READ);
        if (!self::scopeSatisfies($scope, $required_scope)) {
            throw new ApiException(403, "Scope '{$required_scope}' is required", 'insufficient_scope');
        }

        $ip_allow = $matched['ip_allow'] ?? null;
        if ($ip_allow !== null && $ip_allow !== '' && $client_ip !== '') {
            if (!self::ipAllowed($client_ip, (string)$ip_allow)) {
                throw new ApiException(403, 'Client IP not permitted', 'ip_not_allowed');
            }
        }

        return [
            'label' => (string)($matched['label'] ?? 'api'),
            'scope' => $scope,
        ];
    }

    /** write は read を包含する */
    public static function scopeSatisfies(string $granted, string $required): bool
    {
        if ($required === self::SCOPE_READ) {
            return $granted === self::SCOPE_READ || $granted === self::SCOPE_WRITE;
        }
        if ($required === self::SCOPE_WRITE) {
            return $granted === self::SCOPE_WRITE;
        }
        return false;
    }

    private function loadKeys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }
        if (!is_file($this->keys_file)) {
            throw new ApiException(
                401,
                'No API keys are configured. Create one with: php rest-api-v2/bin/make-key.php',
                'no_keys_configured'
            );
        }
        $loaded = require $this->keys_file;
        $this->keys = is_array($loaded) ? $loaded : [];
        return $this->keys;
    }

    // -------------------------------------------------------------------------
    // IP 照合（IPv4 完全一致 / IPv4 CIDR。IPv6 は不一致扱い＝fail-safe）
    // -------------------------------------------------------------------------

    public static function ipAllowed(string $client_ip, string $rule): bool
    {
        foreach (array_map('trim', explode(',', $rule)) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                if (self::cidrMatch($client_ip, $entry)) {
                    return true;
                }
            } elseif ($client_ip === $entry) {
                return true;
            }
        }
        return false;
    }

    private static function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits        = (int)$bits;
        $ip_long     = ip2long($ip);
        $subnet_long = ip2long($subnet);
        if ($ip_long === false || $subnet_long === false || $bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        return ($ip_long & $mask) === ($subnet_long & $mask);
    }
}
