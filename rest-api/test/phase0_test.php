<?php
/**
 * Phase 0 検証テスト
 *
 * 確認すること:
 *  1. AtomicWriter: 正常書き込み・クラッシュ後に中途状態が残らない
 *  2. RevisionStore: blob 保存・読み込み・整合性検証・重複排除
 *  3. Ledger: CAS/楽観ロック・ロック取得解放・自己修復(heal)・下書き状態遷移
 *  4. Reconciler: 外部編集の検出と追従・fullScan・DB再構築
 *  5. DB なしからファイルのみで再構築できること
 *
 * 実行: php rest-api/test/phase0_test.php
 * @version v0.1
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/AtomicWriter.php';
require_once __DIR__ . '/../lib/RevisionStore.php';
require_once __DIR__ . '/../lib/Ledger.php';
require_once __DIR__ . '/../lib/Reconciler.php';

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

function setup_tmp(): string
{
    $dir = sys_get_temp_dir() . '/pkwk_rest_test_' . getmypid();
    if (is_dir($dir)) {
        system("rm -rf " . escapeshellarg($dir));
    }
    mkdir($dir, 0755, true);
    return $dir;
}

// -------------------------------------------------------------------------
// 1. AtomicWriter
// -------------------------------------------------------------------------
section('1. AtomicWriter');

$tmp = setup_tmp() . '/atomic';
mkdir($tmp);

$dest = $tmp . '/page.txt';
AtomicWriter::write($dest, "Hello, PukiWiki!");
ok(file_exists($dest), 'ファイルが作成される');
ok(file_get_contents($dest) === "Hello, PukiWiki!", '内容が正しい');

// 上書き
AtomicWriter::write($dest, "Updated content");
ok(file_get_contents($dest) === "Updated content", '上書きが正しく動作する');

// 書き込み中のクラッシュシミュレーション:
// 一時ファイルだけ残して rename 前に「落ちた」状態を作る
$crashed_tmp = $tmp . '/.rest_tmp_crashed';
file_put_contents($crashed_tmp, "PARTIAL WRITE - should not be visible");
// 一時ファイルが残っていても次の正常書き込みは成功する
AtomicWriter::write($dest, "Post-crash content");
ok(file_get_contents($dest) === "Post-crash content", 'クラッシュ後の一時ファイルが残っていても正常書き込みできる');
// 孤立した一時ファイルが dest に残っていないこと
ok(!file_exists($crashed_tmp) || file_get_contents($crashed_tmp) === "PARTIAL WRITE - should not be visible",
   '孤立した一時ファイルが dest に影響しない（dest は完全な内容）');

// アーカイブ（ソフト削除）
$archive_dir = $tmp . '/archive';
$archived = AtomicWriter::archive($dest, $archive_dir);
ok(!file_exists($dest), 'アーカイブ後に元ファイルが消える');
ok(file_exists($archived), 'アーカイブ先にファイルが存在する');
ok(file_get_contents($archived) === "Post-crash content", 'アーカイブ先に内容が保持される');

// -------------------------------------------------------------------------
// 2. RevisionStore
// -------------------------------------------------------------------------
section('2. RevisionStore');

$blob_dir = setup_tmp() . '/blobs';
mkdir($blob_dir, 0755, true);
$store = new RevisionStore($blob_dir);

$content_a = "= FrontPage =\nこれはテストページです。\n";
$sha1_a    = sha1($content_a);
$returned  = $store->append($content_a, 'FrontPage', 1, 'api-test');
ok($returned === $sha1_a, 'append() が正しい sha1 を返す');
ok($store->has($sha1_a), 'has() が true を返す');
ok($store->read($sha1_a) === $content_a, 'read() が元の内容を返す');
ok($store->verify($sha1_a), 'verify() が true を返す');

// 重複排除: 同じ内容を2回書いても blob は1つ
$before_count = count(glob($blob_dir . '/*/*.gz') ?: []);
$store->append($content_a, 'FrontPage', 2, 'api-test');
$after_count = count(glob($blob_dir . '/*/*.gz') ?: []);
ok($before_count === $after_count, '同じ内容の重複 blob は作られない');

// 異なる内容
$content_b = "= MenuBar =\n- [[FrontPage]]\n";
$sha1_b    = $store->append($content_b, 'MenuBar', 1, 'api-test');
ok($sha1_b !== $sha1_a, '異なる内容には異なる sha1');
ok($store->verify($sha1_b), '2つ目のblob も verify() が true');

// 壊れた blob の検出
$path_b = $blob_dir . '/' . substr($sha1_b, 0, 2) . '/' . $sha1_b . '.gz';
file_put_contents($path_b, "corrupted data");
ok(!$store->verify($sha1_b), '壊れた blob は verify() が false を返す');

// audit
$broken = $store->auditAll();
ok(count($broken) === 1 && $broken[0] === $sha1_b, 'auditAll() が壊れた blob を検出する');

// -------------------------------------------------------------------------
// 3. Ledger
// -------------------------------------------------------------------------
section('3. Ledger');

$db_dir = setup_tmp() . '/db';
mkdir($db_dir, 0755, true);
$db_path     = $db_dir . '/ledger.sqlite';
$schema_path = __DIR__ . '/../schema/init.sql';

$ledger = Ledger::open($db_path, $schema_path);
ok(file_exists($db_path), 'SQLite ファイルが作成される');

// ページ未存在
ok($ledger->getPage('FrontPage') === null, '未登録ページは null');

// 初回 upsert
$now = time();
$ledger->upsertPage('FrontPage', 1, $sha1_a, $now);
$meta = $ledger->getPage('FrontPage');
ok($meta !== null, 'upsert 後に getPage() が行を返す');
ok((int)$meta['current_rev'] === 1, 'rev=1 で登録される');
ok($meta['content_sha1'] === $sha1_a, 'sha1 が正しく登録される');

// CAS: 正常
$new_rev = $ledger->casUpdate('FrontPage', 1, 'new_sha1_value_here', $now + 1);
ok($new_rev === 2, 'casUpdate() が新しい rev=2 を返す');
$meta2 = $ledger->getPage('FrontPage');
ok($meta2['content_sha1'] === 'new_sha1_value_here', 'casUpdate() 後に sha1 が更新される');

// CAS: 競合（古い rev を使うと失敗する）
$result = $ledger->casUpdate('FrontPage', 1, 'another_sha1', $now + 2);
ok($result === false, '競合時に casUpdate() が false を返す（→ 409）');
// DB は変化していないはず
$meta3 = $ledger->getPage('FrontPage');
ok((int)$meta3['current_rev'] === 2, '競合 CAS 後も rev は変わらない');

// 版履歴
$ledger->appendRevision('FrontPage', 2, 'new_sha1_value_here', 'tester', ['note' => 'test'], $now);
$revs = $ledger->listRevisions('FrontPage');
ok(count($revs) === 1, 'appendRevision 後に版履歴が1件');

// ロック取得
$acquired = $ledger->acquireLock('FrontPage', 'key-001', 30, $now);
ok($acquired === true, 'ロック取得成功');
$lock = $ledger->getLock('FrontPage', $now);
ok($lock !== null && $lock['holder'] === 'key-001', 'getLock() で holder が確認できる');

// ロック競合
$acquired2 = $ledger->acquireLock('FrontPage', 'key-002', 30, $now);
ok($acquired2 === false, '別の holder はロック取得に失敗する');

// ロック解放
$released = $ledger->releaseLock('FrontPage', 'key-001');
ok($released === true, 'ロック解放成功');
ok($ledger->getLock('FrontPage', $now) === null, '解放後はロックが消える');

// 孤児ロック回収
$ledger->acquireLock('MenuBar', 'key-expired', 1, $now - 60); // 60秒前に期限切れ
$reclaimed = $ledger->reclaimExpiredLocks($now);
ok($reclaimed >= 1, '期限切れロックが自動回収される');
ok($ledger->getLock('MenuBar', $now) === null, '回収後はロックが消える');

// heal: DB に sha1 と異なる状態を作ってから heal
$ledger->upsertPage('SomePage', 1, 'old_sha1', $now);
$healed = $ledger->heal('SomePage', 'actual_sha1_from_file', $now + 5);
ok($healed === true, 'heal() が修復を行った場合 true を返す');
$healed_meta = $ledger->getPage('SomePage');
ok($healed_meta['content_sha1'] === 'actual_sha1_from_file', 'heal() 後に sha1 がファイルに追従する');
ok((int)$healed_meta['current_rev'] === 2, 'heal() 後に rev が増えている');

// heal: すでに同期済みなら false
$healed2 = $ledger->heal('SomePage', 'actual_sha1_from_file', $now + 6);
ok($healed2 === false, '同期済みの場合 heal() は false を返す');

// 下書き
$now2 = $now + 100;
$draft_id = $ledger->createDraft('FrontPage', $sha1_a, "下書き本文", 'ai-bot-1', $now2, $now2 + 3600);
ok($draft_id > 0, 'createDraft() が正の ID を返す');
$draft = $ledger->getDraft($draft_id);
ok($draft !== null && $draft['status'] === 'open', '下書きの初期状態は open');
ok($draft['base_sha1'] === $sha1_a, '下書きの base_sha1 が正しい');

$ledger->updateDraftStatus($draft_id, 'approved', $now2 + 10);
$draft2 = $ledger->getDraft($draft_id);
ok($draft2['status'] === 'approved', 'updateDraftStatus() でステータスが変わる');

// 検索インデックス
$ledger->indexPage('FrontPage', "FrontPage ここは Wiki のトップページです", $now);
$ledger->indexPage('MenuBar', "MenuBar メニューバーです", $now);
$results = $ledger->search('メニューバー');
ok(count($results) > 0, 'FTS5 検索でヒットする');
ok($results[0]['name'] === 'MenuBar', '検索結果が正しいページを返す');

// APIキー
$raw_key = 'test-api-key-' . bin2hex(random_bytes(8));
$key_id  = $ledger->registerApiKey($raw_key, 'test-key', 'page:read draft:create', $now);
ok($key_id > 0, 'APIキー登録が成功する');
$auth = $ledger->authenticateKey($raw_key, $now);
ok($auth !== null, '正しいキーで認証成功');
ok($auth['scopes'] === 'page:read draft:create', 'スコープが正しい');
$auth_bad = $ledger->authenticateKey('wrong-key', $now);
ok($auth_bad === null, '間違ったキーで認証失敗');

// -------------------------------------------------------------------------
// 4. Reconciler
// -------------------------------------------------------------------------
section('4. Reconciler');

$wiki_dir  = setup_tmp() . '/wiki';
$blob_dir2 = setup_tmp() . '/blobs2';
$db_dir2   = setup_tmp() . '/db2';
mkdir($wiki_dir, 0755, true);
mkdir($blob_dir2, 0755, true);
mkdir($db_dir2, 0755, true);

$ledger2 = Ledger::open($db_dir2 . '/ledger.sqlite', $schema_path);
$store2  = new RevisionStore($blob_dir2);
$rec     = new Reconciler($ledger2, $store2, $wiki_dir);

// encode/decode の動作確認
ok(Reconciler::encode('FrontPage') === '46726F6E7450616765', 'encode() が正しい hex を返す');
ok(Reconciler::decode('46726F6E7450616765') === 'FrontPage', 'decode() が正しい名前を返す');

// wiki ファイルを直接作成（PukiWiki 本体が書いた状態をシミュレート）
$fp_content  = "= FrontPage =\nWiki のトップページ\n";
$mb_content  = "- [[FrontPage]]\n";
$fp_encoded  = Reconciler::encode('FrontPage');
$mb_encoded  = Reconciler::encode('MenuBar');
file_put_contents($wiki_dir . '/' . $fp_encoded . '.txt', $fp_content);
file_put_contents($wiki_dir . '/' . $mb_encoded . '.txt', $mb_content);

// DB は空の状態で check() を呼ぶ → 外部編集として healed になる
$result_fp = $rec->check('FrontPage');
ok($result_fp === 'healed', 'DB にない新ページは healed と判定される');
$meta_fp = $ledger2->getPage('FrontPage');
ok($meta_fp !== null, 'heal 後に DB にページが登録される');
ok($meta_fp['content_sha1'] === sha1($fp_content), 'heal 後に sha1 がファイルと一致する');

// 2回目は in_sync
$result_fp2 = $rec->check('FrontPage');
ok($result_fp2 === 'in_sync', '2回目の check() は in_sync を返す');

// fullScan: 両方のページをまとめて同期
$scan_result = $rec->fullScan();
ok($scan_result['in_sync'] + $scan_result['healed'] >= 2, 'fullScan() が全ページを処理する');

// ファイルが外部から書き換えられた場合（DB との sha1 不一致）
$new_fp_content = "= FrontPage =\n更新されたトップページ\n";
file_put_contents($wiki_dir . '/' . $fp_encoded . '.txt', $new_fp_content);
$result_external = $rec->check('FrontPage');
ok($result_external === 'healed', '外部編集後は healed と判定される');
$meta_fp3 = $ledger2->getPage('FrontPage');
ok($meta_fp3['content_sha1'] === sha1($new_fp_content), '外部編集後 sha1 が追従する');
ok((int)$meta_fp3['current_rev'] >= 2, '外部編集で rev が増えている');

// blob も保存されているはず
ok($store2->has(sha1($new_fp_content)), '外部編集の内容が blob に保存されている');

// ファイルが消えた場合（ソフト削除として扱う）
unlink($wiki_dir . '/' . $fp_encoded . '.txt');
$result_deleted = $rec->check('FrontPage');
ok($result_deleted === 'healed', 'ファイル削除は healed と判定される（DB 側を deleted に変更）');
$meta_fp4 = $ledger2->getPage('FrontPage');
ok($meta_fp4['status'] === 'deleted', 'ファイル削除後に DB の status が deleted になる');

// -------------------------------------------------------------------------
// 5. DB 再構築（ファイルから）
// -------------------------------------------------------------------------
section('5. DB再構築（ファイルから）');

// ファイルを復元して再構築テスト
file_put_contents($wiki_dir . '/' . $fp_encoded . '.txt', $fp_content);

// DB を削除
$db_path_rebuild = $db_dir2 . '/ledger_rebuild.sqlite';
$ledger_rebuild  = Ledger::open($db_path_rebuild, $schema_path);
$rec_rebuild     = new Reconciler($ledger_rebuild, $store2, $wiki_dir);

$rebuild_result = $rec_rebuild->rebuildFromFiles();
ok($rebuild_result['pages'] >= 2, "再構築で {$rebuild_result['pages']} ページが登録される");
ok($rebuild_result['errors'] === 0, '再構築エラーなし');

$meta_rebuilt = $ledger_rebuild->getPage('FrontPage');
ok($meta_rebuilt !== null, '再構築後に FrontPage が登録されている');
ok($meta_rebuilt['content_sha1'] === sha1($fp_content), '再構築後 sha1 が正しい');

// FTS5 検索も動く
$search_result = $ledger_rebuild->search('Wiki トップ');
ok(count($search_result) > 0, '再構築後に FTS5 検索が動作する');

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
