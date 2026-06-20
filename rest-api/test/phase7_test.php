<?php
/**
 * Phase 7 検証テスト: 監査ログ・自動失効・ロールバック API
 *
 * 確認すること:
 *  1. Ledger::listAudit() — 全件取得
 *  2. Ledger::listAudit() — action フィルタ
 *  3. Ledger::listAudit() — page フィルタ
 *  4. Ledger::listAudit() — actor フィルタ
 *  5. Ledger::listAudit() — since フィルタ
 *  6. Ledger::listAudit() — limit / offset
 *  7. Ledger::expireDrafts() — 期限切れ下書きの失効
 *  8. AdminManager::expireDrafts() — 失効件数・監査ログ記録
 *  9. AdminManager::expireDrafts() — 失効なしの場合は監査ログを書かない
 * 10. AdminManager::softDelete() — 正常ソフト削除
 * 11. AdminManager::softDelete() — ファイルが wiki/ から消える（archive へ移動）
 * 12. AdminManager::softDelete() — pages テーブルで status='deleted'
 * 13. AdminManager::softDelete() — FTS5 インデックスから除去
 * 14. AdminManager::softDelete() — 監査ログに page_soft_deleted が記録
 * 15. AdminManager::softDelete() — 存在しないページは 404
 * 16. AdminManager::softDelete() — 既に削除済みは 409
 * 17. AdminManager::rollback() — 正常ロールバック
 * 18. AdminManager::rollback() — ロールバック後に新しいリビジョンが増える
 * 19. AdminManager::rollback() — ロールバック後にページ内容が旧バージョンに戻る
 * 20. AdminManager::rollback() — 存在しないリビジョンは 404
 * 21. AdminManager::rollback() — 監査ログに page_rolled_back が記録
 * 22. GET /admin/audit シミュレーション
 * 23. GET /admin/audit — admin スコープなしは 401/403
 * 24. GET /admin/audit — action フィルタが効く
 * 25. POST /admin/drafts/expire シミュレーション
 * 26. GET /admin/pages/{page}/revisions シミュレーション
 * 27. POST /admin/pages/{page}/rollback シミュレーション
 * 28. POST /admin/pages/{page}/rollback — target_rev 未指定は 400
 * 29. DELETE /admin/pages/{page} シミュレーション
 * 30. Router — DELETE/PUT/PATCH メソッドが登録可能
 * 31. Ledger::revokeApiKey() — キーの失効
 *
 * 実行: php rest-api/test/phase7_test.php
 * @version v0.1
 */
declare(strict_types=1);

$test_dir = sys_get_temp_dir() . '/pkwk_phase7_' . getmypid();
$wiki_dir = $test_dir . '/wiki';
$db_dir   = $test_dir . '/db';
$blob_dir = $test_dir . '/blobs';

foreach ([$wiki_dir, $db_dir, $blob_dir] as $d) {
    mkdir($d, 0755, true);
}

// CommitEngine テスト用の page_write() モック（まだロードされていない場合のみ定義）
if (!function_exists('page_write')) {
    function page_write(string $page, string $body): void
    {
        // ファイルに書く（AtomicWriter と同様の効果）
        $wiki_dir = $GLOBALS['_TEST_WIKI_DIR'] ?? '';
        if ($wiki_dir !== '') {
            $file = $wiki_dir . '/' . strtoupper(bin2hex($page)) . '.txt';
            file_put_contents($file, $body);
        }
        $GLOBALS['_page_write_calls'][] = ['page' => $page, 'body' => $body];
    }
}
if (!function_exists('is_freeze')) {
    function is_freeze(string $page): bool { return false; }
}
if (!function_exists('is_editable')) {
    function is_editable(string $page): bool { return true; }
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
require_once $REST_DIR . '/lib/AdminManager.php';
require_once $REST_DIR . '/lib/Response.php';
require_once $REST_DIR . '/lib/Router.php';

$GLOBALS['_TEST_WIKI_DIR']    = $wiki_dir;
$GLOBALS['_page_write_calls'] = [];

$schema   = $REST_DIR . '/schema/init.sql';
$db_path  = $db_dir . '/ledger.sqlite';
$ledger   = Ledger::open($db_path, $schema);
$store    = new RevisionStore($blob_dir);
$rec      = new Reconciler($ledger, $store, $wiki_dir);
$reader   = new PageReader($rec, $ledger, $wiki_dir);
$engine   = new CommitEngine($ledger, $store, $wiki_dir);
$admin_mg = new AdminManager($ledger, $store, $engine, $wiki_dir);

$auth = new Auth($ledger);
$now  = time();

// API キー登録（page:read, draft:create, admin スコープ）
$admin_key_raw = 'admin-test-key-' . bin2hex(random_bytes(4));
$normal_key_raw = 'normal-test-key-' . bin2hex(random_bytes(4));
$ledger->registerApiKey($admin_key_raw,  'admin-tester',  'page:read draft:create admin', $now);
$ledger->registerApiKey($normal_key_raw, 'normal-tester', 'page:read draft:create',       $now);

// テスト用ウィキページを初期化
$pages_data = [
    'TestPage'    => "= TestPage =\nオリジナル版のコンテンツです。\n",
    'DeletePage'  => "= DeletePage =\n削除されるページ。\n",
    'RollbackPage'=> "= RollbackPage =\n最初のバージョン。\n",
];

foreach ($pages_data as $name => $body) {
    $file = $wiki_dir . '/' . strtoupper(bin2hex($name)) . '.txt';
    file_put_contents($file, $body);
}
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
// 1-6. Ledger::listAudit()
// ──────────────────────────────────────────────────
section('1. Ledger::listAudit() — 全件取得');

// 監査ログを数件生成
$ledger->log('test_action_a', 'TestPage',  'actor1', ['detail' => 'first']);
$ledger->log('test_action_b', 'TestPage',  'actor2', ['detail' => 'second']);
$ledger->log('test_action_a', 'OtherPage', 'actor1', ['detail' => 'third']);
$ledger->log('test_action_c', null,        'actor3', ['detail' => 'fourth']);

$all = $ledger->listAudit(null, null, null, 0, 100, 0);
ok(count($all) >= 4, '4 件以上の監査ログが取れる');
ok(isset($all[0]['action']),    'action フィールドがある');
ok(isset($all[0]['logged_at']), 'logged_at フィールドがある');
ok(isset($all[0]['detail']),    'detail フィールドがある');

section('2. Ledger::listAudit() — action フィルタ');

$by_action = $ledger->listAudit(null, 'test_action_a', null, 0, 100, 0);
ok(count($by_action) >= 2, 'test_action_a が 2 件以上');
ok(array_filter($by_action, fn($r) => $r['action'] !== 'test_action_a') === [],
   'フィルタ外のアクションが含まれない');

section('3. Ledger::listAudit() — page フィルタ');

$by_page = $ledger->listAudit('TestPage', null, null, 0, 100, 0);
ok(count($by_page) >= 2, 'TestPage のログが 2 件以上');
ok(array_filter($by_page, fn($r) => $r['page'] !== 'TestPage') === [],
   'TestPage 以外のページが含まれない');

section('4. Ledger::listAudit() — actor フィルタ');

$by_actor = $ledger->listAudit(null, null, 'actor2', 0, 100, 0);
ok(count($by_actor) >= 1, 'actor2 のログが 1 件以上');
ok(array_filter($by_actor, fn($r) => $r['actor'] !== 'actor2') === [],
   'actor2 以外の actor が含まれない');

section('5. Ledger::listAudit() — since フィルタ');

$future = time() + 10000;
$by_since = $ledger->listAudit(null, null, null, $future, 100, 0);
ok(count($by_since) === 0, '未来の since では 0 件');

$past = time() - 1;
$by_recent = $ledger->listAudit(null, null, null, $past, 100, 0);
ok(count($by_recent) >= 4, '過去の since では全件取れる');

section('6. Ledger::listAudit() — limit / offset');

$limited = $ledger->listAudit(null, null, null, 0, 2, 0);
ok(count($limited) === 2, 'limit=2 で 2 件');

$offset1 = $ledger->listAudit(null, null, null, 0, 100, 1);
$all_ids  = array_column($all, 'id');
$off_ids  = array_column($offset1, 'id');
ok(!empty($off_ids),                         'offset=1 で結果が空でない');
ok($off_ids[0] !== $all_ids[0],              'offset=1 で最初のレコードがスキップされる');

// ──────────────────────────────────────────────────
// 7-8. Ledger::expireDrafts() / AdminManager::expireDrafts()
// ──────────────────────────────────────────────────
section('7. Ledger::expireDrafts()');

// 期限切れ下書きを作成
$past_expires   = $now - 3600;
$future_expires = $now + 7 * 24 * 3600;
$tp_data = $reader->read('TestPage');

$expired_draft_id = $ledger->createDraft(
    'TestPage', $tp_data['sha1'], '期限切れの下書き内容。',
    'test-owner', $now - 7200, $past_expires,
    ['test' => true]
);
$active_draft_id = $ledger->createDraft(
    'TestPage', $tp_data['sha1'], '有効な下書き内容。',
    'test-owner', $now, $future_expires,
    ['test' => true]
);

$expired_count = $ledger->expireDrafts($now);
ok($expired_count >= 1, '少なくとも 1 件が失効');

$expired_draft = $ledger->getDraft($expired_draft_id);
ok($expired_draft['status'] === 'expired', '期限切れ下書きが expired になる');

$active_draft = $ledger->getDraft($active_draft_id);
ok($active_draft['status'] === 'open', '有効な下書きは open のまま');

section('8. AdminManager::expireDrafts() — 失効件数・監査ログ記録');

// 新しい期限切れ下書きを追加してテスト
$another_past = $ledger->createDraft(
    'TestPage', $tp_data['sha1'], '別の期限切れ。',
    'test-owner', $now - 7200, $past_expires - 1,
    ['test' => true]
);

$before_count = count($ledger->listAudit(null, 'drafts_expired', null, 0, 100, 0));
$cnt = $admin_mg->expireDrafts('admin-tester', $now);
$after_count  = count($ledger->listAudit(null, 'drafts_expired', null, 0, 100, 0));

ok($cnt >= 1, 'AdminManager::expireDrafts() が失効件数を返す');
ok($after_count > $before_count, '監査ログに drafts_expired が記録される');

section('9. AdminManager::expireDrafts() — 失効なしの場合は監査ログを書かない');

// もう失効させる下書きがない状態でテスト
$before_no_expire = count($ledger->listAudit(null, 'drafts_expired', null, 0, 100, 0));
$zero_cnt = $admin_mg->expireDrafts('admin-tester', $now);
$after_no_expire  = count($ledger->listAudit(null, 'drafts_expired', null, 0, 100, 0));

ok($zero_cnt === 0, '失効なしで 0 を返す');
ok($after_no_expire === $before_no_expire, '失効なしの場合は監査ログを書かない');

// ──────────────────────────────────────────────────
// 10-16. AdminManager::softDelete()
// ──────────────────────────────────────────────────
section('10. AdminManager::softDelete() — 正常ソフト削除');

$del_result = $admin_mg->softDelete('DeletePage', 'admin-tester', $now);
ok($del_result['status'] === 'deleted', 'status が deleted');
ok($del_result['page'] === 'DeletePage', 'page が DeletePage');
ok(isset($del_result['deleted_at']), 'deleted_at がある');

section('11. AdminManager::softDelete() — ファイルが wiki/ から消える');

$del_file = $wiki_dir . '/' . strtoupper(bin2hex('DeletePage')) . '.txt';
ok(!file_exists($del_file), 'wiki ファイルが wiki/ から消えている');
ok(isset($del_result['archived_to']), 'archived_to が設定されている');
ok($del_result['archived_to'] !== null, 'archived_to が null でない');
ok(file_exists($del_result['archived_to']), 'アーカイブファイルが存在する');

section('12. AdminManager::softDelete() — pages テーブルで status=deleted');

$del_page_rec = $ledger->getPage('DeletePage');
ok($del_page_rec !== null, 'pages テーブルにレコードがある');
ok(($del_page_rec['status'] ?? '') === 'deleted', 'status が deleted になっている');

section('13. AdminManager::softDelete() — FTS5 インデックスから除去');

// FTS5 で検索して DeletePage が見つからないことを確認
try {
    $search_results = $ledger->search('削除されるページ', 10);
    $found_names = array_column($search_results, 'name');
    ok(!in_array('DeletePage', $found_names, true), 'FTS5 から DeletePage が除去されている');
} catch (\Throwable $e) {
    ok(false, 'FTS5 検索でエラー: ' . $e->getMessage());
}

section('14. AdminManager::softDelete() — 監査ログに page_soft_deleted が記録');

$del_audit = $ledger->listAudit('DeletePage', 'page_soft_deleted', null, 0, 10, 0);
ok(!empty($del_audit), '監査ログに page_soft_deleted が記録されている');
$del_detail = json_decode($del_audit[0]['detail'], true);
ok(isset($del_detail['archived_to']), '監査ログに archived_to が含まれる');
ok($del_detail['deleted_at'] === $now, '監査ログに deleted_at が正しい');

section('15. AdminManager::softDelete() — 存在しないページは 404');

try {
    $admin_mg->softDelete('NoSuchPageXYZ', 'admin-tester', $now);
    ok(false, '存在しないページは ApiException を投げる');
} catch (ApiException $e) {
    ok($e->status === 404, '404 が返る');
    ok($e->error_code === 'page_not_found', 'error_code が page_not_found');
}

section('16. AdminManager::softDelete() — 既に削除済みは 409');

try {
    $admin_mg->softDelete('DeletePage', 'admin-tester', $now);
    ok(false, '既に削除済みは ApiException を投げる');
} catch (ApiException $e) {
    ok($e->status === 409, '409 が返る');
    ok($e->error_code === 'already_deleted', 'error_code が already_deleted');
}

// ──────────────────────────────────────────────────
// 17-21. AdminManager::rollback()
// ──────────────────────────────────────────────────
section('17. AdminManager::rollback() — 正常ロールバック');

// RollbackPage を複数回更新してリビジョン履歴を作る
$rb_data = $reader->read('RollbackPage');
$rb_sha1_v1 = $rb_data['sha1'];

// v2 へ更新
$v2_body = "= RollbackPage =\n第2バージョン。更新されました。\n";
$rb_v2 = $engine->commit('RollbackPage', $v2_body, $rb_sha1_v1, 'tester', ['msg' => 'v2']);
$rb_rev_v2  = $rb_v2['new_rev'];

// v3 へ更新
$rb_data_v2  = $reader->read('RollbackPage');
$v3_body = "= RollbackPage =\n第3バージョン。最新です。\n";
$rb_v3 = $engine->commit('RollbackPage', $v3_body, $rb_data_v2['sha1'], 'tester', ['msg' => 'v3']);
$rb_rev_v3  = $rb_v3['new_rev'];

// 現在の状態確認
$rb_current = $reader->read('RollbackPage');
ok(str_contains($rb_current['content'], '第3バージョン'), '現在が v3');
ok($rb_current['rev'] === $rb_rev_v3, '現在リビジョンが v3');

// v1 にロールバック（最初のリビジョンは rev=1 のはず）
$rb_revisions = $ledger->listRevisions('RollbackPage', 10);
$rb_rev_v1    = (int)$rb_revisions[count($rb_revisions) - 1]['rev']; // 最古のリビジョン番号

$rb_result = $admin_mg->rollback('RollbackPage', $rb_rev_v1, 'admin-tester', 'テスト用ロールバック', $now);

ok(isset($rb_result['new_rev']),     'new_rev が返る');
ok(isset($rb_result['new_sha1']),    'new_sha1 が返る');
ok(isset($rb_result['target_rev']),  'target_rev が返る');
ok($rb_result['target_rev'] === $rb_rev_v1, 'target_rev が正しい');

section('18. AdminManager::rollback() — ロールバック後に新しいリビジョンが増える');

$rb_after  = $reader->read('RollbackPage');
ok($rb_after['rev'] > $rb_rev_v3, 'ロールバック後にリビジョンが増える');

section('19. AdminManager::rollback() — ロールバック後にページ内容が旧バージョンに戻る');

ok(str_contains($rb_after['content'], '最初のバージョン'), 'コンテンツが v1 の内容に戻る');
ok(!str_contains($rb_after['content'], '第3バージョン'),   'v3 のコンテンツが消える');

section('20. AdminManager::rollback() — 存在しないリビジョンは 404');

try {
    $admin_mg->rollback('RollbackPage', 99999, 'admin-tester', 'テスト', $now);
    ok(false, '存在しないリビジョンは ApiException を投げる');
} catch (ApiException $e) {
    ok($e->status === 404, '404 が返る');
    ok($e->error_code === 'revision_not_found', 'error_code が revision_not_found');
}

section('21. AdminManager::rollback() — 監査ログに page_rolled_back が記録');

$rb_audit = $ledger->listAudit('RollbackPage', 'page_rolled_back', null, 0, 10, 0);
ok(!empty($rb_audit), '監査ログに page_rolled_back が記録されている');
$rb_detail = json_decode($rb_audit[0]['detail'], true);
ok(isset($rb_detail['to_rev']),    '監査ログに to_rev がある');
ok(isset($rb_detail['new_rev']),   '監査ログに new_rev がある');
ok(($rb_detail['to_rev'] ?? 0) === $rb_rev_v1, 'to_rev が正しい');

// ──────────────────────────────────────────────────
// API エンドポイントシミュレーション（22-30）
// ──────────────────────────────────────────────────

function admin_request(
    string $method, string $path, ?array $body,
    string $raw_key, string $ip,
    Auth $auth, Ledger $ledger, AdminManager $admin_mg, PageReader $reader
): Response {
    try {
        $key     = $auth->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"], 'admin', $ip);
        $actor   = (string)($key['label'] ?? 'admin');
        $now     = time();

        return match ($method . ':' . $path) {
            // GET /admin/audit
            'GET:/admin/audit' => (function () use ($ledger, $body): Response {
                $rows = $ledger->listAudit(
                    $body['page']   ?? null,
                    $body['action'] ?? null,
                    null, 0, 100, 0
                );
                return Response::ok(['audit' => $rows, 'count' => count($rows)]);
            })(),
            // POST /admin/drafts/expire
            'POST:/admin/drafts/expire' => (function () use ($admin_mg, $actor, $now): Response {
                $count = $admin_mg->expireDrafts($actor, $now);
                return Response::ok(['expired' => $count, 'expired_at' => $now]);
            })(),
            default => throw new ApiException(404, "No route: {$method} {$path}"),
        };
    } catch (ApiException $e) {
        // admin スコープチェックが失敗した場合を含む
        return Response::fromException($e);
    }
}

function simulate_admin_get_revisions(
    string $page, string $raw_key, Auth $auth, Ledger $ledger
): Response {
    try {
        $auth->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"], 'admin', '1.2.3.4');
        $revs = $ledger->listRevisions($page, 50);
        return Response::ok(['page' => $page, 'revisions' => $revs, 'count' => count($revs)]);
    } catch (\Throwable $e) {
        return Response::fromException($e);
    }
}

function simulate_admin_rollback(
    string $page, array $body, string $raw_key, Auth $auth, AdminManager $admin_mg
): Response {
    try {
        $key    = $auth->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"], 'admin', '1.2.3.4');
        $actor  = (string)($key['label'] ?? 'admin');
        $target = (int)($body['target_rev'] ?? 0);
        $reason = trim((string)($body['reason'] ?? ''));
        $now    = time();

        if ($target <= 0) {
            throw new ApiException(400, '"target_rev" is required', 'missing_target_rev');
        }
        $result = $admin_mg->rollback($page, $target, $actor, $reason, $now);
        return Response::ok(array_merge($result, ['page' => $page]));
    } catch (\Throwable $e) {
        return Response::fromException($e);
    }
}

function simulate_admin_soft_delete(
    string $page, string $raw_key, Auth $auth, AdminManager $admin_mg
): Response {
    try {
        $key   = $auth->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"], 'admin', '1.2.3.4');
        $actor = (string)($key['label'] ?? 'admin');
        $result = $admin_mg->softDelete($page, $actor, time());
        return Response::ok($result);
    } catch (\Throwable $e) {
        return Response::fromException($e);
    }
}

section('22. GET /admin/audit シミュレーション');

$r22 = admin_request('GET', '/admin/audit', [], $admin_key_raw, '1.2.3.4', $auth, $ledger, $admin_mg, $reader);
ok($r22->getStatus() === 200, 'GET /admin/audit → 200');
$b22 = $r22->toArray();
ok(isset($b22['audit']),   'audit フィールドがある');
ok(isset($b22['count']),   'count フィールドがある');
ok(is_array($b22['audit']), 'audit が配列');

section('23. GET /admin/audit — admin スコープなしは 401/403');

$r23 = admin_request('GET', '/admin/audit', [], $normal_key_raw, '1.2.3.4', $auth, $ledger, $admin_mg, $reader);
ok(in_array($r23->getStatus(), [401, 403], true), 'admin スコープなしは 401/403');

section('24. GET /admin/audit — action フィルタが効く');

$r24 = admin_request('GET', '/admin/audit', ['action' => 'page_rolled_back'], $admin_key_raw, '1.2.3.4', $auth, $ledger, $admin_mg, $reader);
ok($r24->getStatus() === 200, 'action フィルタの GET /admin/audit → 200');
$b24 = $r24->toArray();
foreach ($b24['audit'] as $entry) {
    ok($entry['action'] === 'page_rolled_back', '全エントリが page_rolled_back');
    break; // 先頭だけ確認
}
ok(count($b24['audit']) >= 1, 'page_rolled_back が 1 件以上');

section('25. POST /admin/drafts/expire シミュレーション');

$r25 = admin_request('POST', '/admin/drafts/expire', null, $admin_key_raw, '1.2.3.4', $auth, $ledger, $admin_mg, $reader);
ok($r25->getStatus() === 200, 'POST /admin/drafts/expire → 200');
$b25 = $r25->toArray();
ok(isset($b25['expired']),    'expired フィールドがある');
ok(isset($b25['expired_at']), 'expired_at フィールドがある');
ok(is_int($b25['expired']),   'expired が整数');

section('26. GET /admin/pages/{page}/revisions シミュレーション');

$r26 = simulate_admin_get_revisions('RollbackPage', $admin_key_raw, $auth, $ledger);
ok($r26->getStatus() === 200, 'GET /admin/pages/RollbackPage/revisions → 200');
$b26 = $r26->toArray();
ok(isset($b26['page']),       'page フィールドがある');
ok(isset($b26['revisions']),  'revisions フィールドがある');
ok(isset($b26['count']),      'count フィールドがある');
ok($b26['count'] >= 3, 'RollbackPage に 3 件以上のリビジョン');

foreach ($b26['revisions'] as $rev_row) {
    ok(isset($rev_row['rev']),          'rev フィールドがある');
    ok(isset($rev_row['content_sha1']), 'content_sha1 フィールドがある');
    ok(isset($rev_row['actor']),        'actor フィールドがある');
    break;
}

section('27. POST /admin/pages/{page}/rollback シミュレーション');

// 現在の RollbackPage を再度更新して別のリビジョンへ
$rb_latest = $reader->read('RollbackPage');
$v4_body   = "= RollbackPage =\nv4 テスト更新。\n";
$rb_v4     = $engine->commit('RollbackPage', $v4_body, $rb_latest['sha1'], 'tester', ['msg' => 'v4']);

// 最新の revisions から v1 のリビジョン番号を取得
$rb_all_revs = $ledger->listRevisions('RollbackPage', 20);
$rb_oldest   = end($rb_all_revs);
$rb_oldest_rev = (int)$rb_oldest['rev'];

$r27 = simulate_admin_rollback(
    'RollbackPage',
    ['target_rev' => $rb_oldest_rev, 'reason' => 'API テスト用ロールバック'],
    $admin_key_raw, $auth, $admin_mg
);

ok($r27->getStatus() === 200, 'POST /admin/pages/{page}/rollback → 200');
$b27 = $r27->toArray();
ok(isset($b27['new_rev']),    'new_rev が返る');
ok(isset($b27['new_sha1']),   'new_sha1 が返る');
ok(isset($b27['target_rev']), 'target_rev が返る');

section('28. POST /admin/pages/{page}/rollback — target_rev 未指定は 400');

$r28 = simulate_admin_rollback('RollbackPage', [], $admin_key_raw, $auth, $admin_mg);
ok($r28->getStatus() === 400, 'target_rev 未指定は 400');
ok($r28->toArray()['error']['code'] === 'missing_target_rev', 'error_code が missing_target_rev');

section('29. DELETE /admin/pages/{page} シミュレーション');

// TestPage をソフト削除
$r29 = simulate_admin_soft_delete('TestPage', $admin_key_raw, $auth, $admin_mg);
ok($r29->getStatus() === 200, 'DELETE /admin/pages/TestPage → 200');
$b29 = $r29->toArray();
ok($b29['status'] === 'deleted',    'status が deleted');
ok($b29['page']   === 'TestPage',   'page が TestPage');
ok(isset($b29['archived_to']),      'archived_to がある');

// 認証なしは拒否
$r29b = simulate_admin_soft_delete('TestPage', $normal_key_raw, $auth, $admin_mg);
ok(in_array($r29b->getStatus(), [401, 403], true), 'admin スコープなしは拒否');

// ──────────────────────────────────────────────────
// 30. Router — DELETE/PUT/PATCH メソッドが登録可能
// ──────────────────────────────────────────────────
section('30. Router — DELETE/PUT/PATCH メソッドが登録可能');

$router = new Router();
$called = [];
$router->delete('/test/{id}', function ($vars) use (&$called): Response {
    $called[] = 'DELETE:' . $vars['id'];
    return Response::ok(['deleted' => $vars['id']]);
});
$router->put('/test/{id}', function ($vars) use (&$called): Response {
    $called[] = 'PUT:' . $vars['id'];
    return Response::ok(['updated' => $vars['id']]);
});
$router->patch('/test/{id}', function ($vars) use (&$called): Response {
    $called[] = 'PATCH:' . $vars['id'];
    return Response::ok(['patched' => $vars['id']]);
});

$del_resp = $router->dispatch('DELETE', '/test/42');
ok($del_resp->getStatus() === 200, 'DELETE /test/42 → 200');
ok(in_array('DELETE:42', $called, true), 'DELETE ハンドラが呼ばれる');

$put_resp = $router->dispatch('PUT', '/test/42');
ok($put_resp->getStatus() === 200, 'PUT /test/42 → 200');
ok(in_array('PUT:42', $called, true), 'PUT ハンドラが呼ばれる');

$patch_resp = $router->dispatch('PATCH', '/test/42');
ok($patch_resp->getStatus() === 200, 'PATCH /test/42 → 200');
ok(in_array('PATCH:42', $called, true), 'PATCH ハンドラが呼ばれる');

// 登録されていないメソッドは 405
try {
    $router->dispatch('POST', '/test/42');
    ok(false, 'POST /test/42 は 405 になる');
} catch (ApiException $e) {
    ok($e->status === 405, 'POST /test/42 → 405 Method Not Allowed');
}

// ──────────────────────────────────────────────────
// 31. Ledger::revokeApiKey()
// ──────────────────────────────────────────────────
section('31. Ledger::revokeApiKey()');

$revoke_key_raw = 'revoke-test-key-' . bin2hex(random_bytes(4));
$revoke_key_id  = $ledger->registerApiKey($revoke_key_raw, 'revoke-tester', 'page:read', $now);

// 失効前は認証可能
$auth_before = $ledger->authenticateKey($revoke_key_raw, $now);
ok($auth_before !== null, '失効前は認証可能');

// キーを失効させる
$revoked = $ledger->revokeApiKey($revoke_key_id, $now);
ok($revoked === true, 'revokeApiKey() が true を返す');

// 失効後は認証不可
$auth_after = $ledger->authenticateKey($revoke_key_raw, $now);
ok($auth_after === null, '失効後は認証不可');

// 存在しない ID は false
$not_found = $ledger->revokeApiKey(99999, $now);
ok($not_found === false, '存在しない ID は false を返す');

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
