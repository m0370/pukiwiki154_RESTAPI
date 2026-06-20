<?php
/**
 * Phase 4 検証テスト: 下書き/提案ストア＋差分プレビュー＋人間承認
 *
 * 確認すること:
 *  1. DiffEngine::unified() — 追加・削除・差分なし
 *  2. DiffEngine::stats() — 行数集計
 *  3. DiffEngine::toHtml() — HTML 変換（XSS 対策確認）
 *  4. Ledger::listDrafts() — フィルタリング・ページネーション
 *  5. DraftManager::getWithDiff() — 差分プレビュー生成
 *  6. DraftManager::approve() — 承認・公開（ファイル書き込み＋台帳更新）
 *  7. DraftManager::approve() — sha1 競合で 409
 *  8. DraftManager::approve() — 既に処理済みで 409
 *  9. DraftManager::reject() — 却下
 * 10. DraftManager::reject() — 既に処理済みで 409
 * 11. Ledger::expireDrafts() — 期限切れ下書きの自動処理
 * 12. DraftManager::approve() — 期限切れ下書きは 410
 * 13. GET /drafts エンドポイントのシミュレーション
 * 14. GET /drafts/{id} エンドポイントのシミュレーション
 * 15. POST /drafts/{id}/approve エンドポイントのシミュレーション
 * 16. POST /drafts/{id}/reject エンドポイントのシミュレーション
 * 17. 承認後にページが page_index に反映される（検索可能）
 *
 * 実行: php rest-api/test/phase4_test.php
 * @version v0.1
 */
declare(strict_types=1);

$test_dir = sys_get_temp_dir() . '/pkwk_phase4_' . getmypid();
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
require_once $REST_DIR . '/lib/Response.php';
require_once $REST_DIR . '/lib/Router.php';

$schema   = $REST_DIR . '/schema/init.sql';
$db_path  = $db_dir   . '/ledger.sqlite';
$ledger   = Ledger::open($db_path, $schema);
$store    = new RevisionStore($blob_dir);
$rec      = new Reconciler($ledger, $store, $wiki_dir);
$reader   = new PageReader($rec, $ledger, $wiki_dir);
$engine   = new CommitEngine($ledger, $store, $wiki_dir);
$draftMgr = new DraftManager($ledger, $engine, $wiki_dir);

// テスト用 wiki ファイルを作成
$pages = [
    'FrontPage' => "= FrontPage =\nWiki のトップページです。\n",
    'Help'      => "= Help =\nヘルプページです。使い方を説明します。\n",
];
foreach ($pages as $name => $content) {
    $encoded = strtoupper(bin2hex($name));
    file_put_contents($wiki_dir . '/' . $encoded . '.txt', $content);
}
$rec->buildIndex();

// API キー登録
$now     = time();
$raw_key = 'phase4-test-key-' . bin2hex(random_bytes(4));
$ledger->registerApiKey($raw_key, 'phase4-tester', 'page:read draft:create draft:approve', $now);
$auth = new Auth($ledger);

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

// -------------------------------------------------------------------------
// 1. DiffEngine::unified()
// -------------------------------------------------------------------------
section('1. DiffEngine::unified()');

$diff_same = DiffEngine::unified("abc\n", "abc\n");
ok($diff_same === '', '同じ内容は空文字列');

$old = "行1\n行2\n行3\n";
$new = "行1\n行2改\n行3\n行4\n";
$diff = DiffEngine::unified($old, $new, 'old', 'new');
ok($diff !== '', '差分がある場合は非空');
ok(str_contains($diff, '---') || str_contains($diff, '+++'), 'ヘッダ行が含まれる');
ok(str_contains($diff, '+') || str_contains($diff, '-'), '+/- 記号が含まれる');

// 追加のみ
$add_only = DiffEngine::unified("行1\n", "行1\n行2\n", 'a', 'b');
ok(str_contains($add_only, '+'), '追加行に + が含まれる');

// 削除のみ
$del_only = DiffEngine::unified("行1\n行2\n", "行1\n", 'a', 'b');
ok(str_contains($del_only, '-行2'), '削除行に -行2 が含まれる');

// 英語 diff
$en_diff = DiffEngine::unified("hello world\n", "hello PHP\n", 'a', 'b');
ok(str_contains($en_diff, '+hello PHP'), '英語差分が正しく生成される');

// -------------------------------------------------------------------------
// 2. DiffEngine::stats()
// -------------------------------------------------------------------------
section('2. DiffEngine::stats()');

$stats1 = DiffEngine::stats('');
ok($stats1['added'] === 0 && $stats1['removed'] === 0, '空 diff は 0/0');
ok($stats1['has_diff'] === false, '空 diff は has_diff=false');

// 追加2行・削除1行のシミュレート
$sample = "--- a\n+++ b\n@@ -1 +1 @@\n-old line\n+new line 1\n+new line 2\n";
$stats2 = DiffEngine::stats($sample);
ok($stats2['added'] === 2, '追加行数が 2');
ok($stats2['removed'] === 1, '削除行数が 1');
ok($stats2['has_diff'] === true, 'has_diff が true');

// 実際の diff から stats を取得
$real_stats = DiffEngine::stats($diff);
ok($real_stats['has_diff'] === true, '実際の diff の has_diff が true');
ok($real_stats['added'] >= 1, '追加行数 >= 1');

// -------------------------------------------------------------------------
// 3. DiffEngine::toHtml()
// -------------------------------------------------------------------------
section('3. DiffEngine::toHtml()');

$html = DiffEngine::toHtml('');
ok(str_contains($html, 'diff-none'), '空 diff は diff-none クラス');

$html2 = DiffEngine::toHtml($sample);
ok(str_contains($html2, '<pre class="diff">'), '<pre class="diff"> タグがある');
ok(str_contains($html2, 'diff-added'), '追加行に diff-added クラス');
ok(str_contains($html2, 'diff-removed'), '削除行に diff-removed クラス');
ok(str_contains($html2, 'diff-header'), 'ヘッダに diff-header クラス');
ok(str_contains($html2, 'diff-hunk'), 'ハンクに diff-hunk クラス');

// XSS 対策: < > & が実体参照になっているか
$xss_diff = "--- a\n+++ b\n@@ @@\n+<script>alert('xss')</script>\n";
$xss_html = DiffEngine::toHtml($xss_diff);
ok(!str_contains($xss_html, '<script>'), '<script> タグが HTML 出力に含まれない');
ok(str_contains($xss_html, '&lt;script&gt;'), 'エスケープされた &lt;script&gt; が含まれる');

// -------------------------------------------------------------------------
// 4. Ledger::listDrafts()
// -------------------------------------------------------------------------
section('4. Ledger::listDrafts()');

// 下書きを複数作成
$fp_sha1  = sha1($pages['FrontPage']);
$hlp_sha1 = sha1($pages['Help']);

$d1 = $ledger->createDraft('FrontPage', $fp_sha1, "= FrontPage =\nAI 提案版 1。\n", 'ai-bot', $now, $now + 3600);
$d2 = $ledger->createDraft('FrontPage', $fp_sha1, "= FrontPage =\nAI 提案版 2。\n", 'ai-bot', $now + 1, $now + 3600);
$d3 = $ledger->createDraft('Help', $hlp_sha1, "= Help =\nAI 提案版。\n", 'another-bot', $now + 2, $now + 3600);

// フィルタなし
$all = $ledger->listDrafts();
ok(count($all) >= 3, 'フィルタなしで 3 件以上取得');

// page フィルタ
$fp_drafts = $ledger->listDrafts('FrontPage');
ok(count($fp_drafts) === 2, 'FrontPage の下書きが 2 件');
ok(array_column($fp_drafts, 'page') === ['FrontPage', 'FrontPage'], '全て FrontPage');

// status フィルタ
$open_drafts = $ledger->listDrafts(null, 'open');
ok(count($open_drafts) >= 3, 'open 状態の下書きが 3 件以上');

// owner フィルタ
$bot_drafts = $ledger->listDrafts(null, null, 'ai-bot');
ok(count($bot_drafts) === 2, 'ai-bot オーナーが 2 件');

// limit/offset
$limited = $ledger->listDrafts(null, null, null, 2, 0);
ok(count($limited) === 2, 'limit=2 で 2 件取得');

$offset1 = $ledger->listDrafts(null, null, null, 100, 1);
ok(count($offset1) >= 2, 'offset=1 で少なくとも 2 件取得');

// body は含まれないことを確認（一覧は軽量に）
ok(!array_key_exists('body', $fp_drafts[0]), 'listDrafts 結果に body フィールドがない');

// -------------------------------------------------------------------------
// 5. DraftManager::getWithDiff()
// -------------------------------------------------------------------------
section('5. DraftManager::getWithDiff()');

$detail = $draftMgr->getWithDiff($d1);
ok(isset($detail['draft']), 'draft フィールドが存在する');
ok(isset($detail['diff']), 'diff フィールドが存在する');
ok(isset($detail['diff_stats']), 'diff_stats フィールドが存在する');
ok(isset($detail['diff_html']), 'diff_html フィールドが存在する');
ok(isset($detail['current_sha1']), 'current_sha1 が存在する');
ok(isset($detail['is_conflict']), 'is_conflict が存在する');
ok($detail['draft']['id'] === $d1, 'draft.id が正しい');
ok($detail['is_conflict'] === false, 'sha1 一致時は is_conflict = false');

// 存在しない ID
try {
    $draftMgr->getWithDiff(99999);
    ok(false, '存在しない ID は ApiException を投げる');
} catch (ApiException $e) {
    ok($e->status === 404, '存在しない ID は 404');
}

// -------------------------------------------------------------------------
// 6. DraftManager::approve() — 正常承認
// -------------------------------------------------------------------------
section('6. DraftManager::approve() — 正常承認');

$result = $draftMgr->approve($d1, 'human-reviewer', $now);
ok(isset($result['new_rev']), 'approve() が new_rev を返す');
ok(isset($result['new_sha1']), 'approve() が new_sha1 を返す');
ok($result['new_rev'] >= 1, 'new_rev >= 1');

// ファイルが更新されているか
$fp_file    = $wiki_dir . '/' . strtoupper(bin2hex('FrontPage')) . '.txt';
$fp_content = file_get_contents($fp_file);
ok(str_contains((string)$fp_content, 'AI 提案版 1'), '承認後ファイルが更新される');

// DB の draft.status が published になっているか
$approved_draft = $ledger->getDraft($d1);
ok($approved_draft['status'] === 'published', 'draft.status が published');

// page_index が更新されているか
$page_rec = $ledger->getPage('FrontPage');
ok($page_rec !== null, 'pages テーブルにページが存在する');
ok($page_rec['current_rev'] === $result['new_rev'], 'pages.current_rev が一致する');

// revisions に追記されているか
$revs = $ledger->listRevisions('FrontPage');
ok(!empty($revs), 'revisions に記録がある');
ok($revs[0]['actor'] === 'human-reviewer', 'revisions.actor が human-reviewer');

// page_index に反映されているか（検索でヒット）
$search = $ledger->search('提案版');
$names  = array_column($search, 'name');
ok(in_array('FrontPage', $names, true), '承認後に FTS5 で FrontPage が検索できる');

// -------------------------------------------------------------------------
// 7. DraftManager::approve() — sha1 競合で 409
// -------------------------------------------------------------------------
section('7. DraftManager::approve() — sha1 競合');

// ファイルを外部から直接書き換えて競合状態を作る
file_put_contents($fp_file, "= FrontPage =\n外部から直接書き換えられた内容。\n");

try {
    $draftMgr->approve($d2, 'human-reviewer', $now);
    ok(false, 'sha1 競合は ApiException を投げる');
} catch (ApiException $e) {
    ok($e->status === 409, '競合は 409');
    ok(str_contains($e->getMessage(), 'Conflict') || str_contains($e->getMessage(), 'sha1'), 'メッセージに Conflict/sha1 が含まれる');
}

// ファイルを元に戻す
file_put_contents($fp_file, $pages['FrontPage']);

// -------------------------------------------------------------------------
// 8. DraftManager::approve() — 既に処理済みで 409
// -------------------------------------------------------------------------
section('8. DraftManager::approve() — 既に処理済み');

try {
    $draftMgr->approve($d1, 'human-reviewer', $now); // d1 はすでに published
    ok(false, '処理済み下書きは ApiException を投げる');
} catch (ApiException $e) {
    ok($e->status === 409, '処理済みは 409');
    ok(str_contains($e->getMessage(), 'published'), 'メッセージに published が含まれる');
}

// -------------------------------------------------------------------------
// 9. DraftManager::reject() — 正常却下
// -------------------------------------------------------------------------
section('9. DraftManager::reject() — 正常却下');

$draftMgr->reject($d3, 'human-reviewer', '内容が不正確', $now);
$rejected = $ledger->getDraft($d3);
ok($rejected['status'] === 'rejected', 'draft.status が rejected');

// 監査ログに記録されているか
$pdo = $ledger->getPdo();
$audit = $pdo->query("SELECT * FROM audit WHERE action='draft_rejected' ORDER BY id DESC LIMIT 1")->fetch();
ok($audit !== false, '監査ログに draft_rejected が記録される');
ok($audit['page'] === 'Help', '監査ログの page が Help');

// -------------------------------------------------------------------------
// 10. DraftManager::reject() — 既に処理済みで 409
// -------------------------------------------------------------------------
section('10. DraftManager::reject() — 既に処理済み');

try {
    $draftMgr->reject($d3, 'human-reviewer', '', $now); // d3 はすでに rejected
    ok(false, '処理済み下書きを reject しようとすると例外');
} catch (ApiException $e) {
    ok($e->status === 409, '処理済みは 409');
}

// -------------------------------------------------------------------------
// 11. Ledger::expireDrafts()
// -------------------------------------------------------------------------
section('11. Ledger::expireDrafts()');

// 過去の有効期限で下書きを作成
$d_expire = $ledger->createDraft(
    'Help', $hlp_sha1,
    "= Help =\nこれは失効する下書き。\n",
    'ai-bot', $now - 200, $now - 100 // 100秒前に失効
);
$expired_count = $ledger->expireDrafts($now);
ok($expired_count >= 1, 'expireDrafts() が 1 件以上を処理する');
$expired_draft = $ledger->getDraft($d_expire);
ok($expired_draft['status'] === 'expired', '失効した下書きの status が expired');

// -------------------------------------------------------------------------
// 12. DraftManager::approve() — 失効した下書きは 410
// -------------------------------------------------------------------------
section('12. DraftManager::approve() — 失効した下書き');

// 失効前の下書きを作って、expires_at を過去に設定
$d_past = $ledger->createDraft(
    'Help', $hlp_sha1,
    "= Help =\n失効前下書き。\n",
    'ai-bot', $now - 300, $now - 1 // 1秒前に失効
);

try {
    $draftMgr->approve($d_past, 'human', $now);
    ok(false, '失効した下書きの承認は ApiException を投げる');
} catch (ApiException $e) {
    ok($e->status === 410, '失効は 410');
    ok(str_contains($e->getMessage(), 'expired'), 'メッセージに expired が含まれる');
}
// 自動で status が expired に変わっているか
$past_draft = $ledger->getDraft($d_past);
ok($past_draft['status'] === 'expired', '失効確認時に status が expired に変わる');

// -------------------------------------------------------------------------
// 13-16. REST API エンドポイントシミュレーション
// -------------------------------------------------------------------------
section('13-16. REST API エンドポイントシミュレーション');

// 追加の下書きを作成（d2 は FrontPage で open のまま）
$fp_current  = file_get_contents($fp_file);
$fp_sha1_now = sha1((string)$fp_current);
$d_api       = $ledger->createDraft(
    'FrontPage', $fp_sha1_now,
    "= FrontPage =\nREST API テスト用下書き。\n",
    'ai-bot', $now, $now + 3600
);

function simulate_drafts_request(
    string $method, string $path, array $get, ?array $body,
    string $raw_key, string $client_ip,
    Auth $auth, Ledger $ledger, DraftManager $draftMgr, string $wiki_dir
): Response {
    global $REST_LEDGER, $REST_WIKI_DIR;
    $REST_LEDGER   = $ledger;
    $REST_WIKI_DIR = $wiki_dir;

    // ルーティング: パターンマッチ
    try {
        $auth->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"], 'draft:approve', $client_ip);

        if ($method === 'GET' && $path === '/drafts') {
            $list = $ledger->listDrafts(
                ($get['page'] ?? '') ?: null,
                ($get['status'] ?? '') ?: null,
                ($get['owner'] ?? '') ?: null,
                (int)($get['limit'] ?? 50),
                (int)($get['offset'] ?? 0)
            );
            return Response::ok(['drafts' => $list, 'count' => count($list)]);
        }

        if ($method === 'GET' && preg_match('/^\/drafts\/(\d+)$/', $path, $m)) {
            $data = $draftMgr->getWithDiff((int)$m[1]);
            unset($data['diff_html']);
            return Response::ok($data);
        }

        if ($method === 'POST' && preg_match('/^\/drafts\/(\d+)\/approve$/', $path, $m)) {
            $result = $draftMgr->approve((int)$m[1], 'api-human', time());
            $draft  = $ledger->getDraft((int)$m[1]);
            return Response::ok([
                'published' => true, 'draft_id' => (int)$m[1],
                'page' => $draft['page'], 'new_rev' => $result['new_rev'], 'new_sha1' => $result['new_sha1'],
            ]);
        }

        if ($method === 'POST' && preg_match('/^\/drafts\/(\d+)\/reject$/', $path, $m)) {
            $reason = (string)($body['reason'] ?? '');
            $draftMgr->reject((int)$m[1], 'api-human', $reason, time());
            $draft = $ledger->getDraft((int)$m[1]);
            return Response::ok(['rejected' => true, 'draft_id' => (int)$m[1], 'status' => $draft['status']]);
        }

        return Response::error(404, 'Not found', 'not_found');
    } catch (\Throwable $e) {
        return Response::fromException($e);
    }
}

// 13. GET /drafts
$r13 = simulate_drafts_request('GET', '/drafts', [], null, $raw_key, '1.2.3.4', $auth, $ledger, $draftMgr, $wiki_dir);
ok($r13->getStatus() === 200, 'GET /drafts → 200');
$b13 = $r13->toArray();
ok(isset($b13['drafts']), 'レスポンスに drafts がある');
ok(is_array($b13['drafts']), 'drafts が配列');

// GET /drafts?page=FrontPage&status=open
$r13b = simulate_drafts_request('GET', '/drafts', ['page' => 'FrontPage', 'status' => 'open'], null, $raw_key, '1.2.3.4', $auth, $ledger, $draftMgr, $wiki_dir);
ok($r13b->getStatus() === 200, 'GET /drafts?page=FrontPage&status=open → 200');
foreach ($r13b->toArray()['drafts'] as $d) {
    ok($d['page'] === 'FrontPage' && $d['status'] === 'open', 'フィルタ結果は FrontPage open のみ');
}

// 14. GET /drafts/{id}
$r14 = simulate_drafts_request('GET', "/drafts/{$d_api}", [], null, $raw_key, '1.2.3.4', $auth, $ledger, $draftMgr, $wiki_dir);
ok($r14->getStatus() === 200, 'GET /drafts/{id} → 200');
$b14 = $r14->toArray();
ok(isset($b14['draft']), 'draft フィールドが存在する');
ok(isset($b14['diff']), 'diff フィールドが存在する');
ok(isset($b14['diff_stats']), 'diff_stats フィールドが存在する');
ok(!isset($b14['diff_html']), 'diff_html はレスポンスに含まない（HTMLのみ）');
ok($b14['draft']['id'] === $d_api, 'draft.id が正しい');

// 15. POST /drafts/{id}/approve
$r15 = simulate_drafts_request('POST', "/drafts/{$d_api}/approve", [], null, $raw_key, '1.2.3.4', $auth, $ledger, $draftMgr, $wiki_dir);
ok($r15->getStatus() === 200, 'POST /drafts/{id}/approve → 200');
$b15 = $r15->toArray();
ok($b15['published'] === true, 'published が true');
ok(isset($b15['new_rev']), 'new_rev が存在する');
ok(isset($b15['new_sha1']), 'new_sha1 が存在する');

// 16. POST /drafts/{id}/reject（d2 を却下）
$r16 = simulate_drafts_request('POST', "/drafts/{$d2}/reject", [], ['reason' => 'not suitable'], $raw_key, '1.2.3.4', $auth, $ledger, $draftMgr, $wiki_dir);
ok($r16->getStatus() === 200, 'POST /drafts/{id}/reject → 200');
$b16 = $r16->toArray();
ok($b16['rejected'] === true, 'rejected が true');
ok($b16['status'] === 'rejected', 'status が rejected');

// -------------------------------------------------------------------------
// 17. 承認後の検索インデックス反映
// -------------------------------------------------------------------------
section('17. 承認後の検索インデックス反映');

// d_api を承認した後（section 15 で実施済み）
$search_result = $ledger->search('テスト用下書き');
$search_names  = array_column($search_result, 'name');
ok(in_array('FrontPage', $search_names, true), '承認後の内容が FTS5 でヒットする');

// 古い内容は検索されなくなっているか（インデックスが更新されている）
$old_search = $ledger->search('トップページです');
ok(count($old_search) === 0 || !in_array('FrontPage', array_column($old_search, 'name'), true),
   '古い内容が FrontPage としてヒットしなくなる（インデックス更新確認）');

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
