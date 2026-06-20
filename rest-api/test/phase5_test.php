<?php
/**
 * Phase 5 検証テスト: 共有コミットエンジン
 *
 * 確認すること:
 *  1. CommitEngine::commit() — 既存ページへの正常コミット
 *  2. CommitEngine::commit() — 新規ページ（EMPTY_SHA1）
 *  3. CommitEngine::commit() — sha1 競合（409 sha1_conflict）
 *  4. CommitEngine::commit() — 新規ページで非 EMPTY_SHA1（409 page_not_found_as_conflict）
 *  5. CommitEngine::commit() — ページがリース中（423 page_locked）
 *  6. CommitEngine::commit() — 例外発生後もリースが解放される
 *  7. CommitEngine::commit() — 連続コミット（rev が正しく増える）
 *  8. CommitEngine::commit() — ファイル書き込みは AtomicWriter 経由で正確
 *  9. CommitEngine::commit() — page_write() が存在する場合はそちらを優先
 * 10. CommitEngine::commit() — コミット後に page_index が更新される（FTS5 でヒット）
 * 11. CommitEngine::commit() — コミット後に revisions blob が保存される
 * 12. CommitEngine::commit() — 監査ログに page_committed が記録される
 * 13. CommitEngine::EMPTY_SHA1 が sha1('') と一致する
 * 14. DraftManager が CommitEngine を使う（E2E: 下書き→承認→ページ更新）
 * 15. DraftManager::approve() — CommitEngine の 423 が透過する
 * 16. CommitEngine::commit() — frozen ページのモック（is_freeze() が true）
 *
 * 実行: php rest-api/test/phase5_test.php
 *
 * 注意: テスト 9（page_write モック）は最初に宣言するため、
 * このファイル内の全コミットで page_write() が呼ばれる（意図通り）。
 * モックは AtomicWriter と同等の書き込みを行うので動作に影響しない。
 * @version v0.1
 */
declare(strict_types=1);

// ──────────────────────────────────────────────────
// page_write() モック（PukiWiki 未ロード環境でのテスト 9 用）
// PHP では関数を後から定義できないため、テストファイルの先頭で宣言する。
// このモックはファイルへの書き込みを行い、呼び出しを記録する。
// ──────────────────────────────────────────────────
$GLOBALS['_page_write_calls'] = [];
$GLOBALS['_page_write_wiki_dir'] = '';

function page_write(string $page, string $body): void
{
    $dir     = $GLOBALS['_page_write_wiki_dir'];
    $encoded = strtoupper(bin2hex($page));
    // AtomicWriter と同等の書き込み
    $file    = $dir . '/' . $encoded . '.txt';
    if ($dir !== '') {
        $tmp = tempnam(dirname($file), '.pw_tmp_');
        file_put_contents($tmp, $body, LOCK_EX);
        rename($tmp, $file);
    }
    $GLOBALS['_page_write_calls'][] = ['page' => $page, 'body' => $body];
}

// ──────────────────────────────────────────────────
// 環境セットアップ
// ──────────────────────────────────────────────────
$test_dir = sys_get_temp_dir() . '/pkwk_phase5_' . getmypid();
$wiki_dir = $test_dir . '/wiki';
$db_dir   = $test_dir . '/db';
$blob_dir = $test_dir . '/blobs';

foreach ([$wiki_dir, $db_dir, $blob_dir] as $d) {
    mkdir($d, 0755, true);
}

$GLOBALS['_page_write_wiki_dir'] = $wiki_dir;

$REST_DIR = dirname(__DIR__);
require_once $REST_DIR . '/lib/AtomicWriter.php';
require_once $REST_DIR . '/lib/RevisionStore.php';
require_once $REST_DIR . '/lib/Ledger.php';
require_once $REST_DIR . '/lib/Reconciler.php';
require_once $REST_DIR . '/lib/ApiException.php';
require_once $REST_DIR . '/lib/CommitEngine.php';
require_once $REST_DIR . '/lib/DiffEngine.php';
require_once $REST_DIR . '/lib/DraftManager.php';
require_once $REST_DIR . '/lib/PageReader.php';

$schema  = $REST_DIR . '/schema/init.sql';
$db_path = $db_dir   . '/ledger.sqlite';
$ledger  = Ledger::open($db_path, $schema);
$store   = new RevisionStore($blob_dir);
$engine  = new CommitEngine($ledger, $store, $wiki_dir);

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

function section(string $name): void
{
    echo "\n\e[1m{$name}\e[0m\n";
}

$now = time();

// ──────────────────────────────────────────────────
// 1. 既存ページへの正常コミット
// ──────────────────────────────────────────────────
section('1. 既存ページへの正常コミット');

$page1    = 'FrontPage';
$content1 = "= FrontPage =\n初期内容。\n";
$file1    = $wiki_dir . '/' . strtoupper(bin2hex($page1)) . '.txt';
file_put_contents($file1, $content1);
$sha1_1 = sha1($content1);

$new_content1 = "= FrontPage =\n更新後の内容。\n";
$result1 = $engine->commit($page1, $new_content1, $sha1_1, 'test-actor', ['source' => 'test']);

ok(isset($result1['new_rev']), 'commit() が new_rev を返す');
ok(isset($result1['new_sha1']), 'commit() が new_sha1 を返す');
ok(isset($result1['committed_at']), 'commit() が committed_at を返す');
ok($result1['new_rev'] === 1, '初回コミットの rev が 1');
ok($result1['new_sha1'] === sha1($new_content1), 'new_sha1 が正しい');

// ファイルが更新されているか
$current_file_content = file_get_contents($file1);
ok($current_file_content === $new_content1, 'ファイルが新しい内容に更新される');

// DB が更新されているか
$page_rec = $ledger->getPage($page1);
ok($page_rec !== null, 'pages テーブルにレコードが存在する');
ok((int)$page_rec['current_rev'] === 1, 'pages.current_rev が 1');
ok($page_rec['content_sha1'] === sha1($new_content1), 'pages.content_sha1 が正しい');

// ──────────────────────────────────────────────────
// 2. 新規ページ（EMPTY_SHA1）
// ──────────────────────────────────────────────────
section('2. 新規ページ（EMPTY_SHA1）');

$new_page    = 'NewPage';
$new_content = "= NewPage =\n新規作成ページ。\n";
$new_file    = $wiki_dir . '/' . strtoupper(bin2hex($new_page)) . '.txt';

ok(!file_exists($new_file), '事前確認: ファイルが存在しない');

$result_new = $engine->commit($new_page, $new_content, CommitEngine::EMPTY_SHA1, 'test-actor');

ok(isset($result_new['new_rev']), 'commit() が成功する');
ok($result_new['new_rev'] === 1, '新規ページの rev が 1');
ok(file_exists($new_file), '新規ページのファイルが作成される');
ok(file_get_contents($new_file) === $new_content, '新規ページの内容が正しい');

// ──────────────────────────────────────────────────
// 3. sha1 競合（409 sha1_conflict）
// ──────────────────────────────────────────────────
section('3. sha1 競合（409 sha1_conflict）');

// page1 の内容はすでに変わっているが、古い sha1 を渡す
try {
    $engine->commit($page1, '新しい内容', $sha1_1, 'test-actor'); // $sha1_1 は古い
    ok(false, 'sha1 競合は ApiException を投げる');
} catch (ApiException $e) {
    ok($e->status === 409, '競合は 409');
    ok($e->error_code === 'sha1_conflict', 'error_code が sha1_conflict');
    ok(str_contains($e->getMessage(), 'sha1') || str_contains($e->getMessage(), 'Conflict'), 'メッセージに sha1/Conflict');
}

// ファイルが書き換えられていないことを確認
ok(file_get_contents($file1) === $new_content1, '競合 → ファイルは変更されない');

// ──────────────────────────────────────────────────
// 4. 新規ページで非 EMPTY_SHA1（409 page_not_found_as_conflict）
// ──────────────────────────────────────────────────
section('4. 新規ページで非 EMPTY_SHA1');

$ghost_page = 'GhostPageDoesNotExist';
$ghost_file = $wiki_dir . '/' . strtoupper(bin2hex($ghost_page)) . '.txt';

ok(!file_exists($ghost_file), '事前確認: ファイルが存在しない');

try {
    $engine->commit($ghost_page, '内容', 'abcdef1234567890abcdef1234567890abcdef12', 'actor');
    ok(false, '非 EMPTY_SHA1 + 存在しないページは ApiException');
} catch (ApiException $e) {
    ok($e->status === 409, '409 が返る');
    ok($e->error_code === 'page_not_found_as_conflict', 'error_code が page_not_found_as_conflict');
}

ok(!file_exists($ghost_file), '失敗時にファイルが作成されない');

// ──────────────────────────────────────────────────
// 5. ページがリース中（423 page_locked）
// ──────────────────────────────────────────────────
section('5. ページがリース中（423 page_locked）');

$locked_page    = 'LockedPage';
$locked_content = "= LockedPage =\nロック中。\n";
$locked_file    = $wiki_dir . '/' . strtoupper(bin2hex($locked_page)) . '.txt';
file_put_contents($locked_file, $locked_content);

// 別の保持者がリースを取得中
$ledger->acquireLock($locked_page, 'other-actor-lock', 60, $now);

try {
    $engine->commit($locked_page, $locked_content . "追記\n", sha1($locked_content), 'test-actor');
    ok(false, 'リース中ページは ApiException を投げる');
} catch (ApiException $e) {
    ok($e->status === 423, 'リース中は 423');
    ok($e->error_code === 'page_locked', 'error_code が page_locked');
}

// ファイルが変更されていないことを確認
ok(file_get_contents($locked_file) === $locked_content, 'ロック競合 → ファイルは変更されない');

// リースを解放して次のテストに備える
$ledger->releaseLock($locked_page, 'other-actor-lock');

// ──────────────────────────────────────────────────
// 6. 例外後もリースが解放される
// ──────────────────────────────────────────────────
section('6. 例外後もリースが解放される');

// sha1 競合を起こしてリースが残らないことを確認
$page2    = 'ReleasePage';
$content2 = "= ReleasePage =\n初期。\n";
$file2    = $wiki_dir . '/' . strtoupper(bin2hex($page2)) . '.txt';
file_put_contents($file2, $content2);

try {
    $engine->commit($page2, '内容', 'wrongsha1000000000000000000000000000000000', 'actor');
} catch (ApiException $e) {
    // expected: 409
}

// リースが解放されているか（locks テーブルに残っていないはず）
$lock = $ledger->getLock($page2, $now);
ok($lock === null, '失敗後にリースが解放される');

// ──────────────────────────────────────────────────
// 7. 連続コミット（rev が正しく増える）
// ──────────────────────────────────────────────────
section('7. 連続コミット（rev インクリメント）');

$seq_page = 'SeqPage';
$seq_file = $wiki_dir . '/' . strtoupper(bin2hex($seq_page)) . '.txt';

// 1回目（新規）
$r_v1 = $engine->commit($seq_page, "v1\n", CommitEngine::EMPTY_SHA1, 'actor');
ok($r_v1['new_rev'] === 1, '1回目のコミット rev = 1');

// 2回目
$r_v2 = $engine->commit($seq_page, "v2\n", sha1("v1\n"), 'actor');
ok($r_v2['new_rev'] === 2, '2回目のコミット rev = 2');

// 3回目
$r_v3 = $engine->commit($seq_page, "v3\n", sha1("v2\n"), 'actor');
ok($r_v3['new_rev'] === 3, '3回目のコミット rev = 3');

// DB の rev も一致するか
$seq_rec = $ledger->getPage($seq_page);
ok((int)$seq_rec['current_rev'] === 3, 'DB の current_rev が 3');

// revisions に 3 件記録されているか
$revs = $ledger->listRevisions($seq_page, 10);
ok(count($revs) === 3, 'revisions に 3 件記録される');
ok((int)$revs[0]['rev'] === 3, '最新が rev=3');

// ──────────────────────────────────────────────────
// 8. ファイル書き込みの正確性（AtomicWriter 経由）
// ──────────────────────────────────────────────────
section('8. ファイル書き込みの正確性');

$atomic_page = 'AtomicPage';
$atomic_file = $wiki_dir . '/' . strtoupper(bin2hex($atomic_page)) . '.txt';
$atomic_body = "日本語コンテンツ: こんにちは\n改行あり\n末尾も改行\n";

$engine->commit($atomic_page, $atomic_body, CommitEngine::EMPTY_SHA1, 'actor');
ok(file_exists($atomic_file), 'ファイルが作成される');
ok(file_get_contents($atomic_file) === $atomic_body, 'ファイルの内容がバイト単位で正確');
ok(sha1((string)file_get_contents($atomic_file)) === sha1($atomic_body), 'ファイルの sha1 が一致');

// ──────────────────────────────────────────────────
// 9. page_write() が存在する場合はそちらを使う
// ──────────────────────────────────────────────────
section('9. page_write() モック統合');

// page_write() はこのファイルの冒頭で定義済み（$GLOBALS['_page_write_calls'] に記録）
ok(function_exists('page_write'), 'page_write() が定義されている');

$pw_page    = 'PageWritePage';
$pw_content = "= PageWritePage =\npage_write 経由で書き込み。\n";
$GLOBALS['_page_write_calls'] = []; // リセット

$engine->commit($pw_page, $pw_content, CommitEngine::EMPTY_SHA1, 'actor');

ok(count($GLOBALS['_page_write_calls']) === 1, 'page_write() が 1 回呼ばれた');
ok($GLOBALS['_page_write_calls'][0]['page'] === $pw_page, 'page_write() の page が正しい');
ok($GLOBALS['_page_write_calls'][0]['body'] === $pw_content, 'page_write() の body が正しい');

// ──────────────────────────────────────────────────
// 10. コミット後に page_index が更新される（FTS5 でヒット）
// ──────────────────────────────────────────────────
section('10. コミット後の検索インデックス更新');

$idx_page    = 'IndexUpdatePage';
$idx_content = "= IndexUpdatePage =\nコミットエンジン専用テスト。PukiWiki API。\n";

$engine->commit($idx_page, $idx_content, CommitEngine::EMPTY_SHA1, 'actor');

// FTS5 で検索できるか
$results = $ledger->search('コミットエンジン');
$names   = array_column($results, 'name');
ok(in_array($idx_page, $names, true), 'コミット後にページが FTS5 で検索できる');

// 内容を更新して検索結果が変わるか
$idx_content2 = "= IndexUpdatePage =\n更新後の内容。\n";
$engine->commit($idx_page, $idx_content2, sha1($idx_content), 'actor');

$results2 = $ledger->search('コミットエンジン');
$names2   = array_column($results2, 'name');
ok(!in_array($idx_page, $names2, true), '更新後は古いキーワードでヒットしなくなる');

$results3 = $ledger->search('更新後の内容');
$names3   = array_column($results3, 'name');
ok(in_array($idx_page, $names3, true), '新しいキーワードでヒットする');

// ──────────────────────────────────────────────────
// 11. コミット後に revisions blob が保存される
// ──────────────────────────────────────────────────
section('11. revisions blob 保存');

$blob_page    = 'BlobPage';
$blob_content = "= BlobPage =\n版管理テスト。\n";

$blob_result = $engine->commit($blob_page, $blob_content, CommitEngine::EMPTY_SHA1, 'actor');
$expected_sha1 = sha1($blob_content);

ok($store->has($expected_sha1), 'blob が RevisionStore に保存される');
ok($store->read($expected_sha1) === $blob_content, 'blob の内容が正しい');
ok($store->verify($expected_sha1), 'blob が破損していない');

// ──────────────────────────────────────────────────
// 12. 監査ログに page_committed が記録される
// ──────────────────────────────────────────────────
section('12. 監査ログ記録');

$audit_page = 'AuditPage';
$engine->commit($audit_page, "コンテンツ\n", CommitEngine::EMPTY_SHA1, 'audit-actor', ['source' => 'test']);

$pdo   = $ledger->getPdo();
$audit = $pdo->prepare("SELECT * FROM audit WHERE action='page_committed' AND page=? ORDER BY id DESC LIMIT 1");
$audit->execute([$audit_page]);
$row   = $audit->fetch();

ok($row !== false, '監査ログに page_committed が記録される');
ok($row['actor'] === 'audit-actor', '監査ログの actor が正しい');
ok($row['page'] === $audit_page, '監査ログの page が正しい');

$detail = json_decode($row['detail'], true);
ok(isset($detail['new_rev']), '監査ログに new_rev が含まれる');
ok(isset($detail['new_sha1']), '監査ログに new_sha1 が含まれる');
ok(isset($detail['base_sha1']), '監査ログに base_sha1 が含まれる');
ok($detail['source'] === 'test', '監査ログにメタデータが含まれる');

// ──────────────────────────────────────────────────
// 13. CommitEngine::EMPTY_SHA1 の検証
// ──────────────────────────────────────────────────
section('13. CommitEngine::EMPTY_SHA1 定数の正確性');

ok(CommitEngine::EMPTY_SHA1 === sha1(''), 'EMPTY_SHA1 が sha1("") と一致する');
ok(strlen(CommitEngine::EMPTY_SHA1) === 40, 'EMPTY_SHA1 が 40 文字');
ok(ctype_xdigit(CommitEngine::EMPTY_SHA1), 'EMPTY_SHA1 が 16 進数文字列');

// ──────────────────────────────────────────────────
// 14. E2E: 下書き → 承認 → ページ更新
// ──────────────────────────────────────────────────
section('14. E2E: 下書き作成 → 承認 → ページ更新');

$e2e_page    = 'E2EPage';
$e2e_content = "= E2EPage =\n元の内容。\n";
$e2e_file    = $wiki_dir . '/' . strtoupper(bin2hex($e2e_page)) . '.txt';

// まずコミットエンジンでページを作る
$engine->commit($e2e_page, $e2e_content, CommitEngine::EMPTY_SHA1, 'initial-actor');

// AI が下書きを作成
$e2e_sha1    = sha1($e2e_content);
$draft_body  = "= E2EPage =\nAI が改訂した内容。より詳しく説明。\n";
$draft_id    = $ledger->createDraft($e2e_page, $e2e_sha1, $draft_body, 'ai-bot', $now, $now + 3600);

ok($draft_id > 0, '下書きが作成された');

// 人間が DraftManager 経由で承認
$dm  = new DraftManager($ledger, $engine, $wiki_dir);
$res = $dm->approve($draft_id, 'human-approver', $now);

ok(isset($res['new_rev']), '承認後に new_rev が返る');

// ファイルが更新されているか
ok(file_get_contents($e2e_file) === $draft_body, '承認後ファイルに下書き内容が反映される');

// DB が更新されているか
$e2e_rec = $ledger->getPage($e2e_page);
ok($e2e_rec['content_sha1'] === sha1($draft_body), 'DB の sha1 が更新される');

// 下書きが published に変わっているか
$e2e_draft = $ledger->getDraft($draft_id);
ok($e2e_draft['status'] === 'published', '下書きが published になる');

// FTS5 で新しい内容が検索できるか
$e2e_search = $ledger->search('AI が改訂');
ok(in_array($e2e_page, array_column($e2e_search, 'name'), true), '承認後の内容が FTS5 で検索できる');

// ──────────────────────────────────────────────────
// 15. DraftManager::approve() — CommitEngine の 423 が透過する
// ──────────────────────────────────────────────────
section('15. DraftManager::approve() — CommitEngine 例外の透過');

$lock_e2e = 'LockPassthrough';
$lock_content = "ロック透過テスト\n";
$lock_file    = $wiki_dir . '/' . strtoupper(bin2hex($lock_e2e)) . '.txt';
file_put_contents($lock_file, $lock_content);

// ページをロック
$ledger->acquireLock($lock_e2e, 'external-lock', 60, $now);

$lock_draft_id = $ledger->createDraft(
    $lock_e2e, sha1($lock_content), "更新内容\n",
    'ai-bot', $now, $now + 3600
);

try {
    $dm->approve($lock_draft_id, 'human', $now);
    ok(false, 'CommitEngine の 423 が DraftManager を突き抜ける');
} catch (ApiException $e) {
    ok($e->status === 423, 'DraftManager::approve() が 423 を透過する');
}

// 下書きは open のまま（承認失敗したので）
$lock_draft = $ledger->getDraft($lock_draft_id);
ok($lock_draft['status'] === 'open', '承認失敗時に下書きは open のまま');

$ledger->releaseLock($lock_e2e, 'external-lock');

// ──────────────────────────────────────────────────
// 16. frozen ページのモック（is_freeze() が true）
// ──────────────────────────────────────────────────
section('16. 凍結ページのチェック（PukiWiki is_freeze() モック）');

// is_freeze() がすでに定義されていなければモックを定義
if (!function_exists('is_freeze')) {
    function is_freeze(string $page): bool
    {
        return $page === 'FrozenPage'; // FrozenPage だけ凍結
    }
}

if (!function_exists('is_editable')) {
    function is_editable(string $page): bool
    {
        return true;
    }
}

$frozen_page    = 'FrozenPage';
$frozen_content = "凍結ページ\n";
$frozen_file    = $wiki_dir . '/' . strtoupper(bin2hex($frozen_page)) . '.txt';
file_put_contents($frozen_file, $frozen_content);

try {
    $engine->commit($frozen_page, "新しい内容\n", sha1($frozen_content), 'actor');
    ok(false, '凍結ページは ApiException を投げる');
} catch (ApiException $e) {
    ok($e->status === 403, '凍結ページは 403');
    ok($e->error_code === 'page_frozen', 'error_code が page_frozen');
}

// ファイルが変更されていないことを確認
ok(file_get_contents($frozen_file) === $frozen_content, '凍結ページは変更されない');

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
