<?php
/**
 * Phase 8 検証テスト: API 経由の直接編集
 *
 * 確認すること:
 *  1. CommitEngine::EMPTY_SHA1 定数が正しい
 *  2. PUT /pages/{page} — 新規ページ作成（EMPTY_SHA1）
 *  3. PUT /pages/{page} — 既存ページ更新
 *  4. PUT /pages/{page} — sha1 競合（409 sha1_conflict）
 *  5. PUT /pages/{page} — base_sha1 未指定（400 missing_base_sha1）
 *  6. PUT /pages/{page} — 無効な base_sha1 フォーマット（400 invalid_base_sha1）
 *  7. PUT /pages/{page} — page:write スコープなし（401/403）
 *  8. PUT /pages/{page} — ページがロック中（423 locked）
 *  9. PUT /pages/{page} — 凍結ページ（403 page_frozen）
 * 10. PUT /pages/{page} — EMPTY_SHA1 以外で存在しないページ（409）
 * 11. PUT 後に GET で内容確認（コンテンツが反映される）
 * 12. PUT でリビジョン番号が増える
 * 13. PUT で監査ログ（page_committed）が記録される
 * 14. PUT の summary が meta に保存される
 * 15. PUT — content 空文字列で新規ページ作成可能（空ページ）
 * 16. PATCH /pages/{page} — ブロック単位更新（page:write）
 * 17. PATCH /pages/{page} — 更新後に GET で内容確認
 * 18. PATCH /pages/{page} — base_sha1 競合（409 sha1_conflict）
 * 19. PATCH /pages/{page} — 無効な block_sha1（409 block_not_found）
 * 20. PATCH /pages/{page} — patches 未指定（400 missing_patches）
 * 21. PATCH /pages/{page} — base_sha1 未指定（400 missing_base_sha1）
 * 22. PATCH /pages/{page} — page:write スコープなし（401/403）
 * 23. PATCH /pages/{page} — 存在しないページは 404
 * 24. PATCH でリビジョン番号が増える
 * 25. フル操作フロー: 新規作成 → GET確認 → PATCH更新 → GET確認
 * 26. PATCH → ロールバック後に旧内容に戻る（AdminManager との連携）
 * 27. 連続 PUT でリビジョンが単調増加する
 * 28. PUT の meta.source が put_page
 * 29. PATCH の meta.source が patch_page・patches_count が正しい
 * 30. ロック解放後に別エージェントが PUT できる
 *
 * 実行: php rest-api/test/phase8_test.php
 * @version v0.1
 */
declare(strict_types=1);

// CommitEngine が参照する関数モックを require より前に定義する
if (!function_exists('page_write')) {
    function page_write(string $page, string $body): void
    {
        $wiki_dir = $GLOBALS['_TEST_WIKI_DIR'] ?? '';
        if ($wiki_dir !== '') {
            $file = $wiki_dir . '/' . strtoupper(bin2hex($page)) . '.txt';
            file_put_contents($file, $body);
        }
        $GLOBALS['_page_write_calls'][] = ['page' => $page, 'body' => $body];
    }
}
if (!function_exists('is_freeze')) {
    function is_freeze(string $page): bool { return $page === 'FrozenPage'; }
}
if (!function_exists('is_editable')) {
    function is_editable(string $page): bool { return true; }
}

$test_dir = sys_get_temp_dir() . '/pkwk_phase8_' . getmypid();
$wiki_dir = $test_dir . '/wiki';
$db_dir   = $test_dir . '/db';
$blob_dir = $test_dir . '/blobs';

foreach ([$wiki_dir, $db_dir, $blob_dir] as $d) {
    mkdir($d, 0755, true);
}

$GLOBALS['_TEST_WIKI_DIR']    = $wiki_dir;
$GLOBALS['_page_write_calls'] = [];

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
require_once $REST_DIR . '/lib/AdminManager.php';
require_once $REST_DIR . '/lib/Response.php';
require_once $REST_DIR . '/lib/Router.php';

$schema   = $REST_DIR . '/schema/init.sql';
$db_path  = $db_dir . '/ledger.sqlite';
$ledger   = Ledger::open($db_path, $schema);
$store    = new RevisionStore($blob_dir);
$rec      = new Reconciler($ledger, $store, $wiki_dir);
$reader   = new PageReader($rec, $ledger, $wiki_dir);
$engine   = new CommitEngine($ledger, $store, $wiki_dir);
$admin_mg = new AdminManager($ledger, $store, $engine, $wiki_dir);
$auth     = new Auth($ledger);
$now      = time();

// API キー登録
$write_key_raw    = 'write-key-'    . bin2hex(random_bytes(4));
$readonly_key_raw = 'readonly-key-' . bin2hex(random_bytes(4));
$ledger->registerApiKey($write_key_raw,    'write-tester',    'page:read page:write', $now);
$ledger->registerApiKey($readonly_key_raw, 'readonly-tester', 'page:read',            $now);

// テスト用既存ページ
$existing_content = "= ExistingPage =\n既存ページのコンテンツ。\n\n== Section ==\n内容。\n";
$existing_file    = $wiki_dir . '/' . strtoupper(bin2hex('ExistingPage')) . '.txt';
file_put_contents($existing_file, $existing_content);
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
// API リクエストシミュレーター
// ──────────────────────────────────────────────────

// 保護ページリスト（index.php の $REST_PROTECTED_PAGES と同一）
const PROTECTED_PAGES = ['FrontPage', 'MenuBar'];

function simulate_put(
    string $page, array $body, string $raw_key, string $ip,
    Auth $auth, CommitEngine $engine
): Response {
    try {
        $key = $auth->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"], 'page:write', $ip);

        // 保護ページは直接編集不可
        if (in_array($page, PROTECTED_PAGES, true)) {
            throw new ApiException(403,
                "Page '{$page}' is protected from direct editing. Use POST /drafts instead.",
                'page_protected');
        }

        $base_sha1 = trim((string)($body['base_sha1'] ?? ''));
        $content   = (string)($body['content']  ?? '');
        $summary   = trim((string)($body['summary'] ?? ''));
        $meta      = (array)($body['meta']     ?? []);
        $actor     = (string)($key['label']    ?? 'api');

        if ($base_sha1 === '') {
            throw new ApiException(400,
                '"base_sha1" is required. Use ' . CommitEngine::EMPTY_SHA1 . ' for new pages.',
                'missing_base_sha1');
        }
        if (!preg_match('/^[0-9a-f]{40}$/i', $base_sha1)) {
            throw new ApiException(400, '"base_sha1" must be a 40-char hex SHA1.', 'invalid_base_sha1');
        }
        $is_new = ($base_sha1 === CommitEngine::EMPTY_SHA1);
        if ($summary !== '') {
            $meta['summary'] = $summary;
        }
        $meta['source'] = 'put_page';

        $result = $engine->commit($page, $content, $base_sha1, $actor, $meta);
        $payload = [
            'page'         => $page,
            'new_rev'      => $result['new_rev'],
            'new_sha1'     => $result['new_sha1'],
            'committed_at' => $result['committed_at'],
        ];
        return $is_new ? Response::created($payload) : Response::ok($payload);
    } catch (\Throwable $e) {
        return Response::fromException($e);
    }
}

function simulate_patch(
    string $page, array $body, string $raw_key, string $ip,
    Auth $auth, PageReader $reader, CommitEngine $engine
): Response {
    try {
        $key = $auth->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"], 'page:write', $ip);

        // 保護ページは直接編集不可
        if (in_array($page, PROTECTED_PAGES, true)) {
            throw new ApiException(403,
                "Page '{$page}' is protected from direct editing. Use POST /drafts instead.",
                'page_protected');
        }

        $base_sha1 = trim((string)($body['base_sha1'] ?? ''));
        $patches   = (array)($body['patches']  ?? []);
        $summary   = trim((string)($body['summary'] ?? ''));
        $meta      = (array)($body['meta']     ?? []);
        $actor     = (string)($key['label']    ?? 'api');

        if ($base_sha1 === '') {
            throw new ApiException(400, '"base_sha1" is required.', 'missing_base_sha1');
        }
        if (!preg_match('/^[0-9a-f]{40}$/i', $base_sha1)) {
            throw new ApiException(400, '"base_sha1" must be a 40-char hex SHA1.', 'invalid_base_sha1');
        }
        if (empty($patches)) {
            throw new ApiException(400, '"patches" must be a non-empty array.', 'missing_patches');
        }

        $page_data = $reader->read($page);
        if ($page_data['sha1'] !== $base_sha1) {
            throw new ApiException(409,
                "Conflict: page '{$page}' sha1 mismatch. Expected {$base_sha1}, current {$page_data['sha1']}.",
                'sha1_conflict');
        }

        $new_content = BlockEditor::apply($page_data['content'], $patches);

        if ($summary !== '') {
            $meta['summary'] = $summary;
        }
        $meta['source']        = 'patch_page';
        $meta['patches_count'] = count($patches);

        $result = $engine->commit($page, $new_content, $base_sha1, $actor, $meta);
        return Response::ok([
            'page'            => $page,
            'new_rev'         => $result['new_rev'],
            'new_sha1'        => $result['new_sha1'],
            'committed_at'    => $result['committed_at'],
            'patches_applied' => count($patches),
        ]);
    } catch (\Throwable $e) {
        return Response::fromException($e);
    }
}

function simulate_get(
    string $page, string $raw_key, string $ip,
    Auth $auth, PageReader $reader
): Response {
    try {
        $auth->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"], 'page:read', $ip);
        return Response::ok($reader->read($page));
    } catch (\Throwable $e) {
        return Response::fromException($e);
    }
}

// ──────────────────────────────────────────────────
// 1. CommitEngine::EMPTY_SHA1 定数
// ──────────────────────────────────────────────────
section('1. CommitEngine::EMPTY_SHA1 定数');

ok(CommitEngine::EMPTY_SHA1 === 'da39a3ee5e6b4b0d3255bfef95601890afd80709', 'EMPTY_SHA1 が sha1("") と一致');
ok(sha1('') === CommitEngine::EMPTY_SHA1, 'PHP の sha1("") と一致');

// ──────────────────────────────────────────────────
// 2. PUT — 新規ページ作成（EMPTY_SHA1）
// ──────────────────────────────────────────────────
section('2. PUT /pages/{page} — 新規ページ作成');

$new_content_v1 = "= NewPage =\n最初のコンテンツ。\nここが始まりです。\n";

$r2 = simulate_put('NewPage', [
    'base_sha1' => CommitEngine::EMPTY_SHA1,
    'content'   => $new_content_v1,
    'summary'   => '新規ページ作成テスト',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r2->getStatus() === 201, 'PUT NewPage（新規作成）→ 201 Created');
$b2 = $r2->toArray();
ok(isset($b2['new_rev']),      'new_rev が返る');
ok(isset($b2['new_sha1']),     'new_sha1 が返る');
ok(isset($b2['committed_at']), 'committed_at が返る');
ok($b2['page'] === 'NewPage',  'page が NewPage');
ok($b2['new_rev'] >= 1,        'new_rev >= 1');
ok($b2['new_sha1'] === sha1($new_content_v1), 'new_sha1 が content の sha1 と一致');

// ファイルが実際に書き込まれているか
$new_file = $wiki_dir . '/' . strtoupper(bin2hex('NewPage')) . '.txt';
ok(file_exists($new_file), 'wiki ファイルが作成される');
ok(file_get_contents($new_file) === $new_content_v1, 'ファイルの内容が正しい');

// ──────────────────────────────────────────────────
// 3. PUT — 既存ページ更新
// ──────────────────────────────────────────────────
section('3. PUT /pages/{page} — 既存ページ更新');

$existing_sha1  = sha1($existing_content);
$updated_content = "= ExistingPage =\n更新されたコンテンツ。\n\n== Section ==\n新しい内容。\n";

$r3 = simulate_put('ExistingPage', [
    'base_sha1' => $existing_sha1,
    'content'   => $updated_content,
    'summary'   => '既存ページ更新テスト',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r3->getStatus() === 200, 'PUT ExistingPage → 200');
$b3 = $r3->toArray();
ok($b3['new_sha1'] === sha1($updated_content), 'new_sha1 が更新内容の sha1');

// ──────────────────────────────────────────────────
// 4. PUT — sha1 競合（409）
// ──────────────────────────────────────────────────
section('4. PUT /pages/{page} — sha1 競合（409）');

$r4 = simulate_put('ExistingPage', [
    'base_sha1' => $existing_sha1, // 古い sha1（既に更新済み）
    'content'   => '競合するコンテンツ。',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r4->getStatus() === 409, '古い base_sha1 → 409');
ok($r4->toArray()['error']['code'] === 'sha1_conflict', 'error_code が sha1_conflict');

// ──────────────────────────────────────────────────
// 5. PUT — base_sha1 未指定（400）
// ──────────────────────────────────────────────────
section('5. PUT /pages/{page} — base_sha1 未指定（400）');

$r5 = simulate_put('SomePage', [
    'content' => 'コンテンツ',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r5->getStatus() === 400, 'base_sha1 なし → 400');
ok($r5->toArray()['error']['code'] === 'missing_base_sha1', 'error_code が missing_base_sha1');

// ──────────────────────────────────────────────────
// 6. PUT — 無効な base_sha1 フォーマット（400）
// ──────────────────────────────────────────────────
section('6. PUT /pages/{page} — 無効な base_sha1 フォーマット（400）');

$r6 = simulate_put('SomePage', [
    'base_sha1' => 'not-a-valid-sha1',
    'content'   => 'コンテンツ',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r6->getStatus() === 400, '無効 base_sha1 → 400');
ok($r6->toArray()['error']['code'] === 'invalid_base_sha1', 'error_code が invalid_base_sha1');

// ──────────────────────────────────────────────────
// 7. PUT — page:write スコープなし（401/403）
// ──────────────────────────────────────────────────
section('7. PUT /pages/{page} — page:write スコープなし');

$r7 = simulate_put('ExistingPage', [
    'base_sha1' => sha1($updated_content),
    'content'   => '書き込み試みるコンテンツ',
], $readonly_key_raw, '127.0.0.1', $auth, $engine);

ok(in_array($r7->getStatus(), [401, 403], true), '読み取り専用キーでは 401/403');

// ──────────────────────────────────────────────────
// 8. PUT — ページがロック中（423）
// ──────────────────────────────────────────────────
section('8. PUT /pages/{page} — ページがロック中（423）');

$lock_page = 'LockedPage';
$lock_body = "ロックページ。\n";
$lock_file = $wiki_dir . '/' . strtoupper(bin2hex($lock_page)) . '.txt';
file_put_contents($lock_file, $lock_body);

// ロックを手動で取得
$lock_held = $ledger->acquireLock($lock_page, 'external-holder', 60, $now);
ok($lock_held, 'ロックを手動取得できた');

$r8 = simulate_put($lock_page, [
    'base_sha1' => CommitEngine::EMPTY_SHA1,
    'content'   => 'ロック中に書こうとする',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r8->getStatus() === 423, 'ロック中は 423 Locked');
ok(isset($r8->toArray()['error']), '423 はエラーレスポンス');

// ロック解放
$ledger->releaseLock($lock_page, 'external-holder');

// ──────────────────────────────────────────────────
// 9. PUT — 凍結ページ（403 page_frozen）
// ──────────────────────────────────────────────────
section('9. PUT /pages/{page} — 凍結ページ（403）');

$frozen_body = "凍結ページ。\n";
$frozen_file = $wiki_dir . '/' . strtoupper(bin2hex('FrozenPage')) . '.txt';
file_put_contents($frozen_file, $frozen_body);
// CAS チェックは freeze チェックより先に行われるため、正しい sha1 を渡す必要がある
$frozen_sha1 = sha1($frozen_body);

$r9 = simulate_put('FrozenPage', [
    'base_sha1' => $frozen_sha1,
    'content'   => '凍結ページに書こうとする',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r9->getStatus() === 403, '凍結ページは 403');
ok($r9->toArray()['error']['code'] === 'page_frozen', 'error_code が page_frozen');

// ──────────────────────────────────────────────────
// 10. PUT — EMPTY_SHA1 以外で存在しないページ（409）
// ──────────────────────────────────────────────────
section('10. PUT — EMPTY_SHA1 以外で存在しないページ（409）');

$r10 = simulate_put('NonExistentPage', [
    'base_sha1' => 'abcdef1234567890abcdef1234567890abcdef12',
    'content'   => '存在しないページへ',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r10->getStatus() === 409, '存在しないページに非 EMPTY_SHA1 → 409');
ok(str_contains((string)$r10->toArray()['error']['code'], 'conflict'),
   'error_code に conflict が含まれる');

// ──────────────────────────────────────────────────
// 11. PUT 後に GET で内容確認
// ──────────────────────────────────────────────────
section('11. PUT 後に GET で内容確認');

$r11_get = simulate_get('NewPage', $write_key_raw, '127.0.0.1', $auth, $reader);
ok($r11_get->getStatus() === 200, 'GET NewPage → 200');
$b11 = $r11_get->toArray();
ok($b11['content'] === $new_content_v1, 'GET で PUT した内容が取得できる');
ok($b11['sha1'] === sha1($new_content_v1), 'GET の sha1 が PUT の sha1 と一致');

// ──────────────────────────────────────────────────
// 12. PUT でリビジョン番号が増える
// ──────────────────────────────────────────────────
section('12. PUT でリビジョン番号が増える');

$content_v2 = "= NewPage =\nv2 の内容。\n";
$current_sha1_np = sha1($new_content_v1);

$r12a = simulate_put('NewPage', [
    'base_sha1' => $current_sha1_np,
    'content'   => $content_v2,
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r12a->getStatus() === 200, '2 回目の PUT → 200');
$b12a = $r12a->toArray();

$content_v3 = "= NewPage =\nv3 の内容。\n";
$r12b = simulate_put('NewPage', [
    'base_sha1' => $b12a['new_sha1'],
    'content'   => $content_v3,
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r12b->getStatus() === 200, '3 回目の PUT → 200');
$b12b = $r12b->toArray();

ok($b12b['new_rev'] > $b12a['new_rev'], '3 回目 rev > 2 回目 rev（単調増加）');
ok($b12b['new_rev'] > $b2['new_rev'],   '3 回目 rev > 1 回目 rev');

// ──────────────────────────────────────────────────
// 13. PUT で監査ログが記録される
// ──────────────────────────────────────────────────
section('13. PUT で監査ログ（page_committed）が記録される');

$audit_rows = $ledger->listAudit('NewPage', 'page_committed', null, 0, 10, 0);
ok(count($audit_rows) >= 3, 'NewPage の page_committed が 3 件以上');

$latest_audit = $audit_rows[0];
$detail       = json_decode($latest_audit['detail'], true);
ok($latest_audit['actor'] === 'write-tester', 'actor が write-tester');
ok($latest_audit['page']  === 'NewPage',      'page が NewPage');

// ──────────────────────────────────────────────────
// 14. PUT の summary が meta に保存される
// ──────────────────────────────────────────────────
section('14. PUT の summary が meta に保存される');

$rev_list = $ledger->listRevisions('NewPage', 10);
$latest_rev = $rev_list[0];
$rev_meta   = json_decode($latest_rev['meta'], true);
ok(isset($rev_meta['source']),  'meta に source がある');
ok($rev_meta['source'] === 'put_page', 'meta.source が put_page');

// summary 付きで PUT した最初のリビジョンを確認
$oldest_rev = end($rev_list);
$oldest_meta = json_decode($oldest_rev['meta'], true);
ok(($oldest_meta['summary'] ?? '') === '新規ページ作成テスト', 'summary が meta に保存される');

// ──────────────────────────────────────────────────
// 15. PUT — content 空文字列で新規ページ作成可能
// ──────────────────────────────────────────────────
section('15. PUT — content 空文字列で新規ページ作成');

$r15 = simulate_put('EmptyPage', [
    'base_sha1' => CommitEngine::EMPTY_SHA1,
    'content'   => '',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r15->getStatus() === 201, '空コンテンツの新規ページ作成 → 201 Created');
$b15 = $r15->toArray();
ok($b15['new_sha1'] === sha1(''), 'new_sha1 が sha1("") = EMPTY_SHA1');

// ──────────────────────────────────────────────────
// 16. PATCH /pages/{page} — ブロック単位更新
// ──────────────────────────────────────────────────
section('16. PATCH /pages/{page} — ブロック単位更新');

$patch_base_content = "= PatchPage =\n\n段落A。詳細説明。\n\n== セクション ==\n段落B。ここを編集。\n\n----\nフッター。\n";
$patch_file         = $wiki_dir . '/' . strtoupper(bin2hex('PatchPage')) . '.txt';
file_put_contents($patch_file, $patch_base_content);
$rec->buildIndex();

$patch_base_sha1 = sha1($patch_base_content);
$blocks          = BlockSplitter::split($patch_base_content);
$para_b          = array_values(array_filter($blocks, fn($b) => $b['content'] === '段落B。ここを編集。'))[0] ?? null;

ok($para_b !== null, 'PATCH 対象ブロック（段落B）が見つかる');

if ($para_b) {
    $r16 = simulate_patch('PatchPage', [
        'base_sha1' => $patch_base_sha1,
        'patches'   => [
            ['block_sha1' => $para_b['block_sha1'], 'new_content' => '段落B。更新された内容。'],
        ],
        'summary'   => 'ブロック編集テスト',
    ], $write_key_raw, '127.0.0.1', $auth, $reader, $engine);

    ok($r16->getStatus() === 200, 'PATCH PatchPage → 200');
    $b16 = $r16->toArray();
    ok(isset($b16['new_rev']),         'new_rev が返る');
    ok(isset($b16['new_sha1']),        'new_sha1 が返る');
    ok($b16['patches_applied'] === 1,  'patches_applied が 1');
}

// ──────────────────────────────────────────────────
// 17. PATCH 後に GET で内容確認
// ──────────────────────────────────────────────────
section('17. PATCH 後に GET で内容確認');

$r17 = simulate_get('PatchPage', $write_key_raw, '127.0.0.1', $auth, $reader);
ok($r17->getStatus() === 200, 'GET PatchPage → 200');
$b17 = $r17->toArray();
ok(str_contains($b17['content'], '更新された内容'), 'PATCH 後のコンテンツに新しい内容が含まれる');
ok(!str_contains($b17['content'], '段落B。ここを編集。'), '古い内容が消えている');
ok(str_contains($b17['content'], '段落A'), '変更していないブロックは残っている');
ok(str_contains($b17['content'], '= PatchPage ='), '見出しは残っている');

// ──────────────────────────────────────────────────
// 18. PATCH — base_sha1 競合（409 sha1_conflict）
// ──────────────────────────────────────────────────
section('18. PATCH — base_sha1 競合（409 sha1_conflict）');

$r18 = simulate_patch('PatchPage', [
    'base_sha1' => $patch_base_sha1, // 古い sha1
    'patches'   => [['block_sha1' => 'anyshabloock12', 'new_content' => '内容']],
], $write_key_raw, '127.0.0.1', $auth, $reader, $engine);

ok($r18->getStatus() === 409, '古い base_sha1 で PATCH → 409');
ok($r18->toArray()['error']['code'] === 'sha1_conflict', 'error_code が sha1_conflict');

// ──────────────────────────────────────────────────
// 19. PATCH — 無効な block_sha1（409 block_not_found）
// ──────────────────────────────────────────────────
section('19. PATCH — 無効な block_sha1（409 block_not_found）');

$r17_data = $reader->read('PatchPage');
$r19 = simulate_patch('PatchPage', [
    'base_sha1' => $r17_data['sha1'],
    'patches'   => [['block_sha1' => 'deadbeef00000000', 'new_content' => '内容']],
], $write_key_raw, '127.0.0.1', $auth, $reader, $engine);

ok($r19->getStatus() === 409, '無効 block_sha1 → 409');
ok($r19->toArray()['error']['code'] === 'block_not_found', 'error_code が block_not_found');

// ──────────────────────────────────────────────────
// 20. PATCH — patches 未指定（400）
// ──────────────────────────────────────────────────
section('20. PATCH — patches 未指定（400）');

$r20 = simulate_patch('PatchPage', [
    'base_sha1' => $r17_data['sha1'],
], $write_key_raw, '127.0.0.1', $auth, $reader, $engine);

ok($r20->getStatus() === 400, 'patches なし → 400');
ok($r20->toArray()['error']['code'] === 'missing_patches', 'error_code が missing_patches');

// ──────────────────────────────────────────────────
// 21. PATCH — base_sha1 未指定（400）
// ──────────────────────────────────────────────────
section('21. PATCH — base_sha1 未指定（400）');

$r21 = simulate_patch('PatchPage', [
    'patches' => [['block_sha1' => 'abc', 'new_content' => '内容']],
], $write_key_raw, '127.0.0.1', $auth, $reader, $engine);

ok($r21->getStatus() === 400, 'base_sha1 なし → 400');
ok($r21->toArray()['error']['code'] === 'missing_base_sha1', 'error_code が missing_base_sha1');

// ──────────────────────────────────────────────────
// 22. PATCH — page:write スコープなし（401/403）
// ──────────────────────────────────────────────────
section('22. PATCH — page:write スコープなし（401/403）');

$r22 = simulate_patch('PatchPage', [
    'base_sha1' => $r17_data['sha1'],
    'patches'   => [['block_sha1' => 'abc', 'new_content' => '内容']],
], $readonly_key_raw, '127.0.0.1', $auth, $reader, $engine);

ok(in_array($r22->getStatus(), [401, 403], true), '読み取り専用キーで PATCH → 401/403');

// ──────────────────────────────────────────────────
// 23. PATCH — 存在しないページは 404
// ──────────────────────────────────────────────────
section('23. PATCH — 存在しないページは 404');

$r23 = simulate_patch('NoSuchPageForPatch', [
    'base_sha1' => CommitEngine::EMPTY_SHA1,
    'patches'   => [['block_sha1' => 'abc', 'new_content' => '内容']],
], $write_key_raw, '127.0.0.1', $auth, $reader, $engine);

ok($r23->getStatus() === 404, '存在しないページへの PATCH → 404');

// ──────────────────────────────────────────────────
// 24. PATCH でリビジョン番号が増える
// ──────────────────────────────────────────────────
section('24. PATCH でリビジョン番号が増える');

$pp_before = $reader->read('PatchPage');
$pp_blocks = BlockSplitter::split($pp_before['content']);
$pp_first_para = array_values(array_filter($pp_blocks, fn($b) => $b['type'] === 'paragraph'))[0] ?? null;

ok($pp_first_para !== null, 'PATCH 用段落ブロックが見つかる');

if ($pp_first_para) {
    $r24 = simulate_patch('PatchPage', [
        'base_sha1' => $pp_before['sha1'],
        'patches'   => [
            ['block_sha1' => $pp_first_para['block_sha1'], 'new_content' => '更新後の段落A。'],
        ],
    ], $write_key_raw, '127.0.0.1', $auth, $reader, $engine);

    ok($r24->getStatus() === 200, '2 回目の PATCH → 200');
    $b24 = $r24->toArray();
    ok($b24['new_rev'] > $pp_before['rev'], 'PATCH 後に rev が増える');
}

// ──────────────────────────────────────────────────
// 25. フル操作フロー: 新規作成 → GET → PATCH → GET
// ──────────────────────────────────────────────────
section('25. フル操作フロー: 新規作成 → GET → PATCH → GET');

$flow_content = "= FlowPage =\n\n段落1行目。\n\n== セクション ==\n段落2行目。\n";

// 新規作成
$rF1 = simulate_put('FlowPage', [
    'base_sha1' => CommitEngine::EMPTY_SHA1,
    'content'   => $flow_content,
], $write_key_raw, '127.0.0.1', $auth, $engine);
ok($rF1->getStatus() === 201, 'FlowPage 新規作成 → 201 Created');

// GET で確認
$rF2 = simulate_get('FlowPage', $write_key_raw, '127.0.0.1', $auth, $reader);
ok($rF2->getStatus() === 200, 'FlowPage GET → 200');
ok($rF2->toArray()['content'] === $flow_content, 'GET で作成した内容が取れる');

// ブロック取得してパッチ準備
$flow_data   = $rF2->toArray();
$flow_blocks = BlockSplitter::split($flow_data['content']);
$flow_para   = array_values(array_filter($flow_blocks, fn($b) => $b['content'] === '段落1行目。'))[0] ?? null;
ok($flow_para !== null, 'FlowPage の段落1ブロックが見つかる');

if ($flow_para) {
    // PATCH で更新
    $rF3 = simulate_patch('FlowPage', [
        'base_sha1' => $flow_data['sha1'],
        'patches'   => [
            ['block_sha1' => $flow_para['block_sha1'], 'new_content' => '段落1更新済み。'],
        ],
    ], $write_key_raw, '127.0.0.1', $auth, $reader, $engine);
    ok($rF3->getStatus() === 200, 'FlowPage PATCH → 200');

    // 最終 GET で確認
    $rF4 = simulate_get('FlowPage', $write_key_raw, '127.0.0.1', $auth, $reader);
    ok($rF4->getStatus() === 200, 'FlowPage 最終 GET → 200');
    $bF4 = $rF4->toArray();
    ok(str_contains($bF4['content'], '段落1更新済み'), '最終 GET で更新内容が確認できる');
    ok(!str_contains($bF4['content'], '段落1行目。'),  '旧コンテンツが消えている');
    ok(str_contains($bF4['content'], '段落2行目'),     '変更しなかった段落は残る');
    ok($bF4['rev'] === $rF3->toArray()['new_rev'],     'GET の rev が PATCH の new_rev と一致');
}

// ──────────────────────────────────────────────────
// 26. PATCH → ロールバック後に旧内容に戻る
// ──────────────────────────────────────────────────
section('26. PATCH → AdminManager::rollback() で旧内容に戻る');

$rb_content = "= RollbackTestPage =\nロールバックテスト初期版。\n";
$rR1 = simulate_put('RollbackTestPage', [
    'base_sha1' => CommitEngine::EMPTY_SHA1,
    'content'   => $rb_content,
], $write_key_raw, '127.0.0.1', $auth, $engine);
ok($rR1->getStatus() === 201, 'ロールバックテストページ作成 → 201 Created');
$rb_rev_v1 = $rR1->toArray()['new_rev'];

// PATCH で更新
$rb_data   = $reader->read('RollbackTestPage');
$rb_blks   = BlockSplitter::split($rb_data['content']);
$rb_para   = array_values(array_filter($rb_blks, fn($b) => $b['type'] === 'paragraph'))[0] ?? null;

if ($rb_para) {
    $rR2 = simulate_patch('RollbackTestPage', [
        'base_sha1' => $rb_data['sha1'],
        'patches'   => [
            ['block_sha1' => $rb_para['block_sha1'], 'new_content' => 'PATCH後の内容。'],
        ],
    ], $write_key_raw, '127.0.0.1', $auth, $reader, $engine);
    ok($rR2->getStatus() === 200, 'PATCH → 200');

    // ロールバック
    $rb_result = $admin_mg->rollback('RollbackTestPage', $rb_rev_v1, 'admin', 'テスト', $now);
    ok(isset($rb_result['new_rev']), 'ロールバック完了');

    // GET で確認
    $rR3 = simulate_get('RollbackTestPage', $write_key_raw, '127.0.0.1', $auth, $reader);
    ok(str_contains($rR3->toArray()['content'], 'ロールバックテスト初期版'), 'ロールバック後に初期内容に戻る');
}

// ──────────────────────────────────────────────────
// 27. 連続 PUT でリビジョンが単調増加する
// ──────────────────────────────────────────────────
section('27. 連続 PUT でリビジョンが単調増加する');

$seq_content = "最初。\n";
$seq_rev_prev = simulate_put('SeqPage', [
    'base_sha1' => CommitEngine::EMPTY_SHA1,
    'content'   => $seq_content,
], $write_key_raw, '127.0.0.1', $auth, $engine)->toArray()['new_rev'];

$revs_seen = [$seq_rev_prev];
for ($i = 1; $i <= 4; $i++) {
    $cur = $reader->read('SeqPage');
    $next_content = "バージョン {$i}。\n";
    $res = simulate_put('SeqPage', [
        'base_sha1' => $cur['sha1'],
        'content'   => $next_content,
    ], $write_key_raw, '127.0.0.1', $auth, $engine)->toArray();
    $revs_seen[] = $res['new_rev'];
}
ok(count($revs_seen) === 5, '5 回の PUT が全て成功');
$is_monotonic = true;
for ($i = 1; $i < count($revs_seen); $i++) {
    if ($revs_seen[$i] <= $revs_seen[$i - 1]) {
        $is_monotonic = false;
        break;
    }
}
ok($is_monotonic, 'リビジョン番号が単調増加する');

// ──────────────────────────────────────────────────
// 28. PUT の meta.source が put_page
// ──────────────────────────────────────────────────
section('28. PUT の meta.source が put_page');

$latest_rev = $ledger->listRevisions('SeqPage', 1)[0];
$lrev_meta  = json_decode($latest_rev['meta'], true);
ok(($lrev_meta['source'] ?? '') === 'put_page', 'meta.source が put_page');

// ──────────────────────────────────────────────────
// 29. PATCH の meta.source が patch_page、patches_count が正しい
// ──────────────────────────────────────────────────
section('29. PATCH の meta.source/patches_count');

$pp_current = $reader->read('PatchPage');
$pp_blks    = BlockSplitter::split($pp_current['content']);
$pp_hd      = array_values(array_filter($pp_blks, fn($b) => $b['type'] === 'heading'))[0] ?? null;

if ($pp_hd) {
    simulate_patch('PatchPage', [
        'base_sha1' => $pp_current['sha1'],
        'patches'   => [
            ['block_sha1' => $pp_hd['block_sha1'], 'new_content' => '= PatchPage Updated ='],
        ],
    ], $write_key_raw, '127.0.0.1', $auth, $reader, $engine);

    $pp_rev = $ledger->listRevisions('PatchPage', 1)[0];
    $pp_meta = json_decode($pp_rev['meta'], true);
    ok(($pp_meta['source']        ?? '') === 'patch_page', 'meta.source が patch_page');
    ok(($pp_meta['patches_count'] ?? 0)  === 1,            'meta.patches_count が 1');
}

// ──────────────────────────────────────────────────
// 30. ロック解放後に別エージェントが PUT できる
// ──────────────────────────────────────────────────
section('30. ロック解放後に別エージェントが PUT できる');

// LockedPage は test 8 で作成済み、ロックは解放済み
$r30 = simulate_put($lock_page, [
    'base_sha1' => sha1($lock_body),
    'content'   => 'ロック解放後に書き込み。',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r30->getStatus() === 200, 'ロック解放後は PUT 成功 → 200');
$r30_get = simulate_get($lock_page, $write_key_raw, '127.0.0.1', $auth, $reader);
ok(str_contains($r30_get->toArray()['content'], 'ロック解放後に書き込み'), 'コンテンツが更新されている');

// ──────────────────────────────────────────────────
// 31. 保護ページ — PUT は 403 page_protected
// ──────────────────────────────────────────────────
section('31. 保護ページ — PUT は 403 page_protected');

$r31a = simulate_put('FrontPage', [
    'base_sha1' => CommitEngine::EMPTY_SHA1,
    'content'   => 'FrontPage を直接書き換えようとする',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r31a->getStatus() === 403, 'FrontPage への PUT → 403');
ok($r31a->toArray()['error']['code'] === 'page_protected', 'error_code が page_protected');

$r31b = simulate_put('MenuBar', [
    'base_sha1' => CommitEngine::EMPTY_SHA1,
    'content'   => 'MenuBar を直接書き換えようとする',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r31b->getStatus() === 403, 'MenuBar への PUT → 403');
ok($r31b->toArray()['error']['code'] === 'page_protected', 'error_code が page_protected');

// 保護されていない通常ページは PUT 可能であることを確認
$r31c = simulate_put('NormalPage', [
    'base_sha1' => CommitEngine::EMPTY_SHA1,
    'content'   => '通常ページ。',
], $write_key_raw, '127.0.0.1', $auth, $engine);

ok($r31c->getStatus() === 201, '非保護ページへの PUT は通る → 201 Created');

// ──────────────────────────────────────────────────
// 32. 保護ページ — PATCH は 403 page_protected
// ──────────────────────────────────────────────────
section('32. 保護ページ — PATCH は 403 page_protected');

// FrontPage 用のファイルを作成（PATCH のため既存ページが必要）
$fp_body = "FrontPageの内容。\n";
$fp_file = $wiki_dir . '/' . strtoupper(bin2hex('FrontPage')) . '.txt';
file_put_contents($fp_file, $fp_body);

$r32 = simulate_patch('FrontPage', [
    'base_sha1' => sha1($fp_body),
    'patches'   => [['block_sha1' => 'abc12345abcd1234', 'new_content' => '内容']],
], $write_key_raw, '127.0.0.1', $auth, $reader, $engine);

ok($r32->getStatus() === 403, 'FrontPage への PATCH → 403');
ok($r32->toArray()['error']['code'] === 'page_protected', 'error_code が page_protected');

// ──────────────────────────────────────────────────
// 33. 保護ページ — draft 経由（POST /pages/{page}/blocks）は可能なことを確認
// ──────────────────────────────────────────────────
section('33. 保護ページ — draft 経由は通る（page_protected チェック対象外）');

// draft の作成には DraftManager を使い、保護チェックは API 層にのみある
// ここでは Ledger::createDraft を直接呼んで draft 経由が可能であることを示す
$draft_key_raw = 'draft-key-' . bin2hex(random_bytes(4));
$ledger->registerApiKey($draft_key_raw, 'draft-tester', 'draft:create', $now);

$fp_data = $reader->read('FrontPage');
$draft_id = $ledger->createDraft(
    'FrontPage',
    $fp_data['sha1'],
    "FrontPage を更新した提案内容。\n",
    'draft-tester',
    $now,
    $now + 3600,
    ['source' => 'test']
);

ok($draft_id > 0, 'FrontPage への draft 作成は成功（draft_id > 0）');
$draft = $ledger->getDraft($draft_id);
ok($draft['page'] === 'FrontPage', 'draft の page が FrontPage');
ok($draft['status'] === 'open',    'draft の status が open');

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
