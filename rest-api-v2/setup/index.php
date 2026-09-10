<?php
// 設定前でも管理者認証まで実行し、通常 API の初期化は行わない。
if (PHP_SAPI === 'cli') exit("ブラウザーで開いてください。\n");
ini_set('display_errors','0');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$loopback = in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true);
if (!$https && !$loopback) { http_response_code(403); exit('HTTPSのURLで初期設定画面を開いてください。'); }
session_name('PKWK_REST_ADMIN');
session_set_cookie_params(['httponly'=>true,'secure'=>$https,'samesite'=>'Strict','path'=>str_replace('index.php','',$_SERVER['SCRIPT_NAME'])]);
ini_set('session.use_strict_mode','1');
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$post = $_POST; // 本体 init.php の正規化前に退避
$method = $_SERVER['REQUEST_METHOD'];
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'];
$error = ''; $notice = ''; $keys = null;
try {
    define('PKWK_REST_SETUP',true);
    require __DIR__ . '/../bootstrap.php';
    require_once __DIR__ . '/../lib/Setup.php';
    $stamp = hash('sha256',(string)($GLOBALS['adminpass'] ?? ''));
    $logged = ($_SESSION['expires'] ?? 0) > time() && hash_equals($stamp,(string)($_SESSION['admin_stamp'] ?? ''));
    if ($method === 'POST') {
        if (!is_string($post['csrf'] ?? null) || !hash_equals($_SESSION['csrf'],$post['csrf'])) throw new RuntimeException('画面の有効期限が切れました。再表示して操作してください。');
        $action = $post['action'] ?? '';
        if ($action === 'login') {
            if (!is_string($post['password'] ?? null) || !pkwk_login($post['password'])) throw new RuntimeException('管理者パスワードを確認してください。');
            session_regenerate_id(true);
            $_SESSION['expires'] = time()+900; $_SESSION['admin_stamp'] = $stamp;
            $_SESSION['csrf'] = bin2hex(random_bytes(32)); $logged = true;
        } else {
            if (!$logged) throw new RuntimeException('管理者としてログインしてください。');
            if ($action === 'logout') { $_SESSION = []; session_destroy(); $logged = false; }
            elseif ($action === 'initialize') {
                Setup::initialize(is_string($post['data_dir'] ?? null) ? $post['data_dir'] : '',$docRoot);
                $notice = '保存先を設定しました。続けて接続キーを発行してください。';
            } elseif ($action === 'create') {
                $user = is_string($post['wiki_user'] ?? null) ? $post['wiki_user'] : '';
                if ($user !== '' && !array_key_exists($user,$GLOBALS['auth_users'] ?? [])) throw new RuntimeException('実在するWikiユーザーを選択してください。');
                if ($user === '' && (!empty($GLOBALS['read_auth']) || !empty($GLOBALS['edit_auth']))) throw new RuntimeException('閲覧・編集認証があるためWikiユーザーを選択してください。');
                $store = Setup::keys($docRoot);
                $raw = $store->create((string)($post['label'] ?? ''),(string)($post['scope'] ?? ''),$user,isset($post['attachments']));
                $_SESSION['new_key'] = $raw;
                $_SESSION['notice'] = '接続キーを発行しました。キーの表示は今回限りです。';
                header('Location: '.$scriptName,true,303); exit;
            } elseif ($action === 'revoke') { Setup::keys($docRoot)->revoke((string)($post['label'] ?? '')); $notice='キーを失効しました。'; }
        }
    }
    if ($logged && (is_file(dirname(__DIR__).'/config.local.php') || getenv('PKWK_REST_DATA'))) {
        $keys = Setup::keys($docRoot)->all();
    }
} catch (Throwable $e) { $error = $e->getMessage(); }
// 未認証画面にはパスや構成情報を出さない。
$logged = $logged ?? false;
if (!$logged && $error !== '') $error = 'ログインできません。設置場所・HTTPS接続・管理者パスワードを確認してください。';
$csrf = h($_SESSION['csrf'] ?? '');
$raw = $logged ? ($_SESSION['new_key'] ?? '') : '';
$notice = $_SESSION['notice'] ?? $notice;
unset($_SESSION['new_key'],$_SESSION['notice']);
$apiPath = dirname(dirname($scriptName)).'/api/v1/index.php';
$host = $_SERVER['HTTP_HOST'] ?? '';
$apiUrl = preg_match('/^[a-zA-Z0-9.\-\[\]:]+$/D',$host) ? ($https?'https://':'http://').$host.$apiPath : $apiPath;
$wikiPath = rtrim(dirname($scriptName, 3), '/') . '/';
$skinPath = defined('SKIN_DIR') ? rtrim(SKIN_DIR, '/') . '/' : 'skin/';
$skinUrl = str_starts_with($skinPath, '/') ? $skinPath : $wikiPath . $skinPath;
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>API設定 - <?=h($page_title ?? 'PukiWiki')?></title>
<link rel="stylesheet" href="<?=h($skinUrl)?>pukiwiki.css">
<style>
.rest-setup { max-width:800px; margin:1.5em auto; padding:0 .5em; }
.rest-setup .field { margin:1em 0; }
.rest-setup .field > label { display:block; margin-bottom:.3em; }
.rest-setup input, .rest-setup select, .rest-setup textarea, .rest-setup button { font:inherit; box-sizing:border-box; max-width:100%; }
.rest-setup input[type=text], .rest-setup input[type=password] { width:26em; }
.rest-setup input.path, .rest-setup textarea { width:100%; }
.rest-setup textarea { resize:vertical; }
.rest-setup section { margin:2em 0; }
.rest-setup .error { color:#a00; }
.rest-setup .style_table { width:100%; }
.rest-setup .style_td { overflow-wrap:anywhere; }
.rest-setup .actions { margin:1em 0; }
</style>
</head>
<body>
<main class="rest-setup">
<div id="header">
<p><a href="<?=h($wikiPath)?>"><?=h($page_title ?? 'PukiWiki')?>へ戻る</a></p>
<h1 class="title">API設定</h1>
</div>
<?php if ($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif ?>
<?php if ($notice): ?><p role="status"><?=h($notice)?></p><?php endif ?>
<?php if (!$logged): ?>
<section aria-labelledby="login-heading">
<h2 id="login-heading">管理者としてログイン</h2>
<p>PukiWikiの凍結・解除に使う管理者パスワードを入力してください。</p>
<form method="post">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<input type="hidden" name="action" value="login">
<div class="field"><label for="pass">管理者パスワード</label><input id="pass" name="password" type="password" autocomplete="current-password" required maxlength="512"></div>
<div class="actions"><button type="submit">ログイン</button></div>
</form>
</section>
<?php elseif ($keys === null): ?>
<section aria-labelledby="directory-heading">
<h2 id="directory-heading">非公開の保存先を設定</h2>
<p>APIキーと履歴をWeb公開領域の外に保存します。通常は候補のパスをそのまま使えます。書き込めない場合は、FTPで作成した非公開フォルダを指定してください。</p>
<form method="post">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<input type="hidden" name="action" value="initialize">
<div class="field"><label for="dir">保存先</label><input class="path" type="text" id="dir" name="data_dir" value="<?=h(Setup::candidate($docRoot,$pkwk_root))?>" required></div>
<div class="actions"><button type="submit">保存先を設定する</button></div>
</form>
</section>
<?php else: ?>
<section aria-labelledby="connection-heading">
<h2 id="connection-heading">接続情報</h2>
<div class="field"><label for="api-url">API URL</label><textarea id="api-url" readonly rows="2" spellcheck="false"><?=h($apiUrl)?></textarea></div>
<?php if ($raw): ?>
<div class="field"><label for="api-key">APIキー（今回のみ表示）</label><textarea id="api-key" readonly rows="2" autocomplete="off" spellcheck="false"><?=h($raw)?></textarea></div>
<?php endif ?>
<p>Claude Desktopに配布物の <code>pukiwiki-mcp.mcpb</code> を追加し、API URLと発行したキーを入力します。添付を扱う場合は、拡張の設定で「添付用フォルダ」も指定してください。</p>
<p>設定後、Claudeに「Wikiの接続状態を確認して」と依頼し、既存のページが読めることを確認してください。</p>
</section>
<section aria-labelledby="create-heading">
<h2 id="create-heading">接続キーを発行</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<input type="hidden" name="action" value="create">
<div class="field"><label for="label">キー名</label><input type="text" id="label" name="label" value="claude-desktop" pattern="[A-Za-z0-9_.\-]{1,64}" required></div>
<div class="field"><label for="scope">許可する操作</label><select id="scope" name="scope"><option value="read">閲覧のみ</option><option value="write">閲覧とページの作成・編集</option></select></div>
<p class="small">閲覧には検索・バックアップ・添付ファイルの取得も含みます。</p>
<div class="field"><label for="user">Wikiユーザー</label><select id="user" name="wiki_user"><option value="">匿名（認証のないサイト用）</option><?php foreach (($GLOBALS['auth_users']??[]) as $u=>$_): ?><option value="<?=h($u)?>"><?=h($u)?></option><?php endforeach ?></select></div>
<div class="field"><label><input type="checkbox" name="attachments"> 添付のアップロード・削除も許可する</label></div>
<p class="small">添付の変更には編集権限が必要です。選択したWikiユーザーが編集できるページについて、管理者専用の添付操作をこのキーに許可します。既存キーには自動付与されません。削除した添付は履歴に残ります。</p>
<div class="actions"><button type="submit">キーを発行</button></div>
</form>
</section>
<section aria-labelledby="keys-heading">
<h2 id="keys-heading">発行済みキー</h2>
<?php if (!$keys): ?><p>発行済みのキーはありません。</p><?php else: ?>
<table class="style_table">
<thead><tr><th scope="col" class="style_th">名前</th><th scope="col" class="style_th">権限</th><th scope="col" class="style_th">操作</th></tr></thead>
<tbody>
<?php foreach ($keys as $key): ?>
<tr><td class="style_td"><?=h($key['label'])?></td><td class="style_td"><?=h($key['scope'])?><?=!empty($key['attachments_write'])?' / 添付変更':''?></td><td class="style_td"><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="label" value="<?=h($key['label'])?>"><button type="submit" aria-label="<?=h($key['label'])?>を失効">失効</button></form></td></tr>
<?php endforeach ?>
</tbody>
</table>
<?php endif ?>
<p>キーの値は再表示できません。再発行するときは、古いキーを失効してから新しいキーを発行してください。</p>
</section>
<?php endif ?>
<?php if ($logged): ?>
<hr>
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="logout"><button type="submit">ログアウト</button></form>
<?php endif ?>
</main>
</body>
</html>
