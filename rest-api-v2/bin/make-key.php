#!/usr/bin/env php
<?php
/**
 * API キー管理 CLI。
 *
 * PukiWiki 本体には依存しない（bootstrap を読まない）。
 * キーの生値は生成時に一度だけ表示し、keys.php には SHA-256 ハッシュのみ保存する。
 *
 * 使い方:
 *   php make-key.php --label my-editor --scope write
 *   php make-key.php --label ai-reader --scope read --expires 2027-01-01 --ip 203.0.113.5
 *   php make-key.php --list
 *   php make-key.php --revoke my-editor
 *
 * 環境変数:
 *   PKWK_API_KEYS   keys.php のパス（最優先）
 *   PKWK_REST_DATA  データディレクトリ（bootstrap と同じ値を設定すれば keys.php の
 *                   場所が Web 側と一致する。省略時: rest-api-v2/data）
 *
 * License: GPL v2 or (at your option) any later version（PukiWiki 1.5.4 本体に準拠）
 * @version v2.0
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$keys_file = getenv('PKWK_API_KEYS')
    ?: (getenv('PKWK_REST_DATA') ?: dirname(__DIR__) . '/data') . '/keys.php';

// ---- 引数解析 -------------------------------------------------------------
$args = array_slice($argv, 1);
$opts = ['label' => null, 'scope' => null, 'expires' => null, 'ip' => null,
         'list' => false, 'revoke' => null];
for ($i = 0; $i < count($args); $i++) {
    switch ($args[$i]) {
        case '--label':   $opts['label']  = $args[++$i] ?? null; break;
        case '--scope':   $opts['scope']  = $args[++$i] ?? null; break;
        case '--expires': $opts['expires'] = $args[++$i] ?? null; break;
        case '--ip':      $opts['ip']     = $args[++$i] ?? null; break;
        case '--list':    $opts['list']   = true; break;
        case '--revoke':  $opts['revoke'] = $args[++$i] ?? null; break;
        case '--help': case '-h':
            usage(); exit(0);
        default:
            fwrite(STDERR, "Unknown option: {$args[$i]}\n");
            usage(); exit(1);
    }
}

function usage(): void
{
    echo <<<TXT
使い方:
  キー作成: php make-key.php --label <名前> --scope <read|write> [--expires YYYY-MM-DD] [--ip <IP|CIDR>]
  一覧    : php make-key.php --list
  失効    : php make-key.php --revoke <名前>

TXT;
}

// ---- keys.php の読み書き ---------------------------------------------------
function load_keys(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $loaded = require $file;
    return is_array($loaded) ? $loaded : [];
}

function save_keys(string $file, array $keys): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create directory: {$dir}\n");
        exit(1);
    }
    $body = "<?php\n"
        . "// PukiWiki REST API v2 の API キー設定（bin/make-key.php が管理）。\n"
        . "// key_sha256 は生キーの SHA-256。生キーはここには保存されない。\n"
        . "return " . var_export(array_values($keys), true) . ";\n";
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $body, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        fwrite(STDERR, "Cannot write: {$file}\n");
        exit(1);
    }
    @chmod($file, 0640);
}

$keys = load_keys($keys_file);

// ---- --list ----------------------------------------------------------------
if ($opts['list']) {
    if (empty($keys)) {
        echo "登録済みのキーはありません。（{$keys_file}）\n";
        exit(0);
    }
    echo "keys.php: {$keys_file}\n\n";
    printf("%-20s %-6s %-20s %-20s %s\n", 'LABEL', 'SCOPE', 'EXPIRES', 'IP_ALLOW', 'SHA256(先頭12)');
    foreach ($keys as $k) {
        printf("%-20s %-6s %-20s %-20s %s\n",
            (string)($k['label'] ?? '?'),
            (string)($k['scope'] ?? '?'),
            isset($k['expires_at']) && $k['expires_at'] !== null
                ? date('Y-m-d H:i', (int)$k['expires_at']) : '(none)',
            (string)($k['ip_allow'] ?? '(none)'),
            substr((string)($k['key_sha256'] ?? ''), 0, 12) . '…'
        );
    }
    exit(0);
}

// ---- --revoke ----------------------------------------------------------------
if ($opts['revoke'] !== null) {
    $before = count($keys);
    $keys = array_filter($keys, fn($k) => ($k['label'] ?? '') !== $opts['revoke']);
    if (count($keys) === $before) {
        fwrite(STDERR, "ラベル '{$opts['revoke']}' のキーは見つかりませんでした。\n");
        exit(1);
    }
    save_keys($keys_file, $keys);
    echo "キー '{$opts['revoke']}' を失効（削除）しました。\n";
    exit(0);
}

// ---- キー作成 ----------------------------------------------------------------
if ($opts['label'] === null || $opts['scope'] === null) {
    usage();
    exit(1);
}
if (!in_array($opts['scope'], ['read', 'write'], true)) {
    fwrite(STDERR, "--scope は read か write を指定してください。\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9_\-\.]{1,64}$/', $opts['label'])) {
    fwrite(STDERR, "--label は英数字・ハイフン・アンダースコア・ドット（64字以内）で指定してください。\n");
    exit(1);
}
foreach ($keys as $k) {
    if (($k['label'] ?? '') === $opts['label']) {
        fwrite(STDERR, "ラベル '{$opts['label']}' は既に存在します。--revoke してから作り直してください。\n");
        exit(1);
    }
}

$expires_at = null;
if ($opts['expires'] !== null) {
    $t = strtotime($opts['expires'] . ' 23:59:59');
    if ($t === false) {
        fwrite(STDERR, "--expires の日付を解釈できません: {$opts['expires']}\n");
        exit(1);
    }
    $expires_at = $t;
}

$raw_key = 'pkw2_' . bin2hex(random_bytes(24)); // 192bit エントロピー

$keys[] = [
    'label'      => $opts['label'],
    'key_sha256' => hash('sha256', $raw_key),
    'scope'      => $opts['scope'],
    'expires_at' => $expires_at,
    'ip_allow'   => $opts['ip'],
    'created_at' => time(),
];
save_keys($keys_file, $keys);

echo "APIキーを作成しました。\n\n";
echo "  label  : {$opts['label']}\n";
echo "  scope  : {$opts['scope']}\n";
echo "  expires: " . ($expires_at ? date('Y-m-d H:i', $expires_at) : '(無期限)') . "\n";
echo "  ip     : " . ($opts['ip'] ?? '(制限なし)') . "\n\n";
echo "┌─────────────────────────────────────────────────────────┐\n";
echo "  {$raw_key}\n";
echo "└─────────────────────────────────────────────────────────┘\n";
echo "⚠ このキーは今回しか表示されません。安全な場所に保管してください。\n";
echo "  使い方: curl -H \"Authorization: Bearer {$raw_key}\" ...\n";
