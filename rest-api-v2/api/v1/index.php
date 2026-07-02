<?php
/**
 * REST API v2 フロントコントローラ。
 *
 * ルーティングは bootstrap.php が退避した $REST_REQUEST を使う
 * （PukiWiki の init.php が $_SERVER/$_GET を書き換えるため）。
 *
 * エンドポイント:
 *   GET /pages                              ページ一覧            [read]
 *   GET /pages/{page}                       ページ取得            [read]
 *   PUT /pages/{page}                       全文書き込み          [write]
 *   GET /pages/{page}/revisions             スナップショット一覧  [read]
 *   GET /pages/{page}/revisions/{rev}       過去版の取得          [read]
 *   GET /search?q=...                       全文検索              [read]
 *
 * {page} は階層ページ名（親/子/孫）をそのまま受け付ける。
 * 例: GET /rest-api-v2/api/v1/pages/講演/2026年
 *
 * License: GPL v2 or (at your option) any later version（PukiWiki 1.5.4 本体に準拠）
 * @version v2.0
 */

require_once __DIR__ . '/../../bootstrap.php';

$auth = new Auth($REST_KEYS_FILE);

// -------------------------------------------------------------------------
// リクエスト情報（bootstrap の退避コピーから取得）
// -------------------------------------------------------------------------

/** クエリパラメータを文字列として安全に取得する（?q[]=x による TypeError を防ぐ） */
function rest_query(string $name, string $default = ''): string
{
    global $REST_REQUEST;
    $v = $REST_REQUEST['query'][$name] ?? $default;
    return is_string($v) ? $v : $default;
}

function rest_query_int(string $name, int $default, int $min, int $max): int
{
    $v = rest_query($name, (string)$default);
    return max($min, min($max, (int)$v));
}

/** リクエストパスを取得（マウント位置に依存しない） */
function rest_parse_path(array $req): string
{
    // Apache 非 rewrite / php -S: /api/v1/index.php/pages/Foo 形式（PATH_INFO）
    if ($req['path_info'] !== '') {
        return '/' . ltrim(rawurldecode($req['path_info']), '/');
    }
    $uri    = parse_url($req['uri'], PHP_URL_PATH) ?? '/';
    $script = $req['script_name'];
    // /rest-api-v2/api/v1/index.php → /rest-api-v2/api/v1
    $base = str_ends_with($script, '/index.php') ? substr($script, 0, -strlen('/index.php')) : dirname($script);
    if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    return '/' . ltrim(rawurldecode($uri), '/');
}

/** JSON リクエストボディを取得 */
function rest_json_body(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new ApiException(400, 'Request body must be a JSON object', 'invalid_json');
    }
    return $decoded;
}

// -------------------------------------------------------------------------
// ルート定義
// -------------------------------------------------------------------------
$router = new Router();

// GET /pages — ページ一覧
$router->get('/pages', function () use ($auth): Response {
    global $REST_REQUEST, $REST_PAGES;
    $auth->authenticate($REST_REQUEST['authorization'], Auth::SCOPE_READ, $REST_REQUEST['remote_addr']);

    $limit  = rest_query_int('limit', 100, 1, 1000);
    $offset = rest_query_int('offset', 0, 0, PHP_INT_MAX);
    $result = $REST_PAGES->listPages($limit, $offset);

    return Response::ok([
        'pages'  => $result['pages'],
        'count'  => count($result['pages']),
        'total'  => $result['total'],
        'limit'  => $limit,
        'offset' => $offset,
    ]);
});

// GET /search?q=...&limit=N — 全文検索
$router->get('/search', function () use ($auth): Response {
    global $REST_REQUEST, $REST_PAGES;
    $auth->authenticate($REST_REQUEST['authorization'], Auth::SCOPE_READ, $REST_REQUEST['remote_addr']);

    $q = trim(rest_query('q'));
    if ($q === '') {
        throw new ApiException(400, 'Query parameter "q" is required', 'missing_query');
    }
    if (mb_strlen($q, 'UTF-8') < 2) {
        throw new ApiException(400, 'Query must be at least 2 characters', 'query_too_short');
    }
    $limit   = rest_query_int('limit', 20, 1, 100);
    $results = $REST_PAGES->search($q, $limit);

    return Response::ok([
        'query'   => $q,
        'results' => $results,
        'count'   => count($results),
    ]);
});

// GET /pages/{page}/revisions — API書き込みスナップショットの一覧
$router->get('/pages/{page...}/revisions', function (array $vars) use ($auth): Response {
    global $REST_REQUEST, $REST_SNAPSHOTS;
    $auth->authenticate($REST_REQUEST['authorization'], Auth::SCOPE_READ, $REST_REQUEST['remote_addr']);

    $revs = $REST_SNAPSHOTS->list($vars['page']);
    return Response::ok([
        'page'      => $vars['page'],
        'revisions' => $revs,
        'count'     => count($revs),
        'note'      => 'These are snapshots taken on API writes. '
                     . 'PukiWiki backup/diff history is separate.',
    ]);
});

// GET /pages/{page}/revisions/{rev} — 過去版の本文取得
$router->get('/pages/{page...}/revisions/{rev}', function (array $vars) use ($auth): Response {
    global $REST_REQUEST, $REST_SNAPSHOTS;
    $auth->authenticate($REST_REQUEST['authorization'], Auth::SCOPE_READ, $REST_REQUEST['remote_addr']);

    $content = $REST_SNAPSHOTS->read($vars['page'], $vars['rev']);
    return Response::ok([
        'page'     => $vars['page'],
        'revision' => $vars['rev'],
        'sha1'     => sha1($content),
        'content'  => $content,
        'note'     => 'To restore this version, PUT it back with the current page sha1 as base_sha1.',
    ]);
});

// GET /pages/{page} — ページ取得
$router->get('/pages/{page...}', function (array $vars) use ($auth): Response {
    global $REST_REQUEST, $REST_PAGES;
    $auth->authenticate($REST_REQUEST['authorization'], Auth::SCOPE_READ, $REST_REQUEST['remote_addr']);
    return Response::ok($REST_PAGES->read($vars['page']));
});

// PUT /pages/{page} — 全文書き込み（新規 201 / 更新 200）
$router->put('/pages/{page...}', function (array $vars) use ($auth): Response {
    global $REST_REQUEST, $REST_PAGES;
    $key = $auth->authenticate($REST_REQUEST['authorization'], Auth::SCOPE_WRITE, $REST_REQUEST['remote_addr']);

    $body      = rest_json_body();
    $base_sha1 = is_string($body['base_sha1'] ?? null) ? trim($body['base_sha1']) : '';
    $content   = is_string($body['content'] ?? null) ? $body['content'] : '';

    if ($base_sha1 === '') {
        throw new ApiException(
            400,
            '"base_sha1" is required. Use ' . PageStore::EMPTY_SHA1 . ' for new pages.',
            'missing_base_sha1'
        );
    }
    if ($content === '') {
        throw new ApiException(400, '"content" (non-empty string) is required', 'missing_content');
    }

    $result = $REST_PAGES->write(
        $vars['page'],
        $content,
        $base_sha1,
        $key['label'],
        $REST_REQUEST['remote_addr']
    );

    $result['note'] = 'Content may have been normalized by PukiWiki '
                    . '(#author line, heading anchors). Use new_sha1 as the next base_sha1.';
    return $result['is_new'] ? Response::created($result) : Response::ok($result);
});

// -------------------------------------------------------------------------
// ディスパッチ
// -------------------------------------------------------------------------
try {
    $resp = $router->dispatch($REST_REQUEST['method'], rest_parse_path($REST_REQUEST));
    $resp->send();
} catch (\Throwable $e) {
    if ($e instanceof ApiException && $e->status === 401) {
        $REST_AUDIT->log('auth_failed', [
            'ip'   => $REST_REQUEST['remote_addr'],
            'path' => parse_url($REST_REQUEST['uri'], PHP_URL_PATH),
        ]);
    }
    Response::fromException($e)->send();
}
