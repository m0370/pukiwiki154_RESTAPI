<?php
/**
 * ユニットテスト（スタンドアロン — PukiWiki 本体なしで実行可能）。
 *
 * 実行: php rest-api-v2/test/unit_test.php
 *
 * データは一時ディレクトリに書き、リポジトリの data/ を汚さない。
 * @version v2.0
 */
declare(strict_types=1);

require_once __DIR__ . '/testlib.php';

// ---- テスト用データディレクトリ（毎回まっさら）------------------------------
$tmp = sys_get_temp_dir() . '/pkwk-rest-v2-unit-' . bin2hex(random_bytes(4));
mkdir($tmp, 0755, true);
putenv("PKWK_REST_DATA={$tmp}");
// 親に pukiwiki.ini.php が無い場所を PKWK_ROOT に指定してスタンドアロンを強制
putenv("PKWK_ROOT={$tmp}");

require_once __DIR__ . '/../bootstrap.php';

ok($REST_PKWK_LOADED === false, 'スタンドアロンモードで起動');
ok(is_dir($REST_WIKI_DIR), 'wiki ディレクトリ作成');
ok(is_file($REST_DATA_DIR . '/.htaccess'), 'data/.htaccess が自動生成される');

// =========================================================================
section('Router: パターンマッチ');
// =========================================================================
$m = Router::match('/pages/{page...}', '/pages/親/子/孫');
ok($m !== null && $m['page'] === '親/子/孫', '{page...} が階層ページ名にマッチ');

$m = Router::match('/pages/{page...}/revisions', '/pages/親/子/revisions');
ok($m !== null && $m['page'] === '親/子', '{page...}/revisions で greedy が正しく後退');

$m = Router::match('/pages/{page...}/revisions/{rev}', '/pages/A/B/revisions/123_' . str_repeat('a', 40));
ok($m !== null && $m['page'] === 'A/B' && str_starts_with($m['rev'], '123_'), 'revisions/{rev} のキャプチャ');

ok(Router::match('/pages', '/pages/Foo') === null, '完全一致パターンは部分パスに非マッチ');

$router = new Router();
$router->get('/pages/{page...}/revisions', fn($v) => 'revs:' . $v['page']);
$router->get('/pages/{page...}', fn($v) => 'page:' . $v['page']);
ok($router->dispatch('GET', '/pages/A/B/revisions') === 'revs:A/B', 'revisions ルートが優先マッチ');
ok($router->dispatch('GET', '/pages/A/B') === 'page:A/B', 'ページルートにフォールバック');
expect_api_error(fn() => $router->dispatch('GET', '/nothing'), 404, '未定義パスは 404');
expect_api_error(fn() => $router->dispatch('PUT', '/pages/A/B/revisions'), 405, 'メソッド不一致は 405');

// =========================================================================
section('Auth: キー認証と2スコープ');
// =========================================================================
$raw_read  = 'pkw2_' . bin2hex(random_bytes(24));
$raw_write = 'pkw2_' . bin2hex(random_bytes(24));
$raw_dead  = 'pkw2_' . bin2hex(random_bytes(24));
$raw_ipkey = 'pkw2_' . bin2hex(random_bytes(24));

$keys_file = $tmp . '/keys.php';
file_put_contents($keys_file, '<?php return ' . var_export([
    ['label' => 'reader', 'key_sha256' => hash('sha256', $raw_read),  'scope' => 'read',  'expires_at' => null, 'ip_allow' => null],
    ['label' => 'editor', 'key_sha256' => hash('sha256', $raw_write), 'scope' => 'write', 'expires_at' => null, 'ip_allow' => null],
    ['label' => 'dead',   'key_sha256' => hash('sha256', $raw_dead),  'scope' => 'write', 'expires_at' => time() - 10, 'ip_allow' => null],
    ['label' => 'ipkey',  'key_sha256' => hash('sha256', $raw_ipkey), 'scope' => 'read',  'expires_at' => null, 'ip_allow' => '192.168.1.0/24'],
], true) . ';');

$auth = new Auth($keys_file);

$k = $auth->authenticate("Bearer {$raw_read}", Auth::SCOPE_READ);
ok($k['label'] === 'reader', 'read キーで read 認証成功');
expect_api_error(fn() => $auth->authenticate("Bearer {$raw_read}", Auth::SCOPE_WRITE), 403, 'read キーで write は 403');

$k = $auth->authenticate("Bearer {$raw_write}", Auth::SCOPE_WRITE);
ok($k['label'] === 'editor', 'write キーで write 認証成功');
$k = $auth->authenticate("Bearer {$raw_write}", Auth::SCOPE_READ);
ok($k['label'] === 'editor', 'write キーは read も包含');

expect_api_error(fn() => $auth->authenticate('', Auth::SCOPE_READ), 401, 'ヘッダなしは 401');
expect_api_error(fn() => $auth->authenticate('Bearer wrongkey', Auth::SCOPE_READ), 401, '不正キーは 401');
expect_api_error(fn() => $auth->authenticate("Bearer {$raw_dead}", Auth::SCOPE_READ), 401, '期限切れキーは 401');

$k = $auth->authenticate("Bearer {$raw_ipkey}", Auth::SCOPE_READ, '192.168.1.42');
ok($k['label'] === 'ipkey', 'CIDR 内の IP は許可');
expect_api_error(fn() => $auth->authenticate("Bearer {$raw_ipkey}", Auth::SCOPE_READ, '10.0.0.1'), 403, 'CIDR 外の IP は 403');
expect_api_error(fn() => $auth->authenticate("Bearer {$raw_ipkey}", Auth::SCOPE_READ, '::1'), 403, 'IPv6 は fail-safe で 403');

$auth_nokeys = new Auth($tmp . '/no-such-keys.php');
expect_api_error(fn() => $auth_nokeys->authenticate("Bearer {$raw_read}", Auth::SCOPE_READ), 401, 'keys.php 未作成は 401');

// =========================================================================
section('PageStore: 書き込みと CAS（スタンドアロン）');
// =========================================================================
/** @var PageStore $REST_PAGES */
$EMPTY = PageStore::EMPTY_SHA1;

// 新規作成
$r = $REST_PAGES->write('テスト/ページ1', "*見出し\n本文です。\n", $EMPTY, 'unit-test');
ok($r['is_new'] === true && $r['changed'] === true, '新規ページ作成');
ok($r['new_sha1'] === sha1("*見出し\n本文です。\n"), 'スタンドアロンでは new_sha1 = 送信本文の sha1');

// 読み取り
$d = $REST_PAGES->read('テスト/ページ1');
ok($d['sha1'] === $r['new_sha1'], 'read の sha1 が write の new_sha1 と一致');
ok($d['content'] === "*見出し\n本文です。\n", '本文が一致');
ok($d['is_frozen'] === null && $d['is_editable'] === null, 'スタンドアロンでは freeze/editable は null');

// 返却 sha1 を base にした連続更新
$r2 = $REST_PAGES->write('テスト/ページ1', "*見出し\n更新しました。\n", $r['new_sha1'], 'unit-test');
ok($r2['changed'] === true && $r2['is_new'] === false, '返却 sha1 を base にした更新が成功');

// 古い base での競合
expect_api_error(
    fn() => $REST_PAGES->write('テスト/ページ1', "古い版からの上書き\n", $r['new_sha1'], 'unit-test'),
    409, '古い base_sha1 は 409'
);

// 新規なのに非 EMPTY_SHA1
expect_api_error(
    fn() => $REST_PAGES->write('存在しないページ', "x\n", sha1('dummy'), 'unit-test'),
    409, '存在しないページに非 EMPTY base は 409'
);

// 空本文
expect_api_error(
    fn() => $REST_PAGES->write('テスト/ページ1', "  \n", $r2['new_sha1'], 'unit-test'),
    400, '空本文は 400'
);

// base_sha1 形式不正
expect_api_error(
    fn() => $REST_PAGES->write('テスト/ページ1', "x\n", 'not-a-sha1', 'unit-test'),
    400, '不正な base_sha1 形式は 400'
);

// 保護ページ
expect_api_error(
    fn() => $REST_PAGES->write('FrontPage', "乗っ取り\n", $EMPTY, 'unit-test'),
    403, '保護ページ FrontPage は 403'
);
expect_api_error(
    fn() => $REST_PAGES->write(':config/plugin', "x\n", $EMPTY, 'unit-test'),
    403, "システムページ（':' 始まり）は 403"
);

// ページ名検証
expect_api_error(fn() => $REST_PAGES->read(''), 400, '空ページ名は 400');
expect_api_error(fn() => $REST_PAGES->read(" 前後空白 "), 400, '前後空白のページ名は 400');
expect_api_error(fn() => $REST_PAGES->read("制御\x01文字"), 400, '制御文字入りページ名は 400');
expect_api_error(fn() => $REST_PAGES->read(str_repeat('あ', 100)), 400, '長すぎるページ名は 400');
expect_api_error(fn() => $REST_PAGES->read('存在しないページX'), 404, '未存在ページは 404');

// 無変更書き込み
$d = $REST_PAGES->read('テスト/ページ1');
$r3 = $REST_PAGES->write('テスト/ページ1', $d['content'], $d['sha1'], 'unit-test');
ok($r3['changed'] === false, '同一内容の書き込みは changed=false');

// =========================================================================
section('PageStore: 一覧と検索');
// =========================================================================
$REST_PAGES->write('検索用/日本語ページ', "ここに特徴的な語句カルボプラチンがあります。\n", $EMPTY, 'unit-test');
$REST_PAGES->write('SearchTest/English', "This page mentions pembrolizumab therapy.\n", $EMPTY, 'unit-test');

$list = $REST_PAGES->listPages(100, 0);
ok($list['total'] === 3, 'listPages が全ページを数える（total=' . $list['total'] . '）');
$names = array_column($list['pages'], 'name');
ok(in_array('テスト/ページ1', $names, true), '階層ページ名が一覧に含まれる');

$hits = $REST_PAGES->search('カルボプラチン', 10);
ok(count($hits) === 1 && $hits[0]['page'] === '検索用/日本語ページ', '日本語の本文検索');
ok(str_contains($hits[0]['snippet'], 'カルボプラチン'), 'スニペットに検索語が含まれる');

$hits = $REST_PAGES->search('PEMBROLIZUMAB', 10);
ok(count($hits) === 1, '大文字小文字を無視した英語検索');

$hits = $REST_PAGES->search('検索用', 10);
ok(count($hits) >= 1 && $hits[0]['name_match'] === true, 'ページ名マッチ');

// =========================================================================
section('SnapshotStore: 全版保存');
// =========================================================================
/** @var SnapshotStore $REST_SNAPSHOTS */
$revs = $REST_SNAPSHOTS->list('テスト/ページ1');
ok(count($revs) >= 2, '書き込みのたびにスナップショットが増える（' . count($revs) . '版）');
ok($revs[0]['ts'] >= $revs[count($revs) - 1]['ts'], '一覧は新しい順');

$content = $REST_SNAPSHOTS->read('テスト/ページ1', $revs[0]['id']);
ok(sha1($content) === $revs[0]['sha1'], 'スナップショット本文の sha1 整合性');

$dup = $REST_SNAPSHOTS->saveIfNew('テスト/ページ1', $content);
ok($dup === null, '同一内容は重複保存されない');

// zlib 無効環境のフォールバック形式（非圧縮 .txt）も一覧・読み出しできる
$plain_body = "非圧縮スナップショットのテスト本文\n";
$plain_sha1 = sha1($plain_body);
$plain_id   = '100.000000_' . $plain_sha1;
$plain_dir  = $REST_DATA_DIR . '/snapshots/' . strtoupper(bin2hex('テスト/ページ1'));
file_put_contents($plain_dir . '/' . $plain_id . '.txt', $plain_body);
$revs2 = $REST_SNAPSHOTS->list('テスト/ページ1');
ok(in_array($plain_id, array_column($revs2, 'id'), true), '非圧縮 .txt スナップショットが一覧に載る');
ok($REST_SNAPSHOTS->read('テスト/ページ1', $plain_id) === $plain_body, '非圧縮 .txt スナップショットを読み出せる');
ok($REST_SNAPSHOTS->saveIfNew('テスト/ページ1', $plain_body) === null, '非圧縮形式でも同一内容は重複保存されない');

expect_api_error(fn() => $REST_SNAPSHOTS->read('テスト/ページ1', '../../etc/passwd'), 400, '不正な revision id は 400');
expect_api_error(fn() => $REST_SNAPSHOTS->read('テスト/ページ1', '999_' . str_repeat('0', 40)), 400, '旧形式の revision id は 400');
expect_api_error(fn() => $REST_SNAPSHOTS->read('テスト/ページ1', '999.000000_' . str_repeat('0', 40)), 404, '未存在 revision は 404');

// =========================================================================
section('Audit: 監査ログ');
// =========================================================================
$audit_files = glob($REST_DATA_DIR . '/audit/audit-*.jsonl');
ok(count($audit_files) === 1, '監査ログファイルが作成される');
$lines = array_filter(explode("\n", (string)file_get_contents($audit_files[0])));
ok(count($lines) >= 5, '操作ごとにログ行が追記される（' . count($lines) . '行）');
$last = json_decode(end($lines), true);
ok(is_array($last) && isset($last['action'], $last['ts']), '各行が有効な JSON');
$actions = array_map(fn($l) => json_decode($l, true)['action'] ?? '', $lines);
ok(in_array('page_written', $actions, true), 'page_written が記録される');
ok(in_array('write_denied', $actions, true), 'write_denied（保護ページ拒否）が記録される');

summary();
