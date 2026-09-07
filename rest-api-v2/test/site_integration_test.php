<?php
/**
 * サイト統合テスト — 実運用サイト固有の設定・プラグインとの噛み合わせを検証する。
 *
 * integration_test.php が「素の 1.5.4 に対する REST API の正しさ」を見るのに対し、
 * こちらは「改造済みサイトに載せたときに壊れないか」を見る。
 * 想定している改造（無ければ該当セクションは自動でスキップする）:
 *
 *   A. $edit_auth = 1 + $edit_auth_pages が全ページに掛かっている
 *      → wiki_user 付きキーだけが書ける。無しは fail-closed
 *   B. plugin/md.inc.php（Markdown プラグイン v0.4+）
 *      → #md ページの保存で $str_rules マクロが破壊されない
 *
 * 実行方法（使い捨てのコピーに対して実行すること）:
 *   PKWK_ROOT=/tmp/site-test php rest-api-v2/test/site_integration_test.php
 *
 * @version v2.1
 */
declare(strict_types=1);

// CLI 専用。Web から到達できる場所に置かれても実行させない
// （integration 系は実 wiki に書き込むため、公開ディレクトリでの誤実行は破壊的）。
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/testlib.php';

$pkwk_root = getenv('PKWK_ROOT');
if ($pkwk_root === false || $pkwk_root === '') {
    fwrite(STDERR, "PKWK_ROOT を設定してください（使い捨ての PukiWiki コピーを指すこと）。\n");
    exit(2);
}
if (!is_file($pkwk_root . '/pukiwiki.ini.php')) {
    fwrite(STDERR, "PKWK_ROOT に pukiwiki.ini.php が見つかりません: {$pkwk_root}\n");
    exit(2);
}

putenv("PKWK_REST_DATA={$pkwk_root}/rest-api-v2-testdata");

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../mcp/McpHandler.php';

$EMPTY = PageStore::EMPTY_SHA1;

// このテストが作るページ。同じコピーに対して繰り返し実行できるよう、開始時に消す。
// （API に削除機能は無いので、テストの後始末はファイルを直接消す）
$TEST_PAGES = [
    'サイト統合テスト/認可',
    'サイト統合テスト/拒否',
    'サイト統合テスト/マークダウン',
    'サイト統合テスト/プキウィキ記法',
    'サイト統合テスト/凍結',
    'サイト統合テスト/閲覧制限',
];
foreach ($TEST_PAGES as $p) {
    @unlink($REST_PAGES->filePath($p));
}
if (function_exists('is_freeze')) {
    is_freeze('', true); // static キャッシュを捨てる
}
clearstatcache();

/** このサイトで $edit_auth が全ページに掛かっているか */
$edit_auth_on = !empty($GLOBALS['edit_auth']) && function_exists('is_page_writable');
$read_auth_on = !empty($GLOBALS['read_auth']) && function_exists('_is_page_accessible');
/** $auth_users の先頭ユーザー（wiki_user として使う） */
$wiki_user = '';
foreach (($GLOBALS['auth_users'] ?? []) as $name => $_) {
    $wiki_user = (string)$name;
    break;
}

// =========================================================================
section('1. サイト設定の把握');

ok($REST_PKWK_LOADED, 'PukiWiki 本体をロードできた（改造済みサイトでも Fatal しない）');
echo "     edit_auth={$GLOBALS['edit_auth']} / read_auth={$GLOBALS['read_auth']}"
    . ' / wiki_user=' . ($wiki_user !== '' ? $wiki_user : '(none)')
    . ' / md=' . (function_exists('exist_plugin') && exist_plugin('md') ? 'yes' : 'no') . "\n";

// =========================================================================
section('2. $edit_auth: wiki_user 無しキーは fail-closed');

$page_a = 'サイト統合テスト/認可';
if (!$edit_auth_on) {
    echo "  - スキップ（このサイトは \$edit_auth 無効）\n";
} else {
    expect_api_error(
        fn() => $REST_PAGES->write($page_a, "*認可テスト\n本文\n", $EMPTY, 'nouser-key'),
        403,
        'wiki_user 無しキーは 403 edit_forbidden'
    );
    ok(!is_file($REST_PAGES->filePath($page_a)), '拒否されたページはファイルが作られていない');
}

// =========================================================================
section('3. $edit_auth: wiki_user 付きキーは書ける');

if (!$edit_auth_on || $wiki_user === '') {
    echo "  - スキップ（\$edit_auth 無効、または \$auth_users が空）\n";
} else {
    $r = $REST_PAGES->write($page_a, "*認可テスト\n本文\n", $EMPTY, 'editor-key', '', $wiki_user);
    ok($r['is_new'] === true, "wiki_user='{$wiki_user}' 付きキーで新規作成できる");
    ok(is_file($REST_PAGES->filePath($page_a)), 'ページファイルが実在する');

    $stored = (string)file_get_contents($REST_PAGES->filePath($page_a));
    ok(sha1($stored) === $r['new_sha1'], 'new_sha1 が実ファイルと一致する');

    // #author 行に wiki ユーザーとキーのラベルの両方が残ること
    ok(str_contains($stored, '#author'), '#author 行が付与される');
    ok(str_contains($stored, $wiki_user), "#author に wiki ユーザー '{$wiki_user}' が入る");
    ok(str_contains($stored, 'editor-key'),
        '#author にキーのラベルが残る（どのキーが書いたか追跡できる）');

    // 続けて更新できる
    $r2 = $REST_PAGES->write($page_a, "*認可テスト\n更新\n", $r['new_sha1'], 'editor-key', '', $wiki_user);
    ok($r2['changed'] === true, '返却 sha1 を base にした連続更新が 409 にならない');
}

// =========================================================================
section('4. 例外パスでグローバルが復元される');

$before = [
    $GLOBALS['auth_user']          ?? null,
    $GLOBALS['auth_user_fullname'] ?? null,
    $GLOBALS['auth_user_groups']   ?? null,
];

// CAS 衝突（存在するページに誤った base_sha1）
if ($edit_auth_on && $wiki_user !== '' && is_file($REST_PAGES->filePath($page_a))) {
    expect_api_error(
        fn() => $REST_PAGES->write($page_a, "*衝突\n", str_repeat('0', 40), 'editor-key', '', $wiki_user),
        409,
        'CAS 衝突は 409'
    );
}
// 認可拒否
if ($edit_auth_on) {
    expect_api_error(
        fn() => $REST_PAGES->write('サイト統合テスト/拒否', "*拒否\n", $EMPTY, 'nouser-key'),
        403,
        '認可拒否は 403'
    );
}

$after = [
    $GLOBALS['auth_user']          ?? null,
    $GLOBALS['auth_user_fullname'] ?? null,
    $GLOBALS['auth_user_groups']   ?? null,
];
ok($before === $after, '例外パスを通っても $auth_user / $auth_user_groups が元に戻っている');

// =========================================================================
section('5. Markdown ページの保存が本文を壊さない');

$has_md = function_exists('exist_plugin') && exist_plugin('md') && function_exists('md_page_write');
if (!$has_md) {
    echo "  - スキップ（md.inc.php / md_page_write() が無い）\n";
} elseif ($edit_auth_on && $wiki_user === '') {
    echo "  - スキップ（書き込みできる wiki_user が無い）\n";
} else {
    $md_page = 'サイト統合テスト/マークダウン';
    // '*' 始まりの行（Markdown の箇条書き・強調）と $str_rules マクロを両方含む本文
    $md_body = "#md\n"
        . "# 見出し\n"
        . "* 箇条書き1\n"
        . "* 箇条書き2\n"
        . "\n"
        . "現在時刻マクロ: &now;\n"
        . "日付マクロ: &date;\n";

    $r = $REST_PAGES->write($md_page, $md_body, $EMPTY, 'editor-key', '', $wiki_user);
    $stored = (string)file_get_contents($REST_PAGES->filePath($md_page));

    ok(!preg_match('/\[#[0-9a-z]{8}\]/', $stored),
        '見出しアンカー [#xxxxxxxx] が混入しない');
    ok(str_contains($stored, '&now;'),
        '&now; マクロが実値に置換されない（★不可逆な破壊の防止★）');
    ok(str_contains($stored, '&date;'),
        '&date; マクロが実値に置換されない');
    ok(str_contains($stored, "* 箇条書き1"),
        'Markdown の箇条書き行がそのまま保たれる');
    ok(sha1($stored) === $r['new_sha1'], 'Markdown ページでも new_sha1 が実ファイルと一致する');

    // 非 Markdown ページでは従来どおり PukiWiki の正規化が働くこと
    $pk_page = 'サイト統合テスト/プキウィキ記法';
    $REST_PAGES->write($pk_page, "*見出し\n本文\n", $EMPTY, 'editor-key', '', $wiki_user);
    $pk_stored = (string)file_get_contents($REST_PAGES->filePath($pk_page));
    ok((bool)preg_match('/\*見出し \[#[0-9a-z]{8}\]/', $pk_stored),
        '非 Markdown ページには従来どおり見出しアンカーが付く（挙動を変えていない）');
}

// =========================================================================
section('6. 凍結ページは wiki_user 付きでも書けない');

$frozen = 'サイト統合テスト/凍結';
if ($edit_auth_on && $wiki_user === '') {
    echo "  - スキップ（書き込みできる wiki_user が無い）\n";
} else {
    $REST_PAGES->write($frozen, "*凍結テスト\n本文\n", $EMPTY, 'editor-key', '', $wiki_user);
    $path = $REST_PAGES->filePath($frozen);
    file_put_contents($path, "#freeze\n" . (string)file_get_contents($path));
    clearstatcache(true, $path);
    is_freeze($frozen, true); // is_freeze() の static キャッシュをクリア

    expect_api_error(
        fn() => $REST_PAGES->write(
            $frozen,
            "*上書き\n",
            sha1((string)file_get_contents($REST_PAGES->filePath($frozen))),
            'editor-key',
            '',
            $wiki_user
        ),
        403,
        '#freeze ページは wiki_user 付きでも 403'
    );
}

// =========================================================================
section('7. $read_auth: 読めないページは書けない');

// $read_auth と $edit_auth は同じ _is_page_accessible() を使う。wiki_user 付きキーが
// $edit_auth を通せるようになった以上、「読めないページに書ける」穴が開いていないか
// を明示的に確認する（開いていると旧内容がスナップショットに退避され漏えいする）。
$saved_read_auth       = $GLOBALS['read_auth'] ?? 0;
$saved_read_auth_pages = $GLOBALS['read_auth_pages'] ?? [];
$secret = 'サイト統合テスト/閲覧制限';

try {
    // まず通常状態で作成しておく
    if (!$edit_auth_on || $wiki_user !== '') {
        $REST_PAGES->write($secret, "*秘密\n元の内容\n", $EMPTY, 'editor-key', '', $wiki_user);
    }
    $secret_file = $REST_PAGES->filePath($secret);
    $before_body = is_file($secret_file) ? (string)file_get_contents($secret_file) : '';

    // このページだけ、誰も閲覧できないグループに制限する
    $GLOBALS['read_auth']       = 1;
    $GLOBALS['read_auth_pages'] = ['#サイト統合テスト/閲覧制限#' => 'nobody-group'];

    expect_api_error(
        fn() => $REST_PAGES->write(
            $secret,
            "*秘密\n上書きされた\n",
            sha1($before_body),
            'editor-key',
            '',
            $wiki_user
        ),
        403,
        '閲覧できないページへの書き込みは 403（read_forbidden）'
    );

    clearstatcache(true, $secret_file);
    ok(
        is_file($secret_file) && (string)file_get_contents($secret_file) === $before_body,
        '拒否されたページの内容は無傷'
    );

    // 読める状態に戻せば書ける（回帰確認）
    $GLOBALS['read_auth']       = $saved_read_auth;
    $GLOBALS['read_auth_pages'] = $saved_read_auth_pages;
    if (!$edit_auth_on || $wiki_user !== '') {
        $r = $REST_PAGES->write($secret, "*秘密\n再開\n", sha1($before_body), 'editor-key', '', $wiki_user);
        ok($r['changed'] === true, 'read_auth を戻すと再び書ける（回帰確認）');
    }
} finally {
    $GLOBALS['read_auth']       = $saved_read_auth;
    $GLOBALS['read_auth_pages'] = $saved_read_auth_pages;
}

// =========================================================================
section('8. 読み取りはサイト設定でも通る');

// 一覧は PukiWiki 本体（lib/html.php: is_page_readable）と同じく $read_auth で
// フィルタされる。$read_auth が全ページに掛かるサイトでは identity 必須。
if ($read_auth_on && $wiki_user !== '') {
    $REST_PAGES->setIdentity('list-key', $wiki_user);
}
$listed = $REST_PAGES->listPages(5, 0);
ok(!empty($listed['pages']), 'ページ一覧を取得できる（identity 反映）');

if ($read_auth_on) {
    $REST_PAGES->setIdentity('anon-key', '');
    $anon = $REST_PAGES->listPages(5, 0);
    ok(empty($anon['pages']), 'wiki_user 無しの一覧は空（ページ名を漏らさない）');
}

summary();
