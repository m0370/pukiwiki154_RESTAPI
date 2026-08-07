<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';

/**
 * URI パターンマッチルーター。
 *
 * パターン記法:
 *   {name}     1 セグメント（スラッシュを含まない）
 *   {name...}  スラッシュを含む残りのパス（PukiWiki の階層ページ名用）
 *
 * 例:
 *   /pages/{page...}/revisions   → 「親/子/孫」のような階層ページ名に対応
 *   /pages/{page...}             → 同上（より具体的なパターンを先に登録すること）
 *
 * dispatch() に渡すパスは rawurldecode 済みを想定する。
 * （%2F 必須にすると Apache の AllowEncodedSlashes Off で 404 になるため、
 *  デコード後のパスでスラッシュごとマッチさせる方式を採る）
 * @version v2.0
 */
final class Router
{
    /** @var array<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'GET', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'PUT', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'POST', 'pattern' => $pattern, 'handler' => $handler];
    }

    /**
     * リクエストをディスパッチする。
     * パス不一致は 404、パス一致・メソッド不一致は 405。
     */
    public function dispatch(string $method, string $path): mixed
    {
        $method = strtoupper($method);
        $method_matched = false;

        foreach ($this->routes as $route) {
            $vars = self::match($route['pattern'], $path);
            if ($vars === null) {
                continue;
            }
            if ($route['method'] !== $method) {
                $method_matched = true;
                continue;
            }
            return ($route['handler'])($vars);
        }

        if ($method_matched) {
            throw new ApiException(405, 'Method not allowed');
        }
        throw new ApiException(404, 'No route matched: ' . $path);
    }

    /** パターンを $path に照合してキャプチャを返す。不一致は null */
    public static function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace_callback(
            '/\{(\w+)(\.\.\.)?\}/',
            static fn($m) => isset($m[2]) && $m[2] === '...'
                ? '(?P<' . $m[1] . '>.+)'
                : '(?P<' . $m[1] . '>[^/]+)',
            $pattern
        );
        $regex = '#^' . $regex . '$#u';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $vars = [];
        foreach ($matches as $k => $v) {
            if (is_string($k)) {
                $vars[$k] = $v;
            }
        }
        return $vars;
    }
}
