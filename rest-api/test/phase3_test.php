<?php
/**
 * Phase 3 検証テスト: MCP サーバー
 *
 * 確認すること:
 *  1. McpHandler::handle() が JSON-RPC 2.0 の正しい形式でレスポンスを返す
 *  2. initialize → capabilities が正しい
 *  3. tools/list → 全ツール定義が返る
 *  4. tools/call: wiki_read_page（正常・存在しない・引数なし）
 *  5. tools/call: wiki_search（ヒット・ヒットなし・短すぎるクエリ）
 *  6. tools/call: wiki_list_pages
 *  7. tools/call: wiki_create_draft（正常・base_sha1 フォーマット不正）
 *  8. tools/call: wiki_get_draft
 *  9. resources/list と resources/read
 * 10. Notification（id なし）は null を返す
 * 11. 不明メソッドは -32601 エラー
 * 12. stdio ループのシミュレーション（JSON 文字列 → JSON 文字列）
 *
 * 実行: php rest-api/test/phase3_test.php
 * @version v0.1
 */
declare(strict_types=1);

$test_dir = sys_get_temp_dir() . '/pkwk_phase3_' . getmypid();
$wiki_dir = $test_dir . '/wiki';
$db_dir   = $test_dir . '/db';
$blob_dir = $test_dir . '/blobs';

foreach ([$wiki_dir, $db_dir, $blob_dir] as $d) {
    mkdir($d, 0755, true);
}

$REST_DIR = dirname(__DIR__);
require_once $REST_DIR . '/lib/AtomicWriter.php';
require_once $REST_DIR . '/lib/RevisionStore.php';
require_once $REST_DIR . '/lib/Ledger.php';
require_once $REST_DIR . '/lib/Reconciler.php';
require_once $REST_DIR . '/lib/ApiException.php';
require_once $REST_DIR . '/lib/PageReader.php';
require_once $REST_DIR . '/mcp/McpHandler.php';

$schema   = $REST_DIR . '/schema/init.sql';
$db_path  = $db_dir . '/ledger.sqlite';
$ledger   = Ledger::open($db_path, $schema);
$store    = new RevisionStore($blob_dir);
$rec      = new Reconciler($ledger, $store, $wiki_dir);
$reader   = new PageReader($rec, $ledger, $wiki_dir);
$handler  = new McpHandler($reader, $ledger, 'test-actor');

// テスト用 wiki ファイルを作成
$pages = [
    'FrontPage' => "= FrontPage =\nWiki のトップページです。ようこそ。\n",
    'Help'      => "= Help =\n使い方のヘルプページ。PukiWiki の書式について。\n",
    'SandBox'   => "= SandBox =\n自由に試し書きできます。\n",
];
foreach ($pages as $name => $content) {
    $encoded = strtoupper(bin2hex($name));
    file_put_contents($wiki_dir . '/' . $encoded . '.txt', $content);
}
// インデックスを構築（Phase 2 で実装済み）
$rec->buildIndex();

// -------------------------------------------------------------------------
// テストハーネス
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

// JSON-RPC メッセージを作るヘルパ
function rpc(string $method, array $params = [], int $id = 1): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
}

function tool_call(string $name, array $args = [], int $id = 1): array
{
    return rpc('tools/call', ['name' => $name, 'arguments' => $args], $id);
}

// -------------------------------------------------------------------------
// 1. JSON-RPC レスポンス形式
// -------------------------------------------------------------------------
section('1. JSON-RPC レスポンス形式');

$resp = $handler->handle(rpc('ping'));
ok(isset($resp['jsonrpc']) && $resp['jsonrpc'] === '2.0', 'jsonrpc フィールドが "2.0"');
ok(isset($resp['id']) && $resp['id'] === 1, 'id が正しく返る');
ok(isset($resp['result']), 'result フィールドが存在する');
ok(!isset($resp['error']), '成功時に error フィールドがない');

// エラーレスポンス形式
$err_resp = $handler->handle(rpc('non_existent_method'));
ok(isset($err_resp['error']), 'エラー時に error フィールドがある');
ok(isset($err_resp['error']['code']), 'error.code がある');
ok(isset($err_resp['error']['message']), 'error.message がある');
ok($err_resp['error']['code'] === -32601, '不明メソッドは -32601');
ok(!isset($err_resp['result']), 'エラー時に result フィールドがない');

// Notification（id なし）→ null
$notif = $handler->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => []]);
ok($notif === null, 'Notification（id なし）は null を返す');

// -------------------------------------------------------------------------
// 2. initialize
// -------------------------------------------------------------------------
section('2. initialize');

$init = $handler->handle(rpc('initialize', [
    'protocolVersion' => '2024-11-05',
    'clientInfo'      => ['name' => 'test-client', 'version' => '1.0'],
]));
ok($init['result']['protocolVersion'] === '2024-11-05', 'protocolVersion が正しい');
ok(isset($init['result']['capabilities']['tools']), 'capabilities.tools が存在する');
ok(isset($init['result']['capabilities']['resources']), 'capabilities.resources が存在する');
ok($init['result']['serverInfo']['name'] === 'pukiwiki-rest-api', 'serverInfo.name が正しい');

// -------------------------------------------------------------------------
// 3. tools/list
// -------------------------------------------------------------------------
section('3. tools/list');

$tools_resp = $handler->handle(rpc('tools/list'));
$tools      = $tools_resp['result']['tools'];
ok(is_array($tools), 'tools が配列で返る');
ok(count($tools) >= 5, '5つ以上のツールが定義されている');

$tool_names = array_column($tools, 'name');
ok(in_array('wiki_read_page',    $tool_names, true), 'wiki_read_page が定義されている');
ok(in_array('wiki_search',       $tool_names, true), 'wiki_search が定義されている');
ok(in_array('wiki_list_pages',   $tool_names, true), 'wiki_list_pages が定義されている');
ok(in_array('wiki_create_draft', $tool_names, true), 'wiki_create_draft が定義されている');
ok(in_array('wiki_get_draft',    $tool_names, true), 'wiki_get_draft が定義されている');

// 各ツールに inputSchema があるか
foreach ($tools as $tool) {
    ok(isset($tool['inputSchema']), "{$tool['name']} に inputSchema がある");
    ok(isset($tool['description']), "{$tool['name']} に description がある");
}

// -------------------------------------------------------------------------
// 4. wiki_read_page
// -------------------------------------------------------------------------
section('4. wiki_read_page');

$read_resp = $handler->handle(tool_call('wiki_read_page', ['page' => 'FrontPage']));
ok($read_resp['result']['content'][0]['type'] === 'text', 'content[0].type が text');
$text = $read_resp['result']['content'][0]['text'];
ok(str_contains($text, 'FrontPage'), 'レスポンスにページ名が含まれる');
ok(str_contains($text, 'SHA1:'), 'レスポンスに SHA1 情報が含まれる');
ok(str_contains($text, 'Wiki のトップページです'), 'ページ本文が含まれる');
ok(str_contains($text, 'Rev:'), 'Rev 番号が含まれる');

// 存在しないページ
$notfound = $handler->handle(tool_call('wiki_read_page', ['page' => 'NoSuchPage999']));
ok(str_contains($notfound['result']['content'][0]['text'], 'does not exist'), '存在しないページに適切なメッセージ');

// 引数なし → エラー
$no_args = $handler->handle(tool_call('wiki_read_page', []));
ok(isset($no_args['error']), 'page 引数なしはエラー');

// -------------------------------------------------------------------------
// 5. wiki_search
// -------------------------------------------------------------------------
section('5. wiki_search');

$search_resp = $handler->handle(tool_call('wiki_search', ['query' => 'PukiWiki']));
$search_text = $search_resp['result']['content'][0]['text'];
ok(str_contains($search_text, 'found'), '検索結果に "found" が含まれる');

// 日本語検索
$jp_resp = $handler->handle(tool_call('wiki_search', ['query' => '使い方']));
$jp_text = $jp_resp['result']['content'][0]['text'];
ok(str_contains($jp_text, 'found') || str_contains($jp_text, 'Help'), '日本語検索が機能する');

// 短すぎるクエリ
$short = $handler->handle(tool_call('wiki_search', ['query' => 'ab']));
$short_text = $short['result']['content'][0]['text'];
ok(str_contains($short_text, '3 characters') || str_contains($short_text, 'minimum'), '2文字クエリに適切なメッセージ');

// ヒットなし
$no_hit = $handler->handle(tool_call('wiki_search', ['query' => 'XYZ存在しないキーワード123']));
$no_hit_text = $no_hit['result']['content'][0]['text'];
ok(str_contains($no_hit_text, 'No pages') || str_contains($no_hit_text, 'found'), 'ヒットなしに適切なメッセージ');

// limit が効く
$limited = $handler->handle(tool_call('wiki_search', ['query' => 'ページ', 'limit' => 1]));
ok(isset($limited['result']['content'][0]['text']), 'limit 付き検索が成功する');

// -------------------------------------------------------------------------
// 6. wiki_list_pages
// -------------------------------------------------------------------------
section('6. wiki_list_pages');

$list_resp = $handler->handle(tool_call('wiki_list_pages'));
$list_text = $list_resp['result']['content'][0]['text'];
ok(str_contains($list_text, 'FrontPage'), 'FrontPage が一覧に含まれる');
ok(str_contains($list_text, 'Help'), 'Help が一覧に含まれる');
ok(str_contains($list_text, 'SandBox'), 'SandBox が一覧に含まれる');

// limit
$limited_list = $handler->handle(tool_call('wiki_list_pages', ['limit' => 2]));
$limited_text = $limited_list['result']['content'][0]['text'];
ok(str_contains($limited_text, 'Pages'), 'limit 付き一覧が成功する');

// -------------------------------------------------------------------------
// 7. wiki_create_draft
// -------------------------------------------------------------------------
section('7. wiki_create_draft');

// FrontPage の現在の sha1 を取得
$fp_data = $reader->read('FrontPage');
$fp_sha1 = $fp_data['sha1'];

$draft_resp = $handler->handle(tool_call('wiki_create_draft', [
    'page'      => 'FrontPage',
    'base_sha1' => $fp_sha1,
    'body'      => "= FrontPage =\nこれは AI が提案した改訂版です。\n",
    'meta'      => ['reason' => 'test draft', 'model' => 'test'],
]));
$draft_text = $draft_resp['result']['content'][0]['text'];
ok(str_contains($draft_text, 'Draft created'), '下書き作成成功');
ok(str_contains($draft_text, 'Draft ID'), 'Draft ID が返る');
ok(str_contains($draft_text, 'human'), '人間承認が必要なことが明記される');

// Draft ID を抽出
preg_match('/Draft ID\s*:\s*(\d+)/', $draft_text, $m);
$draft_id = (int)($m[1] ?? 0);
ok($draft_id > 0, 'Draft ID が正の整数');

// DB に正しく保存されているか
$draft_record = $ledger->getDraft($draft_id);
ok($draft_record !== null, 'DB に下書きが保存される');
ok($draft_record['page'] === 'FrontPage', 'draft.page が正しい');
ok($draft_record['base_sha1'] === $fp_sha1, 'draft.base_sha1 が正しい');
ok($draft_record['status'] === 'open', 'draft.status が open');
ok($draft_record['owner'] === 'test-actor', 'draft.owner が actor と一致する');

// sha1 フォーマット不正
$bad_sha1 = $handler->handle(tool_call('wiki_create_draft', [
    'page'      => 'FrontPage',
    'base_sha1' => 'not-a-sha1',
    'body'      => 'body',
]));
ok(isset($bad_sha1['error']), '不正な sha1 はエラー');

// 必須引数なし
$no_page = $handler->handle(tool_call('wiki_create_draft', [
    'base_sha1' => $fp_sha1,
    'body'      => 'body',
]));
ok(isset($no_page['error']), 'page なしはエラー');

// -------------------------------------------------------------------------
// 8. wiki_get_draft
// -------------------------------------------------------------------------
section('8. wiki_get_draft');

$get_draft_resp = $handler->handle(tool_call('wiki_get_draft', ['draft_id' => $draft_id]));
$get_text = $get_draft_resp['result']['content'][0]['text'];
ok(str_contains($get_text, "Draft #{$draft_id}"), 'Draft ID が表示される');
ok(str_contains($get_text, 'FrontPage'), 'ページ名が表示される');
ok(str_contains($get_text, 'open'), 'ステータスが表示される');
ok(str_contains($get_text, $fp_sha1), 'base_sha1 が表示される');

// 存在しない Draft
$missing_draft = $handler->handle(tool_call('wiki_get_draft', ['draft_id' => 99999]));
ok(str_contains($missing_draft['result']['content'][0]['text'], 'not found'), '存在しない Draft に適切なメッセージ');

// 引数なし
$no_id = $handler->handle(tool_call('wiki_get_draft', []));
ok(isset($no_id['error']), 'draft_id なしはエラー');

// -------------------------------------------------------------------------
// 9. resources/list と resources/read
// -------------------------------------------------------------------------
section('9. resources');

$res_list = $handler->handle(rpc('resources/list'));
$resources = $res_list['result']['resources'];
ok(is_array($resources), 'resources が配列で返る');
ok(count($resources) >= count($pages), count($pages) . '件以上のリソースが返る');

// URI の形式確認
ok(str_starts_with($resources[0]['uri'], 'wiki://pages/'), 'URI が wiki://pages/ で始まる');
ok(isset($resources[0]['mimeType']), 'mimeType が存在する');
ok($resources[0]['mimeType'] === 'text/plain', 'mimeType が text/plain');

// resources/read
$res_read = $handler->handle(rpc('resources/read', [
    'uri' => 'wiki://pages/Help',
]));
ok(isset($res_read['result']['contents']), 'contents が返る');
ok($res_read['result']['contents'][0]['text'] === $pages['Help'], 'ページ本文が正しい');
ok($res_read['result']['contents'][0]['uri'] === 'wiki://pages/Help', 'URI が返る');

// 存在しないページのリソース → エラー
$bad_uri = $handler->handle(rpc('resources/read', ['uri' => 'wiki://pages/NoSuchPage']));
ok(isset($bad_uri['error']), '存在しないページはエラー');

// 不正な URI スキーム → エラー
$bad_scheme = $handler->handle(rpc('resources/read', ['uri' => 'http://example.com/']));
ok(isset($bad_scheme['error']), '不正な URI スキームはエラー');

// -------------------------------------------------------------------------
// 10. stdio ループのシミュレーション（JSON 文字列 → JSON 文字列）
// -------------------------------------------------------------------------
section('10. stdio JSON-RPC シミュレーション');

function process_line(McpHandler $handler, string $line): ?string
{
    $msg = json_decode(trim($line), true);
    if ($msg === null) {
        return json_encode([
            'jsonrpc' => '2.0', 'id' => null,
            'error' => ['code' => -32700, 'message' => 'Parse error'],
        ]);
    }
    $resp = $handler->handle($msg);
    return $resp !== null ? json_encode($resp, JSON_UNESCAPED_UNICODE) : null;
}

// 正常メッセージ
$line1 = json_encode(rpc('initialize', ['protocolVersion' => '2024-11-05', 'clientInfo' => ['name' => 'test', 'version' => '1']]));
$out1  = process_line($handler, $line1);
ok($out1 !== null, 'initialize の出力がある');
$decoded1 = json_decode($out1, true);
ok($decoded1['jsonrpc'] === '2.0', '出力が valid JSON-RPC');

// Notification
$notif_line = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => []]);
$out_notif  = process_line($handler, $notif_line);
ok($out_notif === null, 'Notification は出力なし');

// 不正な JSON
$bad_json = '{not valid json';
$out_bad  = process_line($handler, $bad_json);
ok($out_bad !== null, '不正 JSON にはエラーレスポンス');
$decoded_bad = json_decode($out_bad, true);
ok($decoded_bad['error']['code'] === -32700, '不正 JSON は -32700 Parse error');

// 複数メッセージを連続処理
$msgs = [
    rpc('tools/list', [], 10),
    tool_call('wiki_list_pages', [], 11),
    tool_call('wiki_search', ['query' => 'Wiki'], 12),
];
$outputs = [];
foreach ($msgs as $msg) {
    $out = process_line($handler, json_encode($msg));
    if ($out !== null) {
        $outputs[] = json_decode($out, true);
    }
}
ok(count($outputs) === 3, '3メッセージを連続処理して3つ返す');
ok($outputs[0]['id'] === 10, '1つ目のレスポンスの id が 10');
ok($outputs[1]['id'] === 11, '2つ目のレスポンスの id が 11');
ok($outputs[2]['id'] === 12, '3つ目のレスポンスの id が 12');

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
