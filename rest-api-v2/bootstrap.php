<?php
/**
 * REST API v2 共通ブートストラップ（ファイルのみ方式）
 *
 * 設計: 正本は wiki/*.txt のみ。SQLite等のDBは使わない。
 * 書き込みは必ず PukiWiki 本体の page_write() を経由し、
 * Web UI と同一の副作用（diff/backup/RecentChanges/links更新）を得る。
 *
 * このファイルが設定するグローバル:
 *   $REST_REQUEST         退避済みリクエスト情報（method/uri/query/authorization 等）
 *   $REST_ARGV            退避済み argv（init.php が unset するため）
 *   $REST_DIR             rest-api-v2/ の絶対パス
 *   $REST_DATA_DIR        データディレクトリ（snapshots/audit/locks/keys.php）
 *   $REST_WIKI_DIR        wiki/ の絶対パス
 *   $REST_KEYS_FILE       APIキー設定ファイルのパス
 *   $REST_PROTECTED_PAGES 直接編集禁止ページ
 *   $REST_PKWK_LOADED     PukiWiki 本体をロードできたか
 *
 * PukiWiki 本体のロード方式:
 *   本体 index.php → lib/pukiwiki.php の「Main」直前までを忠実に再現する。
 *   （pukiwiki.ini.php を単体 require する方式は DATA_HOME 未定義で Fatal になる。
 *    また page_write() は diff.php/backup.php/link.php 等に依存するため、
 *    本体の正規初期化を通すことが唯一の安全な統合方法。）
 *
 * 環境変数:
 *   PKWK_ROOT             PukiWiki ルート（省略時は rest-api-v2/ の親を自動検出）
 *   PKWK_REST_DATA        データディレクトリの上書き（標準は DocRoot 外を推奨）
 *   PKWK_API_KEYS         keys.php のパス上書き
 *   PKWK_PROTECTED_PAGES  保護ページの JSON 配列（例 '["FrontPage","MenuBar"]'）
 *   PKWK_REST_ALLOW_DOCROOT_DATA=1
 *                         データディレクトリが DocRoot 内でも起動を許可する
 *                         （data/ への HTTP アクセスが 403 になることを確認済みの場合のみ）
 *
 * 注意: 本番はローカルFS上で運用すること。iCloud/NFS等の同期フォルダでは
 *       flock/rename の保証が失われる。
 *
 * License: GPL v2 or (at your option) any later version（PukiWiki 1.5.4 本体に準拠）
 * @version v2.0
 */

// PukiWiki 本体は strict_types ではないため、ここでは宣言しない。

// PukiWiki 1.5.4 は PHP 8.2+ で Deprecated 警告（dynamic property 等）を出す。
// 警告が JSON レスポンスや MCP の stdout に混入しないよう抑制する。
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
}

// -------------------------------------------------------------------------
// 0. リクエスト情報の退避
//    PukiWiki の init.php は $_SERVER の一部（SCRIPT_NAME, QUERY_STRING, argv）を
//    unset し、$_GET をエンコード変換・書き換えする。API 側のルーティングは
//    必ずこの退避コピーを使うこと。
// -------------------------------------------------------------------------
$REST_REQUEST = [
    'method'        => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'uri'           => $_SERVER['REQUEST_URI'] ?? '/',
    'script_name'   => $_SERVER['SCRIPT_NAME'] ?? '/index.php',
    'path_info'     => $_SERVER['PATH_INFO'] ?? '',
    'remote_addr'   => $_SERVER['REMOTE_ADDR'] ?? '',
    'query'         => $_GET,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '',
];
$REST_ARGV = $GLOBALS['argv'] ?? null;

$REST_DIR      = __DIR__;
$REST_DATA_DIR = getenv('PKWK_REST_DATA') ?: $REST_DIR . '/data';

// -------------------------------------------------------------------------
// 1. PukiWiki 本体の検出とロード
// -------------------------------------------------------------------------
$pkwk_root = getenv('PKWK_ROOT') ?: (realpath($REST_DIR . '/..') ?: dirname($REST_DIR));
$REST_PKWK_LOADED = false;

if (is_file($pkwk_root . '/pukiwiki.ini.php') && is_file($pkwk_root . '/lib/init.php')) {
    // CLI や API 経由でも init.php が破綻しないよう最低限のサーバー変数を補完する
    $_SERVER += [
        'SERVER_NAME'     => 'localhost',
        'SERVER_PORT'     => '80',
        'REQUEST_URI'     => '/index.php',
        'SCRIPT_NAME'     => '/index.php',
        'REQUEST_METHOD'  => 'GET',
        'REMOTE_ADDR'     => '127.0.0.1',
        'HTTP_USER_AGENT' => 'PukiWikiRestApi/2.0',
    ];
    // API のクエリ文字列を PukiWiki にページ名として解釈させない
    unset($_SERVER['QUERY_STRING']);
    $_GET = [];

    // DATA_HOME='' の相対パス方式（本体 index.php と同じ）のため cwd を固定する。
    // 以後 chdir してはならない（PukiWiki 関数は相対パスで wiki/ 等にアクセスする）。
    chdir($pkwk_root);
    define('PKWK_REST_API', 1);
    if (!defined('DATA_HOME')) define('DATA_HOME', '');
    if (!defined('LIB_DIR'))   define('LIB_DIR', 'lib/');

    // lib/pukiwiki.php の「Main」直前までを同じ順序で再現する
    require LIB_DIR . 'func.php';
    require LIB_DIR . 'file.php';
    require LIB_DIR . 'plugin.php';
    require LIB_DIR . 'html.php';
    require LIB_DIR . 'backup.php';
    require LIB_DIR . 'convert_html.php';
    require LIB_DIR . 'make_link.php';
    require LIB_DIR . 'diff.php';
    require LIB_DIR . 'config.php';
    require LIB_DIR . 'link.php';
    require LIB_DIR . 'auth.php';
    require LIB_DIR . 'proxy.php';
    if (!extension_loaded('mbstring')) {
        require LIB_DIR . 'mbstring.php';
    }
    $notify = 0;
    require LIB_DIR . 'init.php';
    if ($notify) require LIB_DIR . 'mail.php';

    $REST_PKWK_LOADED = true;

    // DATA_DIR は通常 'wiki/'（相対）。絶対パスに正規化する。
    $REST_WIKI_DIR = str_starts_with(DATA_DIR, '/')
        ? rtrim(DATA_DIR, '/')
        : $pkwk_root . '/' . rtrim(DATA_DIR, '/');
} else {
    // スタンドアロンモード（PukiWiki なしのユニットテスト用）
    $REST_WIKI_DIR = $REST_DATA_DIR . '/wiki';
    if (!is_dir($REST_WIKI_DIR) && !mkdir($REST_WIKI_DIR, 0755, true) && !is_dir($REST_WIKI_DIR)) {
        throw new \RuntimeException("Cannot create standalone wiki dir: {$REST_WIKI_DIR}");
    }
}

// -------------------------------------------------------------------------
// 2. データディレクトリ（Web 非公開領域）
// -------------------------------------------------------------------------
foreach (['/snapshots', '/audit', '/locks'] as $sub) {
    $d = $REST_DATA_DIR . $sub;
    if (!is_dir($d) && !mkdir($d, 0750, true) && !is_dir($d)) {
        throw new \RuntimeException("Cannot create directory: {$d}");
    }
}

// 防御の多層化: data/ 直下に deny の .htaccess を自動配置する
// （rest-api-v2/.htaccess の RewriteRule が効かない環境へのフォールバック）
$rest_data_ht = $REST_DATA_DIR . '/.htaccess';
if (!is_file($rest_data_ht)) {
    @file_put_contents($rest_data_ht, implode("\n", [
        '# REST API data directory - never serve over HTTP',
        '<IfModule mod_authz_core.c>',
        '    Require all denied',
        '</IfModule>',
        '<IfModule !mod_authz_core.c>',
        '    Order deny,allow',
        '    Deny from all',
        '</IfModule>',
        '',
    ]));
}

/**
 * データディレクトリが Web に露出し得るか（Web SAPI かつ DocRoot 配下なら true）。
 *
 * .htaccess による deny が有効かどうか（AllowOverride 設定）は外部から検出できないため、
 * サーバー種別（Apache/nginx）では判定しない。Web SAPI で DocRoot 内なら一律 true。
 * 判定は PHP_SAPI === 'cli' の完全一致であること（php -S は 'cli-server' であり、
 * 前方一致にすると組み込みサーバーでガードが無効になってしまう）。
 */
function rest_data_dir_is_exposed(string $data_dir, array $server, string $sapi): bool
{
    if ($sapi === 'cli') {
        return false; // CLI（MCP・make-key・テスト）は HTTP 配信されない
    }
    $doc_root = (string)($server['DOCUMENT_ROOT'] ?? '');
    if ($doc_root === '') {
        return false;
    }
    $data_real = realpath($data_dir);
    $root_real = realpath($doc_root);
    if ($data_real === false || $root_real === false) {
        return false;
    }
    return $data_real === $root_real
        || str_starts_with($data_real . '/', rtrim($root_real, '/') . '/');
}

// DocRoot 内の data/ は .htaccess が効かない環境（nginx / php -S / AllowOverride None）で
// keys.php・監査ログ・スナップショットが丸見えになるため、明示許可がない限り起動を拒否する。
// この時点では Response/ApiException が未読込（require はセクション4）なので、クラス非依存で返す。
if (rest_data_dir_is_exposed($REST_DATA_DIR, $_SERVER, PHP_SAPI)
    && getenv('PKWK_REST_ALLOW_DOCROOT_DATA') !== '1') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => [
        'status'  => 500,
        'code'    => 'insecure_data_dir',
        'message' => 'REST data directory is inside the web document root. '
            . 'Move it outside with PKWK_REST_DATA (recommended), or set '
            . 'PKWK_REST_ALLOW_DOCROOT_DATA=1 only after verifying that HTTP access '
            . 'to data/ is denied (returns 403).',
    ]], JSON_UNESCAPED_SLASHES);
    exit;
}

// -------------------------------------------------------------------------
// 3. API キー設定と保護ページ
// -------------------------------------------------------------------------
$REST_KEYS_FILE = getenv('PKWK_API_KEYS') ?: $REST_DATA_DIR . '/keys.php';

$REST_PROTECTED_PAGES = ['FrontPage', 'MenuBar'];
if (($env = getenv('PKWK_PROTECTED_PAGES')) !== false && $env !== '') {
    $decoded = json_decode($env, true);
    if (is_array($decoded)) {
        $REST_PROTECTED_PAGES = array_values(array_filter($decoded, 'is_string'));
    }
}

// -------------------------------------------------------------------------
// 4. REST API クラス読み込み
// -------------------------------------------------------------------------
require_once $REST_DIR . '/lib/ApiException.php';
require_once $REST_DIR . '/lib/Response.php';
require_once $REST_DIR . '/lib/Router.php';
require_once $REST_DIR . '/lib/Auth.php';
require_once $REST_DIR . '/lib/Audit.php';
require_once $REST_DIR . '/lib/SnapshotStore.php';
require_once $REST_DIR . '/lib/PageStore.php';

// -------------------------------------------------------------------------
// 5. コンポーネント初期化
// -------------------------------------------------------------------------
$REST_AUDIT     = new Audit($REST_DATA_DIR . '/audit');
$REST_SNAPSHOTS = new SnapshotStore($REST_DATA_DIR . '/snapshots');
$REST_PAGES     = new PageStore(
    $REST_WIKI_DIR,
    $REST_DATA_DIR . '/locks',
    $REST_PROTECTED_PAGES,
    $REST_SNAPSHOTS,
    $REST_AUDIT
);
