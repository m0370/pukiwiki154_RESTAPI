<?php
/**
 * AI 下書き 人間審査ページ
 *
 * AI（Claude 等）が wiki_create_draft で作成した下書きを、
 * 人間が差分で確認して承認 or 却下するための Web UI。
 *
 * URL: /rest-api/api/review.php?id={draft_id}&token={api_key}
 *
 * GET  → 差分プレビュー付きの審査画面を表示
 * POST → フォームから action=approve または action=reject を受け付けて処理後にリダイレクト
 *
 * 認証: クエリパラメータ token= でAPIキーを渡す（draft:approve スコープ必須）。
 * URL に含めることでブラウザから直接クリックできる設計（メール通知リンク等）。
 * @version v0.1
 */
declare(strict_types=1);

chdir(dirname(__DIR__)); // rest-api/ を基点に

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/CommitEngine.php';
require_once __DIR__ . '/../lib/DraftManager.php';
require_once __DIR__ . '/../lib/DiffEngine.php';
require_once __DIR__ . '/../lib/Reconciler.php';

$id     = (int)($_GET['id']    ?? $_POST['draft_id'] ?? 0);
$token  = trim($_GET['token']  ?? $_POST['token']    ?? '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$base_url = dirname($_SERVER['SCRIPT_NAME'] ?? '/review.php');

// -------------------------------------------------------------------------
// 認証
// -------------------------------------------------------------------------
$auth   = new Auth($REST_LEDGER);
$key_id = null;
$label  = 'human';

try {
    $key    = $auth->authenticate(
        ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
        'draft:approve',
        $_SERVER['REMOTE_ADDR'] ?? ''
    );
    $key_id = $key['id'];
    $label  = $key['label'] ?? 'human';
} catch (ApiException $e) {
    http_response_code($e->status);
    render_error($e->status, '認証エラー', $e->getMessage());
    exit;
}

$draftMgr = new DraftManager($REST_LEDGER, $REST_ENGINE, $REST_WIKI_DIR);

// -------------------------------------------------------------------------
// POST 処理（承認 or 却下）
// -------------------------------------------------------------------------
if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $now    = time();
    $flash  = '';
    $flash_type = 'success';

    try {
        if ($action === 'approve') {
            $draftMgr->approve($id, $label, $now);
            $flash = "下書き #{$id} を承認してページへ公開しました。";
        } elseif ($action === 'reject') {
            $draftMgr->reject($id, $label, $reason, $now);
            $flash = "下書き #{$id} を却下しました。";
        } else {
            $flash      = '不明なアクションです。';
            $flash_type = 'error';
        }
    } catch (ApiException $e) {
        $flash      = "エラー ({$e->status}): {$e->getMessage()}";
        $flash_type = 'error';
    }

    // PRG パターンでリダイレクト（二重送信防止）
    $sep = str_contains("{$base_url}/review.php?id={$id}&token={$token}", '?') ? '&' : '?';
    header('Location: ' . "{$base_url}/review.php?id={$id}&token=" . urlencode($token)
        . '&flash=' . urlencode($flash)
        . '&flash_type=' . urlencode($flash_type)
    );
    exit;
}

// -------------------------------------------------------------------------
// GET 処理（差分プレビュー表示）
// -------------------------------------------------------------------------
if ($id <= 0) {
    http_response_code(400);
    render_error(400, 'パラメータエラー', 'id パラメータが必要です。');
    exit;
}

try {
    $data = $draftMgr->getWithDiff($id);
} catch (ApiException $e) {
    http_response_code($e->status);
    render_error($e->status, 'エラー', $e->getMessage());
    exit;
}

$draft    = $data['draft'];
$diff_html = $data['diff_html'];
$stats    = $data['diff_stats'];
$conflict = $data['is_conflict'];

$flash      = htmlspecialchars($_GET['flash']      ?? '', ENT_QUOTES, 'UTF-8');
$flash_type = htmlspecialchars($_GET['flash_type'] ?? '', ENT_QUOTES, 'UTF-8');

$status_label = match ($draft['status']) {
    'open'      => '<span style="color:#1a7f1a">● 審査待ち</span>',
    'published' => '<span style="color:#1565c0">✔ 公開済み</span>',
    'rejected'  => '<span style="color:#c62828">✘ 却下済み</span>',
    'expired'   => '<span style="color:#888">⏱ 失効</span>',
    default     => htmlspecialchars($draft['status'], ENT_QUOTES, 'UTF-8'),
};

$expires_str = $draft['expires_at']
    ? date('Y-m-d H:i:s', (int)$draft['expires_at'])
    : 'なし';

$form_token = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
$page_enc   = htmlspecialchars($draft['page'],  ENT_QUOTES, 'UTF-8');
$owner_enc  = htmlspecialchars($draft['owner'], ENT_QUOTES, 'UTF-8');

$meta = is_array($draft['meta']) ? $draft['meta'] : [];
$meta_reason = htmlspecialchars((string)($meta['reason'] ?? ''), ENT_QUOTES, 'UTF-8');

?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>下書き審査 #<?= $id ?> — <?= $page_enc ?></title>
<style>
:root {
  --green:  #1a7f1a;
  --red:    #c62828;
  --blue:   #1565c0;
  --bg:     #f8f9fa;
  --border: #dee2e6;
}
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
       background: var(--bg); color: #212529; margin: 0; padding: 1.5rem; }
h1 { font-size: 1.4rem; margin-bottom: 0.5rem; }
h2 { font-size: 1.1rem; border-bottom: 2px solid var(--border); padding-bottom: .3rem; margin-top: 1.5rem; }
table { border-collapse: collapse; width: 100%; margin-bottom: 1rem; }
th, td { border: 1px solid var(--border); padding: .4rem .7rem; text-align: left; }
th { background: #e9ecef; width: 130px; }
.meta-table td code { font-size: .85rem; }
.conflict-warn { background: #fff3cd; border: 1px solid #ffc107;
                 border-radius: 4px; padding: .6rem 1rem; margin-bottom: 1rem; }
.flash-box { padding: .6rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
.flash-success { background: #d4edda; border: 1px solid #28a745; }
.flash-error   { background: #f8d7da; border: 1px solid #dc3545; }
/* diff */
pre.diff { background: #fff; border: 1px solid var(--border); padding: .7rem 1rem;
           font-family: "Fira Mono", "Consolas", monospace; font-size: .8rem;
           overflow: auto; max-height: 600px; margin: 0; }
span.diff-header  { color: #6c757d; }
span.diff-hunk    { color: #0d6efd; font-weight: bold; }
span.diff-added   { background: #e6ffed; display: block; }
span.diff-removed { background: #ffeef0; display: block; }
span.diff-context { display: block; }
p.diff-none { color: #6c757d; font-style: italic; }
/* body preview */
pre.body-preview { background: #fff; border: 1px solid var(--border);
                   padding: .7rem 1rem; font-family: monospace; font-size: .82rem;
                   overflow: auto; max-height: 400px; white-space: pre-wrap; }
/* action panel */
.action-panel { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-top: 1rem; }
.action-form  { background: #fff; border: 1px solid var(--border); border-radius: 6px;
                padding: 1rem 1.2rem; flex: 1 1 280px; }
.action-form h3 { margin: 0 0 .6rem; font-size: 1rem; }
textarea { width: 100%; height: 4rem; font-size: .88rem; padding: .4rem; border: 1px solid #ccc;
           border-radius: 3px; resize: vertical; }
button.btn { display: inline-block; padding: .45rem 1.2rem; border: none; border-radius: 4px;
             cursor: pointer; font-size: .95rem; font-weight: 600; color: #fff; }
button.btn-approve { background: var(--green); }
button.btn-approve:hover { background: #155715; }
button.btn-reject  { background: var(--red); }
button.btn-reject:hover  { background: #9b1e1e; }
button:disabled { opacity: .5; cursor: not-allowed; }
.stat-badge { display: inline-block; padding: .15rem .5rem; border-radius: 3px;
              font-size: .8rem; font-weight: bold; margin-left: .3rem; }
.stat-added   { background: #e6ffed; color: var(--green); }
.stat-removed { background: #ffeef0; color: var(--red); }
</style>
</head>
<body>

<h1>下書き審査 #<?= $id ?> — <code><?= $page_enc ?></code></h1>

<?php if ($flash !== ''): ?>
<div class="flash-box flash-<?= $flash_type === 'error' ? 'error' : 'success' ?>">
  <?= $flash ?>
</div>
<?php endif; ?>

<?php if ($conflict): ?>
<div class="conflict-warn">
  <strong>⚠ 競合</strong>：下書き作成後にページが更新されています。
  現在のページ内容と下書きの base_sha1 が一致しません。
  承認すると <strong>現在の変更を上書き</strong>する可能性があります。
</div>
<?php endif; ?>

<!-- メタ情報 -->
<h2>下書き情報</h2>
<table class="meta-table">
<tr><th>ページ</th><td><?= $page_enc ?></td></tr>
<tr><th>作成者</th><td><?= $owner_enc ?></td></tr>
<tr><th>ステータス</th><td><?= $status_label ?></td></tr>
<tr><th>Base SHA1</th><td><code><?= htmlspecialchars($draft['base_sha1'], ENT_QUOTES, 'UTF-8') ?></code><?=
    $conflict ? ' <strong style="color:var(--red)">（競合）</strong>' : ' <span style="color:var(--green)">（一致）</span>'
?></td></tr>
<tr><th>現在の SHA1</th><td><code><?= htmlspecialchars($data['current_sha1'], ENT_QUOTES, 'UTF-8') ?></code></td></tr>
<tr><th>作成日時</th><td><?= date('Y-m-d H:i:s', (int)$draft['created_at']) ?></td></tr>
<tr><th>有効期限</th><td><?= $expires_str ?></td></tr>
<?php if ($meta_reason !== ''): ?>
<tr><th>提案理由</th><td><?= $meta_reason ?></td></tr>
<?php endif; ?>
</table>

<!-- 差分プレビュー -->
<h2>差分
  <span class="stat-badge stat-added">+<?= $stats['added'] ?></span>
  <span class="stat-badge stat-removed">-<?= $stats['removed'] ?></span>
</h2>
<?= $diff_html ?>

<!-- 下書き全文 -->
<h2>下書き全文</h2>
<pre class="body-preview"><?= htmlspecialchars((string)$draft['body'], ENT_QUOTES, 'UTF-8') ?></pre>

<!-- 承認・却下パネル -->
<?php if ($draft['status'] === 'open'): ?>
<h2>アクション</h2>
<div class="action-panel">
  <div class="action-form">
    <h3 style="color:var(--green)">✔ 承認して公開</h3>
    <p style="margin:.3rem 0 .7rem;font-size:.9rem">
      この内容でページを更新します。取り消しは現時点ではできません。
    </p>
    <form method="POST" action="<?= htmlspecialchars("{$base_url}/review.php", ENT_QUOTES, 'UTF-8') ?>"
          onsubmit="return confirm('承認してページを公開しますか？')">
      <input type="hidden" name="draft_id" value="<?= $id ?>">
      <input type="hidden" name="token"    value="<?= $form_token ?>">
      <input type="hidden" name="action"   value="approve">
      <button type="submit" class="btn btn-approve"<?= $conflict ? ' style="background:#e6a000"' : '' ?>>
        <?= $conflict ? '⚠ 競合を承知で承認して公開' : '承認して公開' ?>
      </button>
    </form>
  </div>

  <div class="action-form">
    <h3 style="color:var(--red)">✘ 却下</h3>
    <form method="POST" action="<?= htmlspecialchars("{$base_url}/review.php", ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="draft_id" value="<?= $id ?>">
      <input type="hidden" name="token"    value="<?= $form_token ?>">
      <input type="hidden" name="action"   value="reject">
      <textarea name="reason" placeholder="却下の理由（任意）"></textarea>
      <br><br>
      <button type="submit" class="btn btn-reject">却下</button>
    </form>
  </div>
</div>
<?php else: ?>
<p style="color:#555;margin-top:1rem">
  この下書きは <strong><?= htmlspecialchars($draft['status'], ENT_QUOTES, 'UTF-8') ?></strong>
  のため、これ以上の操作はできません。
</p>
<?php endif; ?>

</body>
</html>
<?php

// -------------------------------------------------------------------------
// ヘルパ
// -------------------------------------------------------------------------
function render_error(int $code, string $title, string $message): void
{
    echo "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'>"
       . "<title>{$code} {$title}</title>"
       . "<style>body{font-family:sans-serif;max-width:600px;margin:3rem auto;padding:1rem}"
       . "h1{color:#c62828}</style></head><body>"
       . "<h1>{$code} — {$title}</h1>"
       . "<p>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>"
       . "</body></html>";
}
