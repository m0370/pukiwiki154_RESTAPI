<?php
/**
 * Phase 1 検証テスト: 読み取り専用 API＋API キー
 *
 * 確認すること:
 *  1. Auth: Bearer トークン照合・スコープ確認・IP 制限
 *  2. PageReader: ページ読み取り・自己修復連携・存在しないページは 404
 *  3. Router: パスマッチング・メソッド照合
 *  4. Response: ステータスコード・エラーレスポンス形式
 *  5. 総合: GET /pages/{page} ハンドラを通して正しい JSON が返る
 *
 * 実行: php rest-api/test/phase1_test.php
 * @version v0.1
 */
declare(strict_types=1);

// -------------------------------------------------------------------------
// テスト環境セットアップ: 独立した tmp ディレクトリを使う
// -------------------------------------------------------------------------
$test_dir  = sys_get_temp_dir() . '/pkwk_phase1_' . getmypid();
$wiki_dir  = $test_dir . '/wiki';
$db_dir    = $test_dir . '/db';
$blob_dir  = $test_dir . '/blobs';

foreach ([$wiki_dir, $db_dir, $blob_dir] as $d) {
    mkdir($d, 0755, true);
}

// bootstrap.php を使わずに手動でコンポーネントを初期化
$REST_DIR = dirname(__DIR__);
require_once $REST_DIR . '/lib/AtomicWriter.php';
require_once $REST_DIR . '/lib/RevisionStore.php';
require_once $REST_DIR . '/lib/Ledger.php';
require_once $REST_DIR . '/lib/Reconciler.php';
require_once $REST_DIR . '/lib/ApiException.php';
require_once $REST_DIR . '/lib/Auth.php';
require_once $REST_DIR . '/lib/PageReader.php';
require_once $REST_DIR . '/lib/Response.php';
require_once $REST_DIR . '/lib/Router.php';

$schema   = $REST_DIR . '/schema/init.sql';
$db_path  = $db_dir . '/ledger.sqlite';
$ledger   = Ledger::open($db_path, $schema);
$store    = new RevisionStore($blob_dir);
$rec      = new Reconciler($ledger, $store, $wiki_dir);
$auth     = new Auth($ledger);
$reader   = new PageReader($rec, $ledger, $wiki_dir);

// テスト用 wiki ファイルを作成
$fp_content = "= FrontPage =\nここは PukiWiki のトップページです。\n";
$mb_content = "- [[FrontPage]]\n- [[Help]]\n";

function write_wiki(string $wiki_dir, string $page, string $content): void
{
    $encoded = strtoupper(bin2hex($page));
    file_put_contents($wiki_dir . '/' . $encoded . '.txt', $content);
}

write_wiki($wiki_dir, 'FrontPage', $fp_content);
write_wiki($wiki_dir, 'MenuBar', $mb_content);

// APIキー登録
$now     = time();
$key_rw  = 'test-read-key-' . bin2hex(random_bytes(4));
$key_bad = 'test-no-scope-' . bin2hex(random_bytes(4));
$key_ip  = 'test-ip-key-'   . bin2hex(random_bytes(4));
$key_cidr = 'test-cidr-key-' . bin2hex(random_bytes(4));

$ledger->registerApiKey($key_rw,   'Read key',     'page:read', $now);
$ledger->registerApiKey($key_bad,  'No scope key', 'draft:create', $now); // page:read がない
$ledger->registerApiKey($key_ip,   'IP-locked key','page:read', $now, null, '127.0.0.1');
$ledger->registerApiKey($key_cidr, 'CIDR key',     'page:read', $now, null, '192.168.1.0/24');

// -------------------------------------------------------------------------
// 簡易テストハーネス
// -------------------------------------------------------------------------
$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        echo "  \e[32m✓\e[0m {$label}\n";
        $passed++;
    } else {
        echo "  \e[31m✗\e[0m {$label}\n";
        $failed++;
    }
}

function section(string $name): void
{
    echo "\n\e[1m{$name}\e[0m\n";
}

function auth_headers(string $raw_key): array
{
    return ['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"];
}

// -------------------------------------------------------------------------
// 1. Auth
// -------------------------------------------------------------------------
section('1. Auth');

// 正常: 正しいキー・正しいスコープ
$key_record = $auth->authenticate(auth_headers($key_rw), 'page:read', '1.2.3.4');
ok($key_record !== null, '正しいキーで authenticate() が成功する');
ok($key_record['scopes'] === 'page:read', 'スコープが正しい');

// 異常: 不正なキー
try {
    $auth->authenticate(auth_headers('wrong-key'), 'page:read', '');
    ok(false, '不正なキーで例外が発生するはず');
} catch (ApiException $e) {
    ok($e->status === 401, '不正なキーは 401 を投げる');
}

// 異常: Bearer なし
try {
    $auth->authenticate([], 'page:read', '');
    ok(false, 'ヘッダなしで例外が発生するはず');
} catch (ApiException $e) {
    ok($e->status === 401, 'ヘッダなしは 401 を投げる');
}

// 異常: スコープ不足
try {
    $auth->authenticate(auth_headers($key_bad), 'page:read', '');
    ok(false, 'スコープ不足で例外が発生するはず');
} catch (ApiException $e) {
    ok($e->status === 403, 'スコープ不足は 403 を投げる');
    ok($e->error_code === 'insufficient_scope', 'error_code が insufficient_scope');
}

// IP 制限: 一致
$key_ip_rec = $auth->authenticate(auth_headers($key_ip), 'page:read', '127.0.0.1');
ok($key_ip_rec !== null, 'IP 一致でアクセス許可');

// IP 制限: 不一致
try {
    $auth->authenticate(auth_headers($key_ip), 'page:read', '10.0.0.1');
    ok(false, 'IP 不一致で例外が発生するはず');
} catch (ApiException $e) {
    ok($e->status === 403, 'IP 不一致は 403 を投げる');
}

// CIDR: 一致
$cidr_rec = $auth->authenticate(auth_headers($key_cidr), 'page:read', '192.168.1.50');
ok($cidr_rec !== null, 'CIDR 範囲内でアクセス許可');

// CIDR: 範囲外
try {
    $auth->authenticate(auth_headers($key_cidr), 'page:read', '192.168.2.1');
    ok(false, 'CIDR 範囲外で例外が発生するはず');
} catch (ApiException $e) {
    ok($e->status === 403, 'CIDR 範囲外は 403 を投げる');
}

// 期限切れキー
$expired_key = 'expired-' . bin2hex(random_bytes(4));
$ledger->registerApiKey($expired_key, 'Expired', 'page:read', $now, $now - 1);
try {
    $auth->authenticate(auth_headers($expired_key), 'page:read', '');
    ok(false, '期限切れキーで例外が発生するはず');
} catch (ApiException $e) {
    ok($e->status === 401, '期限切れキーは 401 を投げる');
}

// -------------------------------------------------------------------------
// 2. PageReader
// -------------------------------------------------------------------------
section('2. PageReader');

// 正常: FrontPage を読む
$data = $reader->read('FrontPage');
ok($data['page'] === 'FrontPage', 'read() がページ名を返す');
ok($data['sha1'] === sha1($fp_content), 'read() が正しい sha1 を返す');
ok($data['content'] === $fp_content, 'read() が正しい内容を返す');
ok(isset($data['rev']), 'read() が rev を返す');
ok(isset($data['updated_at']), 'read() が updated_at を返す');
ok(array_key_exists('is_frozen', $data), 'read() が is_frozen キーを持つ');
ok(array_key_exists('is_editable', $data), 'read() が is_editable キーを持つ');
ok($data['status'] === 'active', 'read() が status=active を返す');
ok($data['size'] === strlen($fp_content), 'read() が正しい size を返す');

// 存在しないページ
try {
    $reader->read('NonExistentPage999');
    ok(false, '存在しないページで例外が発生するはず');
} catch (ApiException $e) {
    ok($e->status === 404, '存在しないページは 404 を投げる');
}

// 外部編集後の自己修復
$new_content = $fp_content . "追記行\n";
write_wiki($wiki_dir, 'FrontPage', $new_content);
// まだ DB は古い sha1 のまま
$before_sha1 = $ledger->getPage('FrontPage')['content_sha1'];
// read() が自己修復を行う
$data2 = $reader->read('FrontPage');
$after_sha1 = $ledger->getPage('FrontPage')['content_sha1'];
ok($data2['sha1'] === sha1($new_content), '外部編集後に read() が新しい内容を返す');
ok($after_sha1 === sha1($new_content), '外部編集後に DB の sha1 が追従する');
ok($after_sha1 !== $before_sha1, '自己修復で sha1 が更新される');

// listPages
$rec->fullScan(); // DB を wiki ファイルと同期
$pages = $reader->listPages();
ok(count($pages) >= 2, 'listPages() が複数ページを返す');
$names = array_column($pages, 'name');
ok(in_array('FrontPage', $names, true), 'listPages() に FrontPage が含まれる');
ok(in_array('MenuBar', $names, true), 'listPages() に MenuBar が含まれる');

// -------------------------------------------------------------------------
// 3. Router
// -------------------------------------------------------------------------
section('3. Router');

$router = new Router();
$router->get('/pages', fn($v) => 'list');
$router->get('/pages/{page}', fn($v) => 'page:' . $v['page']);
$router->get('/pages/{page}/drafts', fn($v) => 'drafts:' . $v['page']);

ok($router->dispatch('GET', '/pages') === 'list', 'GET /pages がマッチする');
ok($router->dispatch('GET', '/pages/FrontPage') === 'page:FrontPage', 'GET /pages/{page} がマッチする');
ok($router->dispatch('GET', '/pages/PukiWiki%2FManual') === 'page:PukiWiki/Manual', 'URL エンコードされたパスがデコードされる');
ok($router->dispatch('GET', '/pages/FrontPage/drafts') === 'drafts:FrontPage', 'サブパスがマッチする');

// メソッド不一致 → 405
try {
    $router->dispatch('POST', '/pages');
    ok(false, 'POST /pages（定義なし）で例外が発生するはず');
} catch (ApiException $e) {
    ok($e->status === 405, 'メソッド不一致は 405');
}

// パス不一致 → 404
try {
    $router->dispatch('GET', '/unknown/path');
    ok(false, '未定義パスで例外が発生するはず');
} catch (ApiException $e) {
    ok($e->status === 404, 'パス不一致は 404');
}

// -------------------------------------------------------------------------
// 4. Response
// -------------------------------------------------------------------------
section('4. Response');

$resp_ok = Response::ok(['page' => 'FrontPage', 'rev' => 1]);
ok($resp_ok->getStatus() === 200, 'ok() が 200 を返す');
ok($resp_ok->toArray()['page'] === 'FrontPage', 'ok() が body を正しく保持する');

$resp_err = Response::error(404, 'Not found', 'not_found');
ok($resp_err->getStatus() === 404, 'error() が 404 を返す');
ok($resp_err->toArray()['error']['code'] === 'not_found', 'error() が error.code を設定する');
ok($resp_err->toArray()['error']['message'] === 'Not found', 'error() が error.message を設定する');

$exc = new ApiException(403, 'Forbidden', 'insufficient_scope');
$resp_exc = Response::fromException($exc);
ok($resp_exc->getStatus() === 403, 'fromException() が ApiException のステータスを使う');
ok($resp_exc->toArray()['error']['code'] === 'insufficient_scope', 'fromException() が error_code を使う');

// -------------------------------------------------------------------------
// 5. 総合: GET /pages/{page} ハンドラのシミュレーション
// -------------------------------------------------------------------------
section('5. 総合ハンドラシミュレーション');

// ハンドラを直接実行してレスポンスを確認（HTTP サーバー不要）
function simulate_get_page(
    string $raw_key,
    string $page,
    string $client_ip,
    Auth $auth,
    PageReader $reader
): Response {
    try {
        $auth->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"], 'page:read', $client_ip);
        return Response::ok($reader->read($page));
    } catch (\Throwable $e) {
        return Response::fromException($e);
    }
}

// 正常取得
$resp = simulate_get_page($key_rw, 'MenuBar', '1.2.3.4', $auth, $reader);
ok($resp->getStatus() === 200, 'GET /pages/MenuBar が 200 を返す');
ok($resp->toArray()['page'] === 'MenuBar', 'レスポンスにページ名が含まれる');
ok(isset($resp->toArray()['sha1']), 'レスポンスに sha1 が含まれる');
ok(isset($resp->toArray()['content']), 'レスポンスに content が含まれる');

// 認証なし → 401
$resp_noauth = simulate_get_page('bad-key', 'FrontPage', '1.2.3.4', $auth, $reader);
ok($resp_noauth->getStatus() === 401, '不正なキーは 401');

// スコープ不足 → 403
$resp_403 = simulate_get_page($key_bad, 'FrontPage', '1.2.3.4', $auth, $reader);
ok($resp_403->getStatus() === 403, 'スコープ不足は 403');

// 存在しないページ → 404
$resp_404 = simulate_get_page($key_rw, 'NoSuchPage', '1.2.3.4', $auth, $reader);
ok($resp_404->getStatus() === 404, '存在しないページは 404');
ok($resp_404->toArray()['error']['code'] === 'not_found', 'エラーコードが not_found');

// JSON 形式の確認
$json = $resp->toJson();
ok(json_validate($json), 'レスポンスが valid JSON');
$decoded = json_decode($json, true);
ok($decoded['page'] === 'MenuBar', 'JSON デコード後にページ名が正しい');

// -------------------------------------------------------------------------
// 結果サマリ
// -------------------------------------------------------------------------
echo "\n";
echo str_repeat('-', 50) . "\n";
$total = $passed + $failed;
echo "\e[1mPassed: {$passed}/{$total}\e[0m";
if ($failed > 0) {
    echo "  \e[31mFailed: {$failed}\e[0m";
}
echo "\n";

if ($failed > 0) {
    exit(1);
}
