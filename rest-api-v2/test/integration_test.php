<?php
/**
 * 統合テスト — 実 PukiWiki 1.5.4 ツリーに対して本物の page_write() を通す。
 *
 * 実行方法（使い捨てのコピーに対して実行すること）:
 *   cp -r /path/to/pukiwiki /tmp/pkwk-test
 *   PKWK_ROOT=/tmp/pkwk-test php rest-api-v2/test/integration_test.php
 *
 * PKWK_ROOT が未指定の場合は実行を拒否する（実運用の wiki を汚さないため）。
 *
 * 検証項目（旧実装 v0.1 で発見された不具合の再発防止）:
 *   1. bootstrap が Fatal しない（DATA_HOME / 依存 lib）
 *   2. 書き込み後の new_sha1 == 実ファイルの sha1（page_write の本文変形対応）
 *   3. 返却 sha1 を base にした連続書き込みが 409 にならない
 *   4. 無変更書き込みは changed=false
 *   5. 空本文は 400（ページ削除の素通し防止）
 *   6. page_write 経由で diff/ と RecentChanges が更新される
 *   7. 凍結ページ・保護ページは 403
 *   8. 階層ページ名（親/子）の読み書き
 *   9. スナップショットが書き込みごとに増える
 *  10. #author 行に API 操作者が記録される
 *  11. MCP ハンドラの write フローが実 PukiWiki で通る
 * @version v2.0
 */
declare(strict_types=1);

require_once __DIR__ . '/testlib.php';

$pkwk_root = getenv('PKWK_ROOT');
if ($pkwk_root === false || $pkwk_root === '') {
    fwrite(STDERR, "PKWK_ROOT を設定してください（使い捨ての PukiWiki コピーを指すこと）。\n");
    fwrite(STDERR, "例: PKWK_ROOT=/tmp/pkwk-test php rest-api-v2/test/integration_test.php\n");
    exit(2);
}
if (!is_file($pkwk_root . '/pukiwiki.ini.php')) {
    fwrite(STDERR, "PKWK_ROOT に pukiwiki.ini.php が見つかりません: {$pkwk_root}\n");
    exit(2);
}

// テストデータは PukiWiki コピー内に置く（コピーごと捨てられる）
putenv("PKWK_REST_DATA={$pkwk_root}/rest-api-v2-testdata");

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../mcp/McpHandler.php';

$EMPTY = PageStore::EMPTY_SHA1;

// =========================================================================
section('1. bootstrap / PukiWiki 本体ロード');
// =========================================================================
ok($REST_PKWK_LOADED === true, 'PukiWiki 本体をロードできた（Fatal しない）');
ok(function_exists('page_write'), 'page_write() が利用可能');
ok(function_exists('is_freeze') && function_exists('is_editable'), 'is_freeze/is_editable が利用可能');
ok(function_exists('encode') && encode('あ') === strtoupper(bin2hex('あ')), '本体の encode() と互換');
ok(is_dir($REST_WIKI_DIR) && str_starts_with($REST_WIKI_DIR, '/'), "wiki dir を絶対パスで解決: {$REST_WIKI_DIR}");

// =========================================================================
section('2〜4. 書き込みと sha1 整合（page_write の本文変形対応）');
// =========================================================================
$page = '統合テスト/APIページ';
$body = "*統合テストの見出し\nこれは統合テストの本文です。\n- 項目1\n- 項目2\n";

$r1 = $REST_PAGES->write($page, $body, $EMPTY, 'integration-key');
ok($r1['is_new'] === true, '新規ページ作成');

$file = $REST_PAGES->filePath($page);
clearstatcache();
$stored = (string)file_get_contents($file);
ok($r1['new_sha1'] === sha1($stored), '★ new_sha1 == 実ファイルの sha1（v0.1 の致命バグ対策）');
ok($r1['new_sha1'] !== sha1($body), 'page_write が本文を変形している（#author/アンカー付与を検出）');
ok(str_contains($stored, '#author('), '#author 行が保存内容に含まれる');
ok(str_contains($stored, 'integration-key'), '★ #author 行に API 操作者が記録される');

// 返却 sha1 を base にした連続書き込み
$d = $REST_PAGES->read($page);
ok($d['sha1'] === $r1['new_sha1'], 'read の sha1 が write の返却値と一致');

$modified = str_replace('項目2', '項目2を修正', $d['content']);
$r2 = $REST_PAGES->write($page, $modified, $r1['new_sha1'], 'integration-key');
ok($r2['changed'] === true, '★ 返却 sha1 を base にした連続書き込みが 409 にならない');

clearstatcache();
ok($r2['new_sha1'] === sha1((string)file_get_contents($file)), '2回目の new_sha1 もファイルと一致');

// 無変更書き込み（保存済み内容をそのまま送る）
$d2 = $REST_PAGES->read($page);
$r3 = $REST_PAGES->write($page, $d2['content'], $d2['sha1'], 'integration-key');
ok($r3['changed'] === false, '無変更書き込みは changed=false（page_write の黙殺 return を検出）');
ok($r3['new_sha1'] === $d2['sha1'], '無変更時は sha1 が変わらない');

// 競合
expect_api_error(
    fn() => $REST_PAGES->write($page, "古い版から\n", $r1['new_sha1'], 'integration-key'),
    409, '古い base_sha1 は 409'
);

// =========================================================================
section('5. 空本文の拒否（ページ削除の素通し防止）');
// =========================================================================
expect_api_error(
    fn() => $REST_PAGES->write($page, '', $r3['new_sha1'], 'integration-key'),
    400, '空本文は 400'
);
clearstatcache();
ok(is_file($file), 'ページファイルは削除されていない');

// =========================================================================
section('6. PukiWiki 本体の副作用（diff / RecentChanges）');
// =========================================================================
$diff_file = $pkwk_root . '/diff/' . encode($page) . '.txt';
ok(is_file($diff_file), 'diff/ に差分が作成される');
$recent = (string)@file_get_contents($pkwk_root . '/cache/recent.dat');
ok(str_contains($recent, $page), 'RecentChanges（recent.dat）に反映される');

// =========================================================================
section('7. 凍結・保護ページ');
// =========================================================================
expect_api_error(
    fn() => $REST_PAGES->write('FrontPage', "乗っ取り\n", $EMPTY, 'integration-key'),
    403, '保護ページ FrontPage は 403'
);
expect_api_error(
    fn() => $REST_PAGES->write('MenuBar', "乗っ取り\n", $EMPTY, 'integration-key'),
    403, '保護ページ MenuBar は 403'
);

// 凍結ページ: 一旦作成 → #freeze を直接付与（Web UI の凍結操作に相当）→ API 書き込みは 403
$fz_page = '統合テスト/凍結ページ';
$rf = $REST_PAGES->write($fz_page, "凍結される前の本文\n", $EMPTY, 'integration-key');
$fz_file = $REST_PAGES->filePath($fz_page);
file_put_contents($fz_file, "#freeze\n" . file_get_contents($fz_file));
is_freeze($fz_page, true); // キャッシュクリア
expect_api_error(
    fn() => $REST_PAGES->write($fz_page, "凍結を無視した上書き\n", sha1((string)file_get_contents($fz_file)), 'integration-key'),
    403, '凍結ページへの書き込みは 403（is_freeze チェック）'
);

// RecentChanges は is_editable の cantedit で保護されているはず
$whatsnew = $GLOBALS['whatsnew'] ?? 'RecentChanges';
$wn_file  = $REST_PAGES->filePath($whatsnew);
$wn_sha1  = is_file($wn_file) ? sha1((string)file_get_contents($wn_file)) : $EMPTY;
expect_api_error(
    fn() => $REST_PAGES->write($whatsnew, "改ざん\n", $wn_sha1, 'integration-key'),
    403, "{$whatsnew} への書き込みは 403（is_editable チェック）"
);

// =========================================================================
section('8. 階層ページ名');
// =========================================================================
$deep = '統合テスト/親/子/孫ページ';
$rd = $REST_PAGES->write($deep, "深い階層のページ\n", $EMPTY, 'integration-key');
ok($rd['is_new'] === true, '3階層のページ名で作成できる');
$dd = $REST_PAGES->read($deep);
ok(str_contains($dd['content'], '深い階層のページ'), '階層ページの読み取り');

$list = $REST_PAGES->listPages(1000, 0);
$names = array_column($list['pages'], 'name');
ok(in_array($deep, $names, true), '一覧に階層ページ名が現れる');
ok(!in_array(':config', $names, true), "一覧に ':' システムページが出ない");

$hits = $REST_PAGES->search('深い階層', 10);
ok(count($hits) >= 1 && $hits[0]['page'] === $deep, '検索で階層ページが見つかる');

// =========================================================================
section('9. スナップショット（API 書き込み全版保存）');
// =========================================================================
$revs = $REST_SNAPSHOTS->list($page);
ok(count($revs) >= 2, "書き込みごとにスナップショットが増える（" . count($revs) . "版）");
$restored = $REST_SNAPSHOTS->read($page, $revs[count($revs) - 1]['id']);
ok(sha1($restored) === $revs[count($revs) - 1]['sha1'], '最古版の整合性検証');
// 過去版の復元 = 現在の sha1 を base にして過去版本文を PUT
$cur = $REST_PAGES->read($page);
$rr = $REST_PAGES->write($page, $restored, $cur['sha1'], 'integration-key');
ok($rr['changed'] === true, '過去版の復元（PUT-back）が成功');

// =========================================================================
section('10. ルーティング（フロントコントローラのパス解決）');
// =========================================================================
$m = Router::match('/pages/{page...}', '/pages/' . $deep);
ok($m !== null && $m['page'] === $deep, '階層ページ名がルートにマッチ');

// =========================================================================
section('11. MCP ハンドラ（実 PukiWiki 上で）');
// =========================================================================
$mcp = new McpHandler($REST_PAGES, 'mcp-integration');

$resp = $mcp->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
ok(isset($resp['result']['serverInfo']['name']), 'MCP initialize');

$resp = $mcp->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);
$tool_names = array_column($resp['result']['tools'] ?? [], 'name');
ok($tool_names === ['wiki_read_page', 'wiki_list_pages', 'wiki_search', 'wiki_write_page'],
    'MCP ツールは 4 個（read/list/search/write）');

$resp = $mcp->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
    'params' => ['name' => 'wiki_read_page', 'arguments' => ['page' => $page]]]);
$text = $resp['result']['content'][0]['text'] ?? '';
ok(str_contains($text, 'SHA1:'), 'MCP wiki_read_page が sha1 を返す');
preg_match('/^SHA1: ([0-9a-f]{40})$/m', $text, $mm);
$mcp_sha1 = $mm[1] ?? '';
ok($mcp_sha1 !== '', 'MCP レスポンスから sha1 を取得');

$resp = $mcp->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
    'params' => ['name' => 'wiki_write_page', 'arguments' => [
        'page' => $page, 'base_sha1' => $mcp_sha1,
        'content' => "*MCP からの更新\nMCP 経由で書き込みました。\n",
    ]]]);
$text = $resp['result']['content'][0]['text'] ?? '';
ok(str_contains($text, 'updated successfully'), 'MCP wiki_write_page で更新成功');

$resp = $mcp->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
    'params' => ['name' => 'wiki_write_page', 'arguments' => [
        'page' => $page, 'base_sha1' => $mcp_sha1,
        'content' => "古い base での上書き\n",
    ]]]);
$text = $resp['result']['content'][0]['text'] ?? '';
ok(str_contains($text, 'sha1_conflict'), 'MCP でも古い base_sha1 は競合エラー');

$resp = $mcp->handle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
    'params' => ['name' => 'wiki_write_page', 'arguments' => [
        'page' => 'FrontPage', 'base_sha1' => $EMPTY, 'content' => "乗っ取り\n",
    ]]]);
$text = $resp['result']['content'][0]['text'] ?? '';
ok(str_contains($text, 'page_protected'), 'MCP でも保護ページは拒否');

clearstatcache();
$final = (string)file_get_contents($file);
ok(str_contains($final, 'mcp-integration'), 'MCP 書き込みの #author に MCP actor が記録される');

summary();
