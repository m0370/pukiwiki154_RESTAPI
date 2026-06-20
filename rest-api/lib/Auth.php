<?php
declare(strict_types=1);

require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/ApiException.php';

/**
 * Bearer トークン認証とスコープ検査。
 *
 * Authorization: Bearer {raw_key} ヘッダからキーを取り出し、
 * api_keys テーブルの SHA-256 ハッシュと照合する。
 * スコープは space-separated で "page:read draft:create" のように格納。
 * @version v0.1
 */
final class Auth
{
    public function __construct(private Ledger $ledger) {}

    /**
     * リクエストヘッダからキーを検証し、必要スコープがあれば API キーレコードを返す。
     * 失敗は ApiException を投げる（呼び出し元はキャッチして 401/403 を返す）。
     *
     * @param string[] $headers  サーバー変数（$_SERVER）またはテスト用の連想配列
     * @param string $required_scope  "page:read" など。空文字ならスコープ不問。
     * @param string $client_ip  クライアント IP（IP 制限の照合に使う）
     */
    public function authenticate(
        array $headers,
        string $required_scope,
        string $client_ip = ''
    ): array {
        $raw_key = $this->extractBearer($headers);
        if ($raw_key === null) {
            throw new ApiException(401, 'Authorization header missing or malformed', 'missing_token');
        }

        $key = $this->ledger->authenticateKey($raw_key, time());
        if ($key === null) {
            throw new ApiException(401, 'Invalid or expired API key', 'invalid_token');
        }

        // スコープ確認
        if ($required_scope !== '') {
            $scopes = array_filter(explode(' ', (string)$key['scopes']));
            if (!in_array($required_scope, $scopes, true) && !in_array('*', $scopes, true)) {
                throw new ApiException(
                    403,
                    "Scope '{$required_scope}' is required",
                    'insufficient_scope'
                );
            }
        }

        // IP 制限（ip_allow が設定されている場合のみ）
        if ($key['ip_allow'] !== null && $client_ip !== '') {
            if (!self::ipAllowed($client_ip, (string)$key['ip_allow'])) {
                throw new ApiException(403, 'Client IP not permitted', 'ip_not_allowed');
            }
        }

        return $key;
    }

    /** Authorization: Bearer ヘッダから raw key を取り出す */
    private function extractBearer(array $headers): ?string
    {
        // $_SERVER['HTTP_AUTHORIZATION'] または直接渡された 'Authorization' キーに対応
        $value = $headers['HTTP_AUTHORIZATION']
            ?? $headers['Authorization']
            ?? $headers['AUTHORIZATION']
            ?? null;

        if ($value === null) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(\S+)$/i', (string)$value, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * IP がルールに一致するか確認。
     * 現実装: 完全一致 または CIDR /32 相当のプレフィクス一致。
     * 将来は cidrMatch() に拡張する。
     */
    private static function ipAllowed(string $client_ip, string $rule): bool
    {
        // CIDR 表記（e.g. "192.168.1.0/24"）
        if (str_contains($rule, '/')) {
            return self::cidrMatch($client_ip, $rule);
        }
        return $client_ip === $rule;
    }

    private static function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int)$bits;
        $ip_long     = ip2long($ip);
        $subnet_long = ip2long($subnet);
        if ($ip_long === false || $subnet_long === false) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
        return ($ip_long & $mask) === ($subnet_long & $mask);
    }
}
