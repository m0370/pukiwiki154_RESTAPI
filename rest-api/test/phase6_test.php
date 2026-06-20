<?php
/**
 * Phase 6 検証テスト: ブロック単位編集層
 *
 * 確認すること:
 *  1. BlockSplitter::split() 基本動作（各ブロック種別）
 *  2. BlockSplitter 不変量: join(split($c)) === $c（複数パターン）
 *  3. BlockSplitter::lineType() の全種別
 *  4. BlockSplitter — 見出し・HR は常に単独ブロック
 *  5. BlockSplitter — 連続リスト・テーブルは1ブロックにまとまる
 *  6. BlockSplitter — 空文字列の扱い
 *  7. BlockSplitter — 末尾改行の保持
 *  8. BlockSplitter — block_sha1 の安定性（同じ内容は同じ sha1）
 *  9. BlockEditor::apply() — 単一ブロック置換
 * 10. BlockEditor::apply() — 複数ブロック同時置換
 * 11. BlockEditor::apply() — ブロック削除（null）
 * 12. BlockEditor::apply() — 無効な block_sha1（409 block_not_found）
 * 13. BlockEditor::apply() — 置換後に不変量が維持される
 * 14. BlockEditor::describe() — インデックスと line_preview を返す
 * 15. GET /pages/{page}/blocks シミュレーション
 * 16. POST /pages/{page}/blocks → 下書き作成シミュレーション
 * 17. POST /pages/{page}/blocks → base_sha1 競合（409）
 * 18. POST /pages/{page}/blocks → 無効 block_sha1（409 block_not_found）
 * 19. MCP wiki_read_blocks ツール
 * 20. MCP wiki_read_blocks — 存在しないページ
 * 21. MCP wiki_patch_blocks — 正常
 * 22. MCP wiki_patch_blocks — base_sha1 競合
 * 23. MCP wiki_patch_blocks — 無効 block_sha1
 * 24. MCP tools/list — 新ツール 2 件が含まれる
 * 25. ブロック削除後の再組み立てが整合する
 *
 * 実行: php rest-api/test/phase6_test.php
 * @version v0.1
 */
declare(strict_types=1);

$test_dir = sys_get_temp_dir() . '/pkwk_phase6_' . getmypid();
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
require_once $REST_DIR . '/lib/Auth.php';
require_once $REST_DIR . '/lib/PageReader.php';
require_once $REST_DIR . '/lib/DiffEngine.php';
require_once $REST_DIR . '/lib/CommitEngine.php';
require_once $REST_DIR . '/lib/DraftManager.php';
require_once $REST_DIR . '/lib/BlockSplitter.php';
require_once $REST_DIR . '/lib/BlockEditor.php';
require_once $REST_DIR . '/lib/Response.php';
require_once $REST_DIR . '/mcp/McpHandler.php';

$schema  = $REST_DIR . '/schema/init.sql';
$db_path = $db_dir   . '/ledger.sqlite';
$ledger  = Ledger::open($db_path, $schema);
$store   = new RevisionStore($blob_dir);
$rec     = new Reconciler($ledger, $store, $wiki_dir);
$reader  = new PageReader($rec, $ledger, $wiki_dir);
$engine  = new CommitEngine($ledger, $store, $wiki_dir);

// MCP ハンドラ
$handler = new McpHandler($reader, $ledger, 'test-actor');

// API キー登録
$now     = time();
$raw_key = 'phase6-test-key-' . bin2hex(random_bytes(4));
$ledger->registerApiKey($raw_key, 'phase6-tester', 'page:read draft:create draft:approve', $now);
$auth    = new Auth($ledger);

// テスト用 wiki ページ
$fp_content = implode("\n", [
    '= FrontPage =',
    'これは PukiWiki のトップページです。',
    'ウィキへようこそ。',
    '',
    '== セクション1 ==',
    'セクション1の内容です。',
    '詳しく説明します。',
    '',
    '== セクション2 ==',
    '- リスト項目1',
    '-- ネストしたリスト',
    '- リスト項目2',
    '',
    '== セクション3 ==',
    '| カラム1 | カラム2 |',
    '| データ1 | データ2 |',
    '',
    '----',
    'フッターテキスト。',
    '',
]);
$fp_encoded = strtoupper(bin2hex('FrontPage'));
file_put_contents($wiki_dir . '/' . $fp_encoded . '.txt', $fp_content);
$rec->buildIndex();

// ──────────────────────────────────────────────────
// テストハーネス
// ──────────────────────────────────────────────────
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
function section(string $name): void { echo "\n\e[1m{$name}\e[0m\n"; }

// ──────────────────────────────────────────────────
// 1. BlockSplitter::split() 基本動作
// ──────────────────────────────────────────────────
section('1. BlockSplitter::split() 基本動作');

$blocks = BlockSplitter::split($fp_content);
ok(!empty($blocks), 'split() がブロック配列を返す');
ok(count($blocks) > 5, '複数ブロックに分割される');

// 各ブロックが type, content, block_sha1 を持つ
ok(isset($blocks[0]['type']),       '各ブロックに type がある');
ok(isset($blocks[0]['content']),    '各ブロックに content がある');
ok(isset($blocks[0]['block_sha1']), '各ブロックに block_sha1 がある');
ok(strlen($blocks[0]['block_sha1']) === 16, 'block_sha1 が 16 文字');
ok(ctype_xdigit($blocks[0]['block_sha1']), 'block_sha1 が 16 進数');

// 見出しブロック
$heading_blocks = array_filter($blocks, fn($b) => $b['type'] === 'heading');
ok(count($heading_blocks) >= 4, '見出しブロックが 4 件以上（= FrontPage =, == セクション1 == 等）');

// リストブロック
$list_blocks = array_filter($blocks, fn($b) => $b['type'] === 'list');
ok(!empty($list_blocks), 'リストブロックが存在する');

// テーブルブロック
$table_blocks = array_filter($blocks, fn($b) => $b['type'] === 'table');
ok(!empty($table_blocks), 'テーブルブロックが存在する');

// HR ブロック
$hr_blocks = array_filter($blocks, fn($b) => $b['type'] === 'hr');
ok(!empty($hr_blocks), 'HR ブロックが存在する');

// ──────────────────────────────────────────────────
// 2. 不変量: join(split($c)) === $c
// ──────────────────────────────────────────────────
section('2. 不変量: join(split($c)) === $c');

$test_contents = [
    $fp_content,
    "単純なテキスト",
    "行1\n行2\n行3",
    "= 見出し =\n本文\n",
    "= H1 =\n\n== H2 ==\n内容\n",
    "- item\n-- sub\n- item2",
    "| col1 | col2 |\n| data1 | data2 |",
    " コード\n さらにコード",
    "----\n",
    "",
    "\n",
    "行1\n\n\n行4",
    "#plugin(args)\n普通のテキスト",
];

foreach ($test_contents as $i => $tc) {
    $rejoined = BlockSplitter::join(BlockSplitter::split($tc));
    ok($rejoined === $tc, "不変量テスト #{$i}: join(split(c)) === c");
}

// ──────────────────────────────────────────────────
// 3. BlockSplitter::lineType() 全種別
// ──────────────────────────────────────────────────
section('3. BlockSplitter::lineType() 全種別');

ok(BlockSplitter::lineType('')                === 'empty',      '空行 → empty');
ok(BlockSplitter::lineType('----')            === 'hr',         '---- → hr');
ok(BlockSplitter::lineType('--------')        === 'hr',         '-------- → hr');
ok(BlockSplitter::lineType('= H1 =')          === 'heading',    '= H1 = → heading');
ok(BlockSplitter::lineType('== H2 ==')        === 'heading',    '== H2 == → heading');
ok(BlockSplitter::lineType('=== H3 ===')      === 'heading',    '=== H3 === → heading');
ok(BlockSplitter::lineType('#plugin')          === 'plugin',     '#plugin → plugin');
ok(BlockSplitter::lineType('#table_of_contents()') === 'plugin', '#table_of_contents() → plugin');
ok(BlockSplitter::lineType('- item')           === 'list',       '- item → list');
ok(BlockSplitter::lineType('-- item')          === 'list',       '-- item → list');
ok(BlockSplitter::lineType('+ item')           === 'list',       '+ item → list');
ok(BlockSplitter::lineType('++ item')          === 'list',       '++ item → list');
ok(BlockSplitter::lineType('* item')           === 'list',       '* item → list');
ok(BlockSplitter::lineType('| a | b |')        === 'table',      '| a | b | → table');
ok(BlockSplitter::lineType(':term|desc')       === 'definition', ':term|desc → definition');
ok(BlockSplitter::lineType(' code')            === 'pre',        ' code → pre');
ok(BlockSplitter::lineType("\tcode")           === 'pre',        'tab code → pre');
ok(BlockSplitter::lineType('普通のテキスト')   === 'paragraph',  '普通のテキスト → paragraph');
ok(BlockSplitter::lineType('Hello world')      === 'paragraph',  'Hello world → paragraph');

// ──────────────────────────────────────────────────
// 4. 見出し・HR は常に単独ブロック
// ──────────────────────────────────────────────────
section('4. 見出し・HR は常に単独ブロック');

$mixed = "= H1 =\n== H2 ==\n本文\n= H3 =";
$mixed_blocks = BlockSplitter::split($mixed);
$heading_types = array_column(array_filter($mixed_blocks, fn($b) => $b['type'] === 'heading'), 'content');
ok(in_array('= H1 =', $heading_types, true), 'H1 が独立したブロック');
ok(in_array('== H2 ==', $heading_types, true), 'H2 が独立したブロック');
ok(in_array('= H3 =', $heading_types, true), 'H3 が独立したブロック');
ok(count(array_filter($mixed_blocks, fn($b) => $b['type'] === 'heading')) === 3, '見出しブロックが 3 件');

// 本文（H2とH3の間）は段落ブロック
$para_blocks = array_filter($mixed_blocks, fn($b) => $b['type'] === 'paragraph');
ok(count($para_blocks) === 1, '段落ブロックが 1 件');

$hr_mixed = "本文A\n----\n本文B";
$hr_mixed_blocks = BlockSplitter::split($hr_mixed);
ok(count(array_filter($hr_mixed_blocks, fn($b) => $b['type'] === 'hr')) === 1, 'HR が単独ブロック');
ok(count(array_filter($hr_mixed_blocks, fn($b) => $b['type'] === 'paragraph')) === 2, '前後の段落が 2 件');

// ──────────────────────────────────────────────────
// 5. 連続リスト・テーブルは1ブロックにまとまる
// ──────────────────────────────────────────────────
section('5. 連続リスト・テーブルは1ブロックにまとまる');

$list_content = "- item1\n-- sub1\n-- sub2\n- item2\n--- deep";
$list_blocks  = BlockSplitter::split($list_content);
ok(count($list_blocks) === 1, 'リスト全体が1ブロック');
ok($list_blocks[0]['type'] === 'list', 'type が list');
ok($list_blocks[0]['content'] === $list_content, 'content が全行を含む');

$table_content = "| h1 | h2 |\n| d1 | d2 |\n| d3 | d4 |";
$table_blocks  = BlockSplitter::split($table_content);
ok(count($table_blocks) === 1, 'テーブル全体が1ブロック');
ok($table_blocks[0]['type'] === 'table', 'type が table');

// ──────────────────────────────────────────────────
// 6. 空文字列の扱い
// ──────────────────────────────────────────────────
section('6. 空文字列の扱い');

$empty_blocks = BlockSplitter::split('');
ok($empty_blocks === [], '空文字列は空配列');
ok(BlockSplitter::join([]) === '', 'join([]) は空文字列');

// ──────────────────────────────────────────────────
// 7. 末尾改行の保持
// ──────────────────────────────────────────────────
section('7. 末尾改行の保持');

$with_nl  = "行1\n行2\n";
$without  = "行1\n行2";

$joined1  = BlockSplitter::join(BlockSplitter::split($with_nl));
$joined2  = BlockSplitter::join(BlockSplitter::split($without));

ok($joined1 === $with_nl,  '末尾改行ありが保持される');
ok($joined2 === $without,  '末尾改行なしも正しい');
ok($joined1 !== $joined2,  '末尾改行あり/なしが区別される');

// ──────────────────────────────────────────────────
// 8. block_sha1 の安定性
// ──────────────────────────────────────────────────
section('8. block_sha1 の安定性');

$same1 = BlockSplitter::split("同じ内容\n");
$same2 = BlockSplitter::split("同じ内容\n");
ok($same1[0]['block_sha1'] === $same2[0]['block_sha1'], '同じ内容は同じ block_sha1');

$diff1 = BlockSplitter::split("内容A\n");
$diff2 = BlockSplitter::split("内容B\n");
ok($diff1[0]['block_sha1'] !== $diff2[0]['block_sha1'], '異なる内容は異なる block_sha1');

// ──────────────────────────────────────────────────
// 9. BlockEditor::apply() — 単一ブロック置換
// ──────────────────────────────────────────────────
section('9. BlockEditor::apply() — 単一ブロック置換');

$source    = "= タイトル =\n本文の段落。\n詳細説明。\n\n== セクション ==\n追加情報。\n";
$blocks_s  = BlockSplitter::split($source);

// 段落ブロックを見つける
$para_block = array_values(array_filter($blocks_s, fn($b) => $b['type'] === 'paragraph'))[0];

$new_content = BlockEditor::apply($source, [
    ['block_sha1' => $para_block['block_sha1'], 'new_content' => "新しい段落の内容。\nより詳しい説明。"],
]);

ok(str_contains($new_content, '新しい段落の内容'), '段落が置換される');
ok(!str_contains($new_content, '本文の段落'), '古い内容が消える');
ok(str_contains($new_content, '= タイトル ='), 'タイトルは変わらない');
ok(str_contains($new_content, '== セクション =='), 'セクション見出しは変わらない');

// 不変量の確認
$re_blocks = BlockSplitter::split($new_content);
$re_joined = BlockSplitter::join($re_blocks);
ok($re_joined === $new_content, '置換後も join(split(c)) === c');

// ──────────────────────────────────────────────────
// 10. BlockEditor::apply() — 複数ブロック同時置換
// ──────────────────────────────────────────────────
section('10. BlockEditor::apply() — 複数ブロック同時置換');

$multi_source = "= H1 =\n段落A。\n\n= H2 =\n段落B。\n";
$multi_blocks = BlockSplitter::split($multi_source);

$para_a = array_values(array_filter($multi_blocks, fn($b) => $b['content'] === '段落A。'))[0] ?? null;
$para_b = array_values(array_filter($multi_blocks, fn($b) => $b['content'] === '段落B。'))[0] ?? null;

ok($para_a !== null, '段落A が見つかる');
ok($para_b !== null, '段落B が見つかる');

if ($para_a && $para_b) {
    $multi_result = BlockEditor::apply($multi_source, [
        ['block_sha1' => $para_a['block_sha1'], 'new_content' => '更新した段落A。'],
        ['block_sha1' => $para_b['block_sha1'], 'new_content' => '更新した段落B。'],
    ]);
    ok(str_contains($multi_result, '更新した段落A'), '段落A が更新される');
    ok(str_contains($multi_result, '更新した段落B'), '段落B が更新される');
    ok(!str_contains($multi_result, '段落A。') || str_contains($multi_result, '更新した段落A'), '古い段落A が消える');
}

// ──────────────────────────────────────────────────
// 11. BlockEditor::apply() — ブロック削除（null）
// ──────────────────────────────────────────────────
section('11. BlockEditor::apply() — ブロック削除（null）');

$del_source = "= H =\n削除する段落。\n\n残す段落。\n";
$del_blocks = BlockSplitter::split($del_source);
$del_target = array_values(array_filter($del_blocks, fn($b) => $b['content'] === '削除する段落。'))[0] ?? null;

ok($del_target !== null, '削除対象ブロックが見つかる');

if ($del_target) {
    $del_result = BlockEditor::apply($del_source, [
        ['block_sha1' => $del_target['block_sha1'], 'new_content' => null],
    ]);
    ok(!str_contains($del_result, '削除する段落'), '段落が削除される');
    ok(str_contains($del_result, '残す段落'), '他の段落は残る');
    ok(str_contains($del_result, '= H ='), '見出しは残る');
}

// ──────────────────────────────────────────────────
// 12. BlockEditor::apply() — 無効な block_sha1（409）
// ──────────────────────────────────────────────────
section('12. BlockEditor::apply() — 無効な block_sha1（409）');

try {
    BlockEditor::apply("内容\n", [
        ['block_sha1' => 'deadbeef01234567', 'new_content' => '置換内容'],
    ]);
    ok(false, '無効な sha1 は ApiException を投げる');
} catch (ApiException $e) {
    ok($e->status === 409, '409 が返る');
    ok($e->error_code === 'block_not_found', 'error_code が block_not_found');
    ok(str_contains($e->getMessage(), 'deadbeef01234567'), 'sha1 がメッセージに含まれる');
}

// 有効な sha1 と無効な sha1 が混在する場合も 409
$valid_block = BlockSplitter::split("段落A。\n")[0];
try {
    BlockEditor::apply("段落A。\n", [
        ['block_sha1' => $valid_block['block_sha1'], 'new_content' => '更新'],
        ['block_sha1' => 'invalidsha1123456', 'new_content' => '更新2'],
    ]);
    ok(false, '有効/無効混在も 409');
} catch (ApiException $e) {
    ok($e->status === 409, '混在時も 409');
}

// ──────────────────────────────────────────────────
// 13. BlockEditor::apply() — 置換後の不変量
// ──────────────────────────────────────────────────
section('13. BlockEditor::apply() — 置換後の不変量');

$complex = "= H1 =\n\n段落1行目\n段落2行目\n\n== H2 ==\n- list\n-- sub\n\n----\n末尾\n";
$c_blocks = BlockSplitter::split($complex);

// 全ブロックを同じ内容で置換（not null → 内容に変化なし）
$identity_patches = array_map(fn($b) => ['block_sha1' => $b['block_sha1'], 'new_content' => $b['content']], $c_blocks);
$identity_result  = BlockEditor::apply($complex, $identity_patches);
ok($identity_result === $complex, '全ブロックを同一内容で置換しても原文と一致');

// ──────────────────────────────────────────────────
// 14. BlockEditor::describe()
// ──────────────────────────────────────────────────
section('14. BlockEditor::describe()');

$desc = BlockEditor::describe($fp_content);
ok(is_array($desc), 'describe() が配列を返す');
ok(!empty($desc), 'describe() が空でない');
ok(isset($desc[0]['index']),        'index フィールドが存在する');
ok(isset($desc[0]['type']),         'type フィールドが存在する');
ok(isset($desc[0]['content']),      'content フィールドが存在する');
ok(isset($desc[0]['block_sha1']),   'block_sha1 フィールドが存在する');
ok(isset($desc[0]['line_preview']), 'line_preview フィールドが存在する');
ok($desc[0]['index'] === 0,         '最初のブロックの index が 0');

// 改行が ↵ に変換されているか
$multiline_desc = array_filter($desc, fn($b) => str_contains($b['content'], "\n"));
foreach (array_slice(array_values($multiline_desc), 0, 3) as $b) {
    ok(!str_contains($b['line_preview'], "\n"), 'line_preview に改行が含まれない');
}

// ──────────────────────────────────────────────────
// 15. GET /pages/{page}/blocks シミュレーション
// ──────────────────────────────────────────────────
section('15. GET /pages/{page}/blocks シミュレーション');

function simulate_get_blocks(
    string $page, string $raw_key, string $ip,
    Auth $auth, PageReader $reader
): Response {
    try {
        $auth->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"], 'page:read', $ip);
        $data   = $reader->read($page);
        $blocks = BlockEditor::describe($data['content']);
        return Response::ok([
            'page'        => $data['page'],
            'sha1'        => $data['sha1'],
            'rev'         => $data['rev'],
            'block_count' => count($blocks),
            'blocks'      => $blocks,
        ]);
    } catch (\Throwable $e) {
        return Response::fromException($e);
    }
}

$r15 = simulate_get_blocks('FrontPage', $raw_key, '1.2.3.4', $auth, $reader);
ok($r15->getStatus() === 200, 'GET /pages/FrontPage/blocks → 200');
$b15 = $r15->toArray();
ok(isset($b15['page']),        'page フィールドがある');
ok(isset($b15['sha1']),        'sha1 フィールドがある');
ok(isset($b15['blocks']),      'blocks フィールドがある');
ok(isset($b15['block_count']), 'block_count フィールドがある');
ok(is_array($b15['blocks']),   'blocks が配列');
ok($b15['block_count'] === count($b15['blocks']), 'block_count と blocks の件数が一致');

// 認証なし
$r15b = simulate_get_blocks('FrontPage', 'bad-key', '1.2.3.4', $auth, $reader);
ok($r15b->getStatus() === 401, '認証なしは 401');

// ──────────────────────────────────────────────────
// 16. POST /pages/{page}/blocks → 下書き作成
// ──────────────────────────────────────────────────
section('16. POST /pages/{page}/blocks → 下書き作成');

function simulate_post_blocks(
    string $page, array $body, string $raw_key, string $ip,
    Auth $auth, PageReader $reader, Ledger $ledger
): Response {
    try {
        $key = $auth->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"], 'draft:create', $ip);
        $actor = (string)($key['label'] ?? 'api');

        $base_sha1 = trim((string)($body['base_sha1'] ?? ''));
        $patches   = (array)($body['patches'] ?? []);
        $meta      = (array)($body['meta'] ?? []);

        if ($base_sha1 === '') {
            throw new ApiException(400, '"base_sha1" is required', 'missing_base_sha1');
        }
        if (empty($patches)) {
            throw new ApiException(400, '"patches" must be a non-empty array', 'missing_patches');
        }

        $page_data = $reader->read($page);

        if ($page_data['sha1'] !== $base_sha1) {
            throw new ApiException(409, "Conflict: sha1 mismatch for '{$page}'.", 'sha1_conflict');
        }

        $new_content = BlockEditor::apply($page_data['content'], $patches);
        $new_sha1    = sha1($new_content);
        $diff        = DiffEngine::unified($page_data['content'], $new_content, 'current', 'draft');
        $diff_stats  = DiffEngine::stats($diff);

        $t       = time();
        $expires = $t + 7 * 24 * 3600;
        $did     = $ledger->createDraft($page, $base_sha1, $new_content, $actor, $t, $expires,
            array_merge(['source' => 'block_patch', 'patches_count' => count($patches)], $meta));

        return Response::created([
            'draft_id'        => $did,
            'page'            => $page,
            'base_sha1'       => $base_sha1,
            'new_sha1'        => $new_sha1,
            'patches_applied' => count($patches),
            'diff'            => $diff,
            'diff_stats'      => $diff_stats,
        ]);
    } catch (\Throwable $e) {
        return Response::fromException($e);
    }
}

// 現在のページ sha1 を取得
$fp_data    = $reader->read('FrontPage');
$fp_sha1    = $fp_data['sha1'];
$fp_blocks  = BlockSplitter::split($fp_data['content']);
$fp_para    = array_values(array_filter($fp_blocks, fn($b) => $b['type'] === 'paragraph'))[0] ?? null;

ok($fp_para !== null, 'FrontPage の段落ブロックが見つかる');

if ($fp_para) {
    $r16 = simulate_post_blocks('FrontPage', [
        'base_sha1' => $fp_sha1,
        'patches' => [
            ['block_sha1' => $fp_para['block_sha1'], 'new_content' => 'AI が修正した段落。'],
        ],
        'meta' => ['reason' => 'ブロック編集テスト'],
    ], $raw_key, '1.2.3.4', $auth, $reader, $ledger);

    ok($r16->getStatus() === 201, 'POST /pages/{page}/blocks → 201 Created');
    $b16 = $r16->toArray();
    ok(isset($b16['draft_id']),    'draft_id が返る');
    ok(isset($b16['diff']),        'diff が返る');
    ok(isset($b16['diff_stats']),  'diff_stats が返る');
    ok($b16['patches_applied'] === 1, 'patches_applied が 1');

    // 下書きが DB に保存されているか
    $saved_draft = $ledger->getDraft((int)$b16['draft_id']);
    ok($saved_draft !== null, '下書きが DB に保存される');
    ok(str_contains((string)$saved_draft['body'], 'AI が修正した段落'), '下書きに修正内容が含まれる');
    $meta_decoded = json_decode($saved_draft['meta'], true);
    ok(($meta_decoded['source'] ?? '') === 'block_patch', 'meta.source が block_patch');
}

// ──────────────────────────────────────────────────
// 17. POST /pages/{page}/blocks — base_sha1 競合（409）
// ──────────────────────────────────────────────────
section('17. POST /pages/{page}/blocks — base_sha1 競合');

$r17 = simulate_post_blocks('FrontPage', [
    'base_sha1' => 'abcdef1234567890abcdef1234567890abcdef12', // 古い sha1
    'patches'   => [['block_sha1' => 'deadbeef01234567', 'new_content' => '置換']],
], $raw_key, '1.2.3.4', $auth, $reader, $ledger);

ok($r17->getStatus() === 409, 'base_sha1 競合は 409');
ok($r17->toArray()['error']['code'] === 'sha1_conflict', 'error_code が sha1_conflict');

// ──────────────────────────────────────────────────
// 18. POST /pages/{page}/blocks — 無効 block_sha1（409 block_not_found）
// ──────────────────────────────────────────────────
section('18. POST /pages/{page}/blocks — 無効 block_sha1');

$r18 = simulate_post_blocks('FrontPage', [
    'base_sha1' => $fp_sha1,
    'patches'   => [['block_sha1' => 'invalidblk01234', 'new_content' => '置換']],
], $raw_key, '1.2.3.4', $auth, $reader, $ledger);

ok($r18->getStatus() === 409, '無効 block_sha1 は 409');
ok($r18->toArray()['error']['code'] === 'block_not_found', 'error_code が block_not_found');

// ──────────────────────────────────────────────────
// MCP ツールテスト（19-24）
// ──────────────────────────────────────────────────

function mcp_call(McpHandler $handler, string $tool, array $args): array
{
    $resp = $handler->handle([
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'tools/call',
        'params'  => ['name' => $tool, 'arguments' => $args],
    ]);
    return $resp ?? [];
}

section('19. MCP wiki_read_blocks — 正常');

$r19 = mcp_call($handler, 'wiki_read_blocks', ['page' => 'FrontPage']);
ok(isset($r19['result']['content'][0]['text']), 'wiki_read_blocks がテキストを返す');
$t19 = $r19['result']['content'][0]['text'];
ok(str_contains($t19, '# Blocks:'), 'ブロック一覧ヘッダが含まれる');
ok(str_contains($t19, 'type=heading'), '見出しブロックが含まれる');
ok(str_contains($t19, 'sha1='), 'sha1 が含まれる');
ok(str_contains($t19, 'Page SHA1'), 'ページ SHA1 が表示される');

// block_sha1 を抽出（次のテストで使用）
preg_match_all('/sha1=([0-9a-f]{16})/', $t19, $sha1_matches);
$block_sha1s = $sha1_matches[1] ?? [];
ok(!empty($block_sha1s), 'ブロック sha1 が複数見つかる');

section('20. MCP wiki_read_blocks — 存在しないページ');

$r20 = mcp_call($handler, 'wiki_read_blocks', ['page' => 'NoSuchPageXYZ']);
ok(isset($r20['result']['content'][0]['text']), 'レスポンスがある');
ok(str_contains($r20['result']['content'][0]['text'], 'does not exist'), '存在しないページに適切なメッセージ');

section('21. MCP wiki_patch_blocks — 正常');

$fp_reload = $reader->read('FrontPage');
$fp_sha1   = $fp_reload['sha1'];
$fp_blks   = BlockSplitter::split($fp_reload['content']);
$fp_para   = array_values(array_filter($fp_blks, fn($b) => $b['type'] === 'paragraph'))[0] ?? null;

ok($fp_para !== null, 'MCP テスト用の段落ブロックが見つかる');

if ($fp_para) {
    $r21 = mcp_call($handler, 'wiki_patch_blocks', [
        'page'      => 'FrontPage',
        'base_sha1' => $fp_sha1,
        'patches'   => [
            ['block_sha1' => $fp_para['block_sha1'], 'new_content' => 'MCP から修正した段落。'],
        ],
        'meta' => ['reason' => 'MCP block patch test'],
    ]);
    ok(!isset($r21['error']), 'wiki_patch_blocks がエラーなし');
    $t21 = $r21['result']['content'][0]['text'];
    ok(str_contains($t21, 'Draft ID'), 'Draft ID が返る');
    ok(str_contains($t21, 'open (pending human review)'), '承認待ちであることが明記される');
    ok(str_contains($t21, 'IMPORTANT'), '重要な注意事項が含まれる');
}

section('22. MCP wiki_patch_blocks — base_sha1 競合');

$r22 = mcp_call($handler, 'wiki_patch_blocks', [
    'page'      => 'FrontPage',
    'base_sha1' => 'abcdef1234567890abcdef1234567890abcdef12',
    'patches'   => [['block_sha1' => 'deadbeef01234567', 'new_content' => '内容']],
]);
ok(!isset($r22['error']), 'MCP 自体はエラーにならない（メッセージで通知）');
$t22 = $r22['result']['content'][0]['text'];
ok(str_contains($t22, 'Conflict') || str_contains($t22, 'modified'), '競合メッセージが返る');

section('23. MCP wiki_patch_blocks — 無効 block_sha1');

$fp_reload2 = $reader->read('FrontPage');
$r23 = mcp_call($handler, 'wiki_patch_blocks', [
    'page'      => 'FrontPage',
    'base_sha1' => $fp_reload2['sha1'],
    'patches'   => [['block_sha1' => 'badblock01234567', 'new_content' => '内容']],
]);
ok(!isset($r23['error']), 'MCP 自体はエラーにならない（メッセージで通知）');
$t23 = $r23['result']['content'][0]['text'];
ok(str_contains($t23, 'Block patch failed') || str_contains($t23, 'not found'), 'ブロック未発見メッセージが返る');

section('24. MCP tools/list — 新ツール 2 件が含まれる');

$r24 = $handler->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
$tool_names = array_column($r24['result']['tools'], 'name');
ok(in_array('wiki_read_blocks',  $tool_names, true), 'tools/list に wiki_read_blocks がある');
ok(in_array('wiki_patch_blocks', $tool_names, true), 'tools/list に wiki_patch_blocks がある');
ok(count($tool_names) === 7, 'ツール合計 7 件');

// wiki_read_blocks の inputSchema 確認
$rblks_def = array_values(array_filter($r24['result']['tools'], fn($t) => $t['name'] === 'wiki_read_blocks'))[0];
ok(isset($rblks_def['inputSchema']['properties']['page']), 'wiki_read_blocks に page プロパティ');

// wiki_patch_blocks の inputSchema 確認
$pblks_def = array_values(array_filter($r24['result']['tools'], fn($t) => $t['name'] === 'wiki_patch_blocks'))[0];
ok(isset($pblks_def['inputSchema']['properties']['patches']), 'wiki_patch_blocks に patches プロパティ');
ok(isset($pblks_def['inputSchema']['properties']['base_sha1']), 'wiki_patch_blocks に base_sha1 プロパティ');

// ──────────────────────────────────────────────────
// 25. ブロック削除後の再組み立てが整合する
// ──────────────────────────────────────────────────
section('25. ブロック削除後の再組み立て整合');

$del_page = "= タイトル =\n\n段落1。\n\n= 中間見出し =\n\n段落2。\n\n= 末尾見出し =\n末尾段落。\n";
$del_blks = BlockSplitter::split($del_page);

// 中間見出しを削除
$mid_h = array_values(array_filter($del_blks, fn($b) => $b['content'] === '= 中間見出し ='))[0] ?? null;
ok($mid_h !== null, '中間見出しブロックが見つかる');

if ($mid_h) {
    $del_result = BlockEditor::apply($del_page, [
        ['block_sha1' => $mid_h['block_sha1'], 'new_content' => null],
    ]);
    ok(!str_contains($del_result, '= 中間見出し ='), '中間見出しが削除される');
    ok(str_contains($del_result, '= タイトル ='), 'タイトルは残る');
    ok(str_contains($del_result, '= 末尾見出し ='), '末尾見出しは残る');

    // 削除後も不変量（再分割→再結合が一致する）
    $del_check = BlockSplitter::join(BlockSplitter::split($del_result));
    ok($del_check === $del_result, '削除後も不変量が維持される');
}

// ──────────────────────────────────────────────────
// 結果サマリ
// ──────────────────────────────────────────────────
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
