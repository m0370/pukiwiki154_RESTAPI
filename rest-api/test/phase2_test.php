<?php
/**
 * Phase 2 検証テスト: 検索インデックス
 *
 * 確認すること:
 *  1. Reconciler::buildIndex() が wiki/ から page_index を構築する
 *  2. Reconciler::buildIndexIfEmpty() が冪等（既に構築済みなら何もしない）
 *  3. Ledger::search() が FTS5 全文検索で日本語・英語ともにヒットする
 *  4. Reconciler::verifyIndex() が整合性を正しく報告する
 *  5. GET /search エンドポイントのシミュレーション
 *  6. GET /index/status の動作確認
 *  7. インデックスに未登録のページへの read() が自動でインデックスを更新する
 *
 * 実行: php rest-api/test/phase2_test.php
 * @version v0.1
 */
declare(strict_types=1);

$test_dir  = sys_get_temp_dir() . '/pkwk_phase2_' . getmypid();
$wiki_dir  = $test_dir . '/wiki';
$db_dir    = $test_dir . '/db';
$blob_dir  = $test_dir . '/blobs';

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
require_once $REST_DIR . '/lib/Response.php';
require_once $REST_DIR . '/lib/Router.php';

$schema  = $REST_DIR . '/schema/init.sql';
$db_path = $db_dir . '/ledger.sqlite';
$ledger  = Ledger::open($db_path, $schema);
$store   = new RevisionStore($blob_dir);
$rec     = new Reconciler($ledger, $store, $wiki_dir);
$reader  = new PageReader($rec, $ledger, $wiki_dir);

// -------------------------------------------------------------------------
// テスト用 wiki ファイルを作成
// -------------------------------------------------------------------------
$pages = [
    'FrontPage'   => "= FrontPage =\nここは PukiWiki のトップページです。Wiki へようこそ。\n",
    'MenuBar'     => "- [[FrontPage]]\n- [[Help]]\n- [[SandBox]]\n",
    'Help'        => "= Help =\n使い方のヘルプページです。編集方法や書式について説明します。\n",
    'SandBox'     => "= SandBox =\n自由に試し書きができるページです。\n",
    'PukiWiki'    => "= PukiWiki =\nPukiWiki は PHP で動作する Wiki システムです。\n",
    'RecentChanges' => "= RecentChanges =\n最近更新されたページの一覧です。\n",
];

foreach ($pages as $name => $content) {
    $encoded = strtoupper(bin2hex($name));
    file_put_contents($wiki_dir . '/' . $encoded . '.txt', $content);
}

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
// 1. buildIndex() / buildIndexIfEmpty()
// -------------------------------------------------------------------------
section('1. buildIndex / buildIndexIfEmpty');

// page_index は空のはず
$count_before = (int)$ledger->getPdo()->query('SELECT COUNT(*) FROM page_index')->fetchColumn();
ok($count_before === 0, 'buildIndex 前は page_index が空');

// buildIndex
$built = $rec->buildIndex();
ok($built === count($pages), "buildIndex() が " . count($pages) . " ページを処理する");
$count_after = (int)$ledger->getPdo()->query('SELECT COUNT(*) FROM page_index')->fetchColumn();
ok($count_after === count($pages), 'buildIndex 後に page_index にページが登録される');

// buildIndexIfEmpty: 既に存在するので 0 を返す
$built2 = $rec->buildIndexIfEmpty();
ok($built2 === 0, 'buildIndexIfEmpty() は既に構築済みなら 0 を返す');
$count_after2 = (int)$ledger->getPdo()->query('SELECT COUNT(*) FROM page_index')->fetchColumn();
ok($count_after2 === count($pages), 'buildIndexIfEmpty() はページ数を変更しない');

// -------------------------------------------------------------------------
// 2. Ledger::search() / FTS5
// -------------------------------------------------------------------------
section('2. FTS5 全文検索');

// 日本語検索
$r1 = $ledger->search('PukiWiki');
ok(count($r1) >= 1, '"PukiWiki" 検索で1件以上ヒット');
$names1 = array_column($r1, 'name');
ok(in_array('PukiWiki', $names1, true), '"PukiWiki" ページがヒットする');

// 日本語キーワード
$r2 = $ledger->search('使い方');
ok(count($r2) >= 1, '"使い方" 検索でヒット');
ok(in_array('Help', array_column($r2, 'name'), true), '"使い方" で Help ページがヒット');

// 複数ページにまたがるキーワード
$r3 = $ledger->search('ページ');
ok(count($r3) >= 2, '"ページ" 検索で複数ページがヒット');

// excerpt が返ってくる
ok(isset($r1[0]['excerpt']), 'search() の結果に excerpt が含まれる');

// クエリが短すぎる（2文字未満）
// trigram は 3 文字未満のクエリには動作しない仕様
// → 実際には 1-2 文字の場合 0 件になる
$r_short = $ledger->search('PH'); // 2文字
ok(count($r_short) === 0, '2文字クエリは結果なし（trigram 最小 3 文字）');

// 存在しないキーワード
$r_miss = $ledger->search('存在しないキーワードXYZ123');
ok(count($r_miss) === 0, '存在しないキーワードは 0 件');

// limit の動作
$r_limited = $ledger->search('ページ', 2);
ok(count($r_limited) <= 2, 'limit パラメータが機能する');

// -------------------------------------------------------------------------
// 3. verifyIndex()
// -------------------------------------------------------------------------
section('3. verifyIndex');

// 整合している状態
$status = $rec->verifyIndex();
ok($status['is_consistent'] === true, '初期状態は整合している');
ok($status['total_files'] === count($pages), 'total_files がファイル数と一致');
ok($status['total_indexed'] === count($pages), 'total_indexed がインデックス数と一致');
ok(empty($status['missing_in_index']), 'missing_in_index が空');
ok(empty($status['orphan_in_index']), 'orphan_in_index が空');

// wiki/ に新しいファイルを追加（インデックス未登録）
$new_page    = 'NewArticle';
$new_content = "= NewArticle =\n新しく追加された記事です。\n";
$new_encoded = strtoupper(bin2hex($new_page));
file_put_contents($wiki_dir . '/' . $new_encoded . '.txt', $new_content);

$status2 = $rec->verifyIndex();
ok($status2['is_consistent'] === false, 'ファイル追加後は不整合を検出する');
ok(in_array($new_page, $status2['missing_in_index'], true), '新しいページが missing_in_index に現れる');

// インデックスを更新してから再確認
$ledger->indexPage($new_page, $new_content, time());
$status3 = $rec->verifyIndex();
ok($status3['is_consistent'] === true, 'indexPage 後は整合する');

// page_index にあるのに wiki/ にないページ（孤立）
$ledger->indexPage('GhostPage', 'ゴーストページ', time());
$status4 = $rec->verifyIndex();
ok(in_array('GhostPage', $status4['orphan_in_index'], true), '孤立ページが orphan_in_index に現れる');
// 孤立エントリを削除
$ledger->deindexPage('GhostPage');
$status5 = $rec->verifyIndex();
ok($status5['is_consistent'] === true, '孤立エントリ削除後に整合する');

// -------------------------------------------------------------------------
// 4. GET /search エンドポイントのシミュレーション
// -------------------------------------------------------------------------
section('4. GET /search シミュレーション');

// API キー登録
$now     = time();
$raw_key = 'search-test-key-' . bin2hex(random_bytes(4));
$ledger->registerApiKey($raw_key, 'search test', 'page:read', $now);
$auth = new Auth($ledger);

function simulate_search(
    string $raw_key, string $q, string $client_ip,
    Auth $auth, Ledger $ledger
): Response {
    try {
        $auth->authenticate(
            ['HTTP_AUTHORIZATION' => "Bearer {$raw_key}"],
            'page:read', $client_ip
        );
        if ($q === '') {
            throw new ApiException(400, 'Query parameter "q" is required', 'missing_query');
        }
        if (mb_strlen($q, 'UTF-8') < 3) {
            throw new ApiException(400, 'Query must be at least 3 characters', 'query_too_short');
        }
        $limit   = 20;
        $results = $ledger->search($q, $limit);
        return Response::ok([
            'query'   => $q,
            'results' => $results,
            'count'   => count($results),
            'limit'   => $limit,
        ]);
    } catch (\Throwable $e) {
        return Response::fromException($e);
    }
}

// 正常検索
$resp = simulate_search($raw_key, 'PukiWiki', '1.2.3.4', $auth, $ledger);
ok($resp->getStatus() === 200, 'GET /search が 200 を返す');
$body = $resp->toArray();
ok(isset($body['query']), 'レスポンスに query が含まれる');
ok(isset($body['results']), 'レスポンスに results が含まれる');
ok(isset($body['count']), 'レスポンスに count が含まれる');
ok($body['count'] >= 1, 'PukiWiki 検索で 1 件以上ヒット');

// クエリ短すぎる
$resp_short = simulate_search($raw_key, 'ab', '1.2.3.4', $auth, $ledger);
ok($resp_short->getStatus() === 400, '2文字クエリは 400');
ok($resp_short->toArray()['error']['code'] === 'query_too_short', 'error_code が query_too_short');

// クエリなし
$resp_empty = simulate_search($raw_key, '', '1.2.3.4', $auth, $ledger);
ok($resp_empty->getStatus() === 400, '空クエリは 400');

// 認証なし
$resp_noauth = simulate_search('bad-key', 'PukiWiki', '1.2.3.4', $auth, $ledger);
ok($resp_noauth->getStatus() === 401, '不正なキーは 401');

// -------------------------------------------------------------------------
// 5. GET /index/status シミュレーション
// -------------------------------------------------------------------------
section('5. GET /index/status シミュレーション');

$status_resp = $rec->verifyIndex();
ok(isset($status_resp['total_files']), 'verifyIndex に total_files がある');
ok(isset($status_resp['total_indexed']), 'verifyIndex に total_indexed がある');
ok(isset($status_resp['is_consistent']), 'verifyIndex に is_consistent がある');
ok(is_bool($status_resp['is_consistent']), 'is_consistent が bool 型');

// -------------------------------------------------------------------------
// 6. read() が未インデックスページを自動登録する
// -------------------------------------------------------------------------
section('6. read() による自動インデックス更新');

// まず GhostPage を wiki/ に作成してから FreshPage を新規追加
$fresh_content = "= FreshPage =\n自動登録されるページです。\n";
$fresh_encoded = strtoupper(bin2hex('FreshPage'));
file_put_contents($wiki_dir . '/' . $fresh_encoded . '.txt', $fresh_content);

// page_index にはまだ FreshPage がない
$before = $ledger->listPages(1000, 0);
$before_names = array_column($before, 'name');
ok(!in_array('FreshPage', $before_names, true), 'read() 前は FreshPage がインデックスにない');

// read() を呼ぶ → Reconciler::check() が heal → indexPage() が呼ばれる
$fresh_data = $reader->read('FreshPage');
ok($fresh_data['page'] === 'FreshPage', 'read() が FreshPage を返す');

$after = $ledger->listPages(1000, 0);
$after_names = array_column($after, 'name');
ok(in_array('FreshPage', $after_names, true), 'read() 後に FreshPage がインデックスに登録される');

// FreshPage が検索でヒットするか
$search_fresh = $ledger->search('自動登録');
ok(count($search_fresh) >= 1, '自動登録されたページが FTS5 で検索できる');

// -------------------------------------------------------------------------
// 7. bin/build-index.php の動作確認（CLI）
// -------------------------------------------------------------------------
section('7. bin/build-index.php CLI スクリプト');

// 別の DB で一から buildIndex をテスト
$cli_db_path = $db_dir . '/cli_test.sqlite';
$cli_db = Ledger::open($cli_db_path, $schema);
$cli_store = new RevisionStore($blob_dir . '/cli');
$cli_rec = new Reconciler($cli_db, $cli_store, $wiki_dir);

// buildIndexIfEmpty: 空なので構築する
$built_fresh = $cli_rec->buildIndexIfEmpty();
ok($built_fresh >= count($pages), "buildIndexIfEmpty() が {$built_fresh} ページを構築する");

$cli_result = $cli_rec->verifyIndex();
ok($cli_result['is_consistent'] === true, '新規 DB でのビルド後に整合している');

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
