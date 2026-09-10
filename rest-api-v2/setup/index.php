<?php
// 設定前でも管理者認証まで実行し、通常 API の初期化は行わない。
if (PHP_SAPI === 'cli') exit("ブラウザーで開いてください。\n");
ini_set('display_errors','0');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
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
?>
<!doctype html><html lang="ja"><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>PukiWiki API 初期設定</title>
<style>body{font-family:system-ui,sans-serif;background:#f3f5f7;color:#1c2b36;margin:0;padding:24px}main{max-width:780px;margin:auto}section{background:white;border:1px solid #d7dfe5;border-radius:12px;padding:24px;margin:20px 0}label{display:block;margin:16px 0 6px}input:not([type=checkbox]),select,button,textarea{font:inherit;padding:10px;box-sizing:border-box;max-width:100%}input:not([type=checkbox]),select,textarea{width:100%}button{background:#145b7b;color:white;border:0;border-radius:6px;margin-top:16px;cursor:pointer}code{overflow-wrap:anywhere}.error{color:#a02020}.ok{color:#17633c}small{color:#52616d}table{width:100%;text-align:left}td{padding:8px;overflow-wrap:anywhere}</style>
<main><h1>PukiWiki API 2.2</h1><p>FTPで設置したら、この画面で準備できます。コマンド操作は不要です。</p>
<?php if ($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif ?>
<?php if ($notice): ?><p class="ok" role="status"><?=h($notice)?></p><?php endif ?>
<?php if (!$logged): ?>
<section><h2>管理者としてログイン</h2><p>PukiWikiの凍結・解除などに使う管理者パスワードを入力してください。</p><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="login"><label for="pass">管理者パスワード</label><input id="pass" name="password" type="password" autocomplete="current-password" required maxlength="512"><button>ログイン</button></form></section>
<?php elseif ($keys === null): ?>
<section><h2>1. 非公開の保存先を設定</h2><p>キーと履歴をWeb公開領域の外に保存します。候補が使えない場合だけ、FTPで作成した保存先を指定してください。</p><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="initialize"><label for="dir">保存先</label><input id="dir" name="data_dir" value="<?=h(Setup::candidate($docRoot,$pkwk_root))?>" required><button>検査して設定する</button></form></section>
<?php else: ?>
<section><h2>接続の準備</h2><p class="ok">PukiWikiの読込・非公開の保存先への書込を確認しました。</p><label>Claude Desktopに入力するAPI URL</label><textarea readonly rows="2"><?=h($apiUrl)?></textarea>
<?php if ($raw): ?><label>APIキー（今回のみ表示）</label><textarea readonly rows="2" autocomplete="off"><?=h($raw)?></textarea><?php endif ?>
<p>配布物の <code>pukiwiki-mcp.mcpb</code> をClaude Desktopにインストールし、このURLとキーを入力してください。添付のアップロード・保存を使う場合は、拡張の設定で「添付用フォルダ」も選択します。</p><p>Claudeに「Wikiの接続状態を確認して」と依頼すると、認証と対応機能を確認できます。続けて既存のページを読むよう依頼してください。ここでの検査はHTTP経由の接続成功やページ編集成功を保証するものではありません。</p></section>
<section><h2>2. 接続キーを発行</h2><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="create"><label for="label">キー名</label><input id="label" name="label" value="claude-desktop" pattern="[A-Za-z0-9_.\-]{1,64}" required><label for="scope">許可する操作</label><select id="scope" name="scope"><option value="read">閲覧・検索・バックアップ・添付の取得</option><option value="write">上記に加えてページの作成・編集</option></select><label for="user">Wikiユーザー</label><select id="user" name="wiki_user"><option value="">匿名（閲覧・編集認証のないサイト用）</option><?php foreach (($GLOBALS['auth_users']??[]) as $u=>$_): ?><option value="<?=h($u)?>"><?=h($u)?></option><?php endforeach ?></select><label><input type="checkbox" name="attachments">添付のアップロード・削除も許可する（編集権限が必要）</label><p><small>この許可は、管理者専用の添付操作を選択したWikiユーザーの編集可能ページについてAPIキーに委任します。既存キーには自動付与されません。削除した添付は履歴に残ります。</small></p><button>キーを発行</button></form></section>
<section><h2>発行済みキー</h2><table><tr><th>名前</th><th>権限</th><th>操作</th></tr><?php foreach ($keys as $key): ?><tr><td><?=h($key['label'])?></td><td><?=h($key['scope'])?><?=!empty($key['attachments_write'])?' / 添付変更':''?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="label" value="<?=h($key['label'])?>"><button>失効</button></form></td></tr><?php endforeach ?></table><p>再発行は古いキーを失効してから、新しいキーを発行してください。</p></section>
<?php endif ?>
<?php if ($logged): ?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="logout"><button>ログアウト</button></form><?php endif ?>
</main></html>
