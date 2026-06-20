<?php
/**
 * REST API v1 フロントコントローラ
 *
 * Apache mod_rewrite（.htaccess）により、
 * /rest-api/api/v1/* のリクエストがすべてここに集まる。
 * REQUEST_URI からパスを取得してルーティングする。
 *
 * 統合時は PukiWiki のルート直下に rest-api/ を置く。
 * bootstrap.php が pukiwiki.ini.php を自動検出する。
 * @version v0.1
 */
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/ApiException.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/PageReader.php';
require_once __DIR__ . '/../../lib/CommitEngine.php';
require_once __DIR__ . '/../../lib/DraftManager.php';
require_once __DIR__ . '/../../lib/DiffEngine.php';
require_once __DIR__ . '/../../lib/BlockSplitter.php';
require_once __DIR__ . '/../../lib/BlockEditor.php';
require_once __DIR__ . '/../../lib/AdminManager.php';
require_once __DIR__ . '/../../lib/Response.php';
require_once __DIR__ . '/../../lib/Router.php';

// -------------------------------------------------------------------------
// コンポーネント初期化（bootstrap.php が $REST_* グローバルを設定）
// -------------------------------------------------------------------------
$auth   = new Auth($REST_LEDGER);
$reader = new PageReader($REST_RECONCILER, $REST_LEDGER, $REST_WIKI_DIR);
$drafts = new DraftManager($REST_LEDGER, $REST_ENGINE, $REST_WIKI_DIR);
$admin  = new AdminManager($REST_LEDGER, $REST_REVISIONS, $REST_ENGINE, $REST_WIKI_DIR);
$router = new Router();

// -------------------------------------------------------------------------
// ルート定義
// -------------------------------------------------------------------------

// GET /pages  → ページ一覧（page_index から取得）
$router->get('/pages', function (array $vars) use ($auth, $reader): Response {
    $auth->authenticate($_SERVER, 'page:read', $_SERVER['REMOTE_ADDR'] ?? '');
    $limit  = max(1, min(1000, (int)($_GET['limit']  ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $pages  = $reader->listPages($limit, $offset);
    return Response::ok(['pages' => $pages, 'count' => count($pages)]);
});

// GET /pages/{page}  → ページ取得（自己修復込み）
$router->get('/pages/{page}', function (array $vars) use ($auth, $reader): Response {
    $auth->authenticate($_SERVER, 'page:read', $_SERVER['REMOTE_ADDR'] ?? '');
    return Response::ok($reader->read($vars['page']));
});

// PUT /pages/{page}  → ページ全文上書き（新規作成 / 既存更新）
// scope: page:write
// body:  {base_sha1, content, summary?, meta?}
//   base_sha1 = CommitEngine::EMPTY_SHA1 で新規作成。既存ページは現在の sha1 を渡す。
// 保護ページ（$REST_PROTECTED_PAGES）は 403 page_protected — draft 経由のみ許可。
// 新規作成（base_sha1 = EMPTY_SHA1）は HTTP 201 Created を返す。
$router->put('/pages/{page}', function (array $vars) use ($auth): Response {
    global $REST_ENGINE, $REST_PROTECTED_PAGES;
    $key = $auth->authenticate($_SERVER, 'page:write', $_SERVER['REMOTE_ADDR'] ?? '');

    // 保護ページは直接編集不可
    if (in_array($vars['page'], $REST_PROTECTED_PAGES ?? [], true)) {
        throw new ApiException(
            403,
            "Page '{$vars['page']}' is protected from direct editing. " .
            "Use POST /drafts or wiki_create_draft instead.",
            'page_protected'
        );
    }

    $body      = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $base_sha1 = trim((string)($body['base_sha1'] ?? ''));
    $content   = (string)($body['content']  ?? '');
    $summary   = trim((string)($body['summary'] ?? ''));
    $meta      = (array)($body['meta']     ?? []);
    $actor     = (string)($key['label']    ?? $key['id'] ?? 'api');

    if ($base_sha1 === '') {
        throw new ApiException(
            400,
            '"base_sha1" is required. Use ' . CommitEngine::EMPTY_SHA1 . ' for new pages.',
            'missing_base_sha1'
        );
    }
    if (!preg_match('/^[0-9a-f]{40}$/i', $base_sha1)) {
        throw new ApiException(400, '"base_sha1" must be a 40-char hex SHA1.', 'invalid_base_sha1');
    }

    $is_new = ($base_sha1 === CommitEngine::EMPTY_SHA1);

    if ($summary !== '') {
        $meta['summary'] = $summary;
    }
    $meta['source'] = 'put_page';

    $result = $REST_ENGINE->commit($vars['page'], $content, $base_sha1, $actor, $meta);

    $payload = [
        'page'         => $vars['page'],
        'new_rev'      => $result['new_rev'],
        'new_sha1'     => $result['new_sha1'],
        'committed_at' => $result['committed_at'],
    ];
    return $is_new ? Response::created($payload) : Response::ok($payload);
});

// PATCH /pages/{page}  → ブロック単位編集（直接コミット）
// scope: page:write
// body:  {base_sha1, patches: [{block_sha1, new_content}], summary?, meta?}
// 保護ページ（$REST_PROTECTED_PAGES）は 403 page_protected — draft 経由のみ許可。
$router->patch('/pages/{page}', function (array $vars) use ($auth, $reader): Response {
    global $REST_ENGINE, $REST_PROTECTED_PAGES;
    $key = $auth->authenticate($_SERVER, 'page:write', $_SERVER['REMOTE_ADDR'] ?? '');

    // 保護ページは直接編集不可
    if (in_array($vars['page'], $REST_PROTECTED_PAGES ?? [], true)) {
        throw new ApiException(
            403,
            "Page '{$vars['page']}' is protected from direct editing. " .
            "Use POST /drafts or wiki_create_draft instead.",
            'page_protected'
        );
    }

    $body      = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $base_sha1 = trim((string)($body['base_sha1'] ?? ''));
    $patches   = (array)($body['patches']  ?? []);
    $summary   = trim((string)($body['summary'] ?? ''));
    $meta      = (array)($body['meta']     ?? []);
    $actor     = (string)($key['label']    ?? $key['id'] ?? 'api');

    if ($base_sha1 === '') {
        throw new ApiException(400, '"base_sha1" is required.', 'missing_base_sha1');
    }
    if (!preg_match('/^[0-9a-f]{40}$/i', $base_sha1)) {
        throw new ApiException(400, '"base_sha1" must be a 40-char hex SHA1.', 'invalid_base_sha1');
    }
    if (empty($patches)) {
        throw new ApiException(400, '"patches" must be a non-empty array.', 'missing_patches');
    }

    // 現在のページ内容を取得（自己修復込み）
    $page_data = $reader->read($vars['page']);

    // page-level CAS チェック（CommitEngine が lock 内で再チェックするが、
    // ブロック sha1 の照合前に素早く弾くための事前チェック）
    if ($page_data['sha1'] !== $base_sha1) {
        throw new ApiException(
            409,
            "Conflict: page '{$vars['page']}' sha1 mismatch. " .
            "Expected {$base_sha1}, current {$page_data['sha1']}.",
            'sha1_conflict'
        );
    }

    // ブロックパッチを適用して新しい全文を生成
    $new_content = BlockEditor::apply($page_data['content'], $patches);

    if ($summary !== '') {
        $meta['summary'] = $summary;
    }
    $meta['source']        = 'patch_page';
    $meta['patches_count'] = count($patches);

    $result = $REST_ENGINE->commit($vars['page'], $new_content, $base_sha1, $actor, $meta);

    return Response::ok([
        'page'            => $vars['page'],
        'new_rev'         => $result['new_rev'],
        'new_sha1'        => $result['new_sha1'],
        'committed_at'    => $result['committed_at'],
        'patches_applied' => count($patches),
    ]);
});

// GET /search?q={query}&limit={n}  → FTS5 全文検索
$router->get('/search', function (array $vars) use ($auth): Response {
    global $REST_LEDGER;
    $auth->authenticate($_SERVER, 'page:read', $_SERVER['REMOTE_ADDR'] ?? '');

    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        throw new ApiException(400, 'Query parameter "q" is required', 'missing_query');
    }
    if (mb_strlen($q, 'UTF-8') < 3) {
        throw new ApiException(
            400,
            'Query must be at least 3 characters (trigram tokenizer minimum)',
            'query_too_short'
        );
    }

    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));

    try {
        $results = $REST_LEDGER->search($q, $limit);
    } catch (\PDOException $e) {
        throw new ApiException(400, 'Invalid search query: ' . $e->getMessage(), 'invalid_query');
    }

    return Response::ok([
        'query'   => $q,
        'results' => $results,
        'count'   => count($results),
        'limit'   => $limit,
    ]);
});

// GET /index/status  → インデックスと wiki/ の整合性確認（運用用）
$router->get('/index/status', function (array $vars) use ($auth): Response {
    global $REST_RECONCILER;
    $auth->authenticate($_SERVER, 'page:read', $_SERVER['REMOTE_ADDR'] ?? '');
    return Response::ok($REST_RECONCILER->verifyIndex());
});

// POST /index/rebuild  → インデックスを wiki/ から再構築（運用用）
$router->post('/index/rebuild', function (array $vars) use ($auth): Response {
    global $REST_RECONCILER;
    $auth->authenticate($_SERVER, 'page:read', $_SERVER['REMOTE_ADDR'] ?? '');
    $count = $REST_RECONCILER->buildIndex();
    return Response::ok(['rebuilt' => true, 'pages' => $count]);
});

// -------------------------------------------------------------------------
// ブロック編集エンドポイント
// -------------------------------------------------------------------------

// GET /pages/{page}/blocks  → ページをブロックに分割して返す
$router->get('/pages/{page}/blocks', function (array $vars) use ($auth, $reader): Response {
    $auth->authenticate($_SERVER, 'page:read', $_SERVER['REMOTE_ADDR'] ?? '');
    $data   = $reader->read($vars['page']);
    $blocks = BlockEditor::describe($data['content']);
    return Response::ok([
        'page'        => $data['page'],
        'sha1'        => $data['sha1'],
        'rev'         => $data['rev'],
        'block_count' => count($blocks),
        'blocks'      => $blocks,
    ]);
});

// POST /pages/{page}/blocks  → ブロックパッチから下書きを作成
$router->post('/pages/{page}/blocks', function (array $vars) use ($auth, $reader): Response {
    global $REST_LEDGER;
    $key = $auth->authenticate($_SERVER, 'draft:create', $_SERVER['REMOTE_ADDR'] ?? '');

    $body      = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $base_sha1 = trim((string)($body['base_sha1'] ?? ''));
    $patches   = (array)($body['patches']   ?? []);
    $meta      = (array)($body['meta']      ?? []);
    $actor     = (string)($key['label']     ?? $key['id'] ?? 'api');

    if ($base_sha1 === '') {
        throw new ApiException(400, '"base_sha1" is required', 'missing_base_sha1');
    }
    if (empty($patches)) {
        throw new ApiException(400, '"patches" must be a non-empty array', 'missing_patches');
    }

    // 現在のページ内容を取得（自己修復込み）
    $page_data = $reader->read($vars['page']);

    // base_sha1 の競合チェック
    if ($page_data['sha1'] !== $base_sha1) {
        throw new ApiException(409, implode(' ', [
            "Conflict: page '{$vars['page']}' has changed.",
            "Expected sha1={$base_sha1}, current={$page_data['sha1']}.",
        ]), 'sha1_conflict');
    }

    // ブロックパッチを適用
    $new_content = BlockEditor::apply($page_data['content'], $patches);
    $new_sha1    = sha1($new_content);

    // 差分プレビュー生成
    $diff       = DiffEngine::unified(
        $page_data['content'], $new_content,
        "{$vars['page']} (current)", "{$vars['page']} (draft)"
    );
    $diff_stats = DiffEngine::stats($diff);

    // 下書きを作成
    $now      = time();
    $expires  = $now + 7 * 24 * 3600;
    $draft_id = $REST_LEDGER->createDraft(
        $vars['page'], $base_sha1, $new_content, $actor,
        $now, $expires,
        array_merge(['source' => 'block_patch', 'patches_count' => count($patches)], $meta)
    );

    return Response::created([
        'draft_id'       => $draft_id,
        'page'           => $vars['page'],
        'base_sha1'      => $base_sha1,
        'new_sha1'       => $new_sha1,
        'patches_applied'=> count($patches),
        'diff'           => $diff,
        'diff_stats'     => $diff_stats,
    ]);
});

// -------------------------------------------------------------------------
// 下書き（Draft）エンドポイント
// -------------------------------------------------------------------------

// GET /drafts  → 下書き一覧（フィルタ: ?page=&status=open&owner=&limit=&offset=）
$router->get('/drafts', function (array $vars) use ($auth, $drafts): Response {
    global $REST_LEDGER;
    $auth->authenticate($_SERVER, 'draft:approve', $_SERVER['REMOTE_ADDR'] ?? '');

    $page   = $_GET['page']   ?? null;
    $status = $_GET['status'] ?? null;
    $owner  = $_GET['owner']  ?? null;
    $limit  = max(1, min(200, (int)($_GET['limit']  ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $list = $REST_LEDGER->listDrafts(
        $page ?: null,
        $status ?: null,
        $owner  ?: null,
        $limit,
        $offset
    );
    return Response::ok(['drafts' => $list, 'count' => count($list)]);
});

// GET /drafts/{id}  → 下書き詳細 + 差分プレビュー
$router->get('/drafts/{id}', function (array $vars) use ($auth, $drafts): Response {
    $auth->authenticate($_SERVER, 'draft:approve', $_SERVER['REMOTE_ADDR'] ?? '');
    $id   = (int)$vars['id'];
    $data = $drafts->getWithDiff($id);
    // diff_html は HTML レビュー UI 用。JSON レスポンスには diff テキストのみ含める
    unset($data['diff_html']);
    return Response::ok($data);
});

// POST /drafts/{id}/approve  → 下書きを承認してページへ公開
$router->post('/drafts/{id}/approve', function (array $vars) use ($auth, $drafts): Response {
    global $REST_LEDGER;
    $key = $auth->authenticate($_SERVER, 'draft:approve', $_SERVER['REMOTE_ADDR'] ?? '');
    $id       = (int)$vars['id'];
    $approver = $key['label'] ?? $key['id'] ?? 'human';
    $now      = time();

    $result = $drafts->approve($id, (string)$approver, $now);
    $draft  = $REST_LEDGER->getDraft($id);

    return Response::ok([
        'published' => true,
        'draft_id'  => $id,
        'page'      => $draft['page'],
        'new_rev'   => $result['new_rev'],
        'new_sha1'  => $result['new_sha1'],
    ]);
});

// POST /drafts/{id}/reject  → 下書きを却下
$router->post('/drafts/{id}/reject', function (array $vars) use ($auth, $drafts): Response {
    global $REST_LEDGER;
    $key = $auth->authenticate($_SERVER, 'draft:approve', $_SERVER['REMOTE_ADDR'] ?? '');
    $id       = (int)$vars['id'];
    $rejector = $key['label'] ?? $key['id'] ?? 'human';
    $now      = time();

    $body   = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $reason = trim((string)($body['reason'] ?? ''));

    $drafts->reject($id, (string)$rejector, $reason, $now);
    $draft  = $REST_LEDGER->getDraft($id);

    return Response::ok([
        'rejected' => true,
        'draft_id' => $id,
        'page'     => $draft['page'],
        'status'   => $draft['status'],
        'reason'   => $reason,
    ]);
});

// -------------------------------------------------------------------------
// 管理者エンドポイント（scope: admin）
// -------------------------------------------------------------------------

// GET /admin/audit  → 監査ログ一覧
$router->get('/admin/audit', function (array $vars) use ($auth): Response {
    global $REST_LEDGER;
    $auth->authenticate($_SERVER, 'admin', $_SERVER['REMOTE_ADDR'] ?? '');

    $page   = $_GET['page']   ?? null;
    $action = $_GET['action'] ?? null;
    $actor  = $_GET['actor']  ?? null;
    $since  = (int)($_GET['since']  ?? 0);
    $limit  = max(1, min(500, (int)($_GET['limit']  ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $rows = $REST_LEDGER->listAudit(
        $page  ?: null,
        $action ?: null,
        $actor  ?: null,
        $since, $limit, $offset
    );
    return Response::ok(['audit' => $rows, 'count' => count($rows)]);
});

// POST /admin/drafts/expire  → 期限切れ下書きを一括失効
$router->post('/admin/drafts/expire', function (array $vars) use ($auth, $admin): Response {
    $key   = $auth->authenticate($_SERVER, 'admin', $_SERVER['REMOTE_ADDR'] ?? '');
    $actor = (string)($key['label'] ?? 'admin');
    $now   = time();
    $count = $admin->expireDrafts($actor, $now);
    return Response::ok(['expired' => $count, 'expired_at' => $now]);
});

// GET /admin/pages/{page}/revisions  → ページのリビジョン一覧
$router->get('/admin/pages/{page}/revisions', function (array $vars) use ($auth): Response {
    global $REST_LEDGER;
    $auth->authenticate($_SERVER, 'admin', $_SERVER['REMOTE_ADDR'] ?? '');
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $revs  = $REST_LEDGER->listRevisions($vars['page'], $limit);
    return Response::ok(['page' => $vars['page'], 'revisions' => $revs, 'count' => count($revs)]);
});

// POST /admin/pages/{page}/rollback  → ページをリビジョンにロールバック
$router->post('/admin/pages/{page}/rollback', function (array $vars) use ($auth, $admin): Response {
    $key    = $auth->authenticate($_SERVER, 'admin', $_SERVER['REMOTE_ADDR'] ?? '');
    $actor  = (string)($key['label'] ?? 'admin');
    $body   = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $target = (int)($body['target_rev'] ?? 0);
    $reason = trim((string)($body['reason'] ?? ''));
    $now    = time();

    if ($target <= 0) {
        throw new ApiException(400, '"target_rev" (positive integer) is required', 'missing_target_rev');
    }

    $result = $admin->rollback($vars['page'], $target, $actor, $reason, $now);
    return Response::ok(array_merge($result, ['page' => $vars['page']]));
});

// DELETE /admin/pages/{page}  → ページのソフト削除
$router->delete('/admin/pages/{page}', function (array $vars) use ($auth, $admin): Response {
    $key   = $auth->authenticate($_SERVER, 'admin', $_SERVER['REMOTE_ADDR'] ?? '');
    $actor = (string)($key['label'] ?? 'admin');
    $now   = time();

    $result = $admin->softDelete($vars['page'], $actor, $now);
    return Response::ok($result);
});

// -------------------------------------------------------------------------
// ディスパッチ
// -------------------------------------------------------------------------
try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path   = api_parse_path();
    $resp   = $router->dispatch($method, $path);
    $resp->send();
} catch (\Throwable $e) {
    Response::fromException($e)->send();
}

/**
 * REQUEST_URI からマウントポイント（このスクリプトの場所）を除いたパスを返す。
 * 例: /rest-api/api/v1/pages/FrontPage → /pages/FrontPage
 */
function api_parse_path(): string
{
    $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $script = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    if ($script !== '/' && str_starts_with($uri, $script)) {
        $uri = substr($uri, strlen($script));
    }
    return '/' . ltrim($uri, '/');
}
