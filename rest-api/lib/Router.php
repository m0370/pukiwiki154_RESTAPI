<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';

/**
 * シンプルな URI パターンマッチルーター。
 *
 * パターン例: '/pages/{page}'
 * → path segment {page} を配列キー 'page' として抽出する。
 *
 * 使い方:
 *   $router = new Router();
 *   $router->get('/pages/{page}', fn($p, $vars) => showPage($vars['page']));
 *   $router->dispatch($method, $path, $params);
 * @version v0.1
 */
final class Router
{
    /** @var array<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'GET', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'POST', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'DELETE', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'PUT', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'PATCH', 'pattern' => $pattern, 'handler' => $handler];
    }

    /**
     * リクエストをディスパッチする。
     * マッチするルートがなければ ApiException(404)。
     * メソッドが合わなければ ApiException(405)。
     */
    public function dispatch(string $method, string $path): mixed
    {
        $method = strtoupper($method);
        $method_matched = false;

        foreach ($this->routes as $route) {
            $vars = $this->match($route['pattern'], $path);
            if ($vars === null) {
                continue; // パスが一致しない
            }

            if ($route['method'] !== $method) {
                $method_matched = true;
                continue; // パスは一致するがメソッドが違う
            }

            return ($route['handler'])($vars);
        }

        if ($method_matched) {
            throw new ApiException(405, 'Method not allowed');
        }
        throw new ApiException(404, 'No route matched');
    }

    /**
     * URI パターンを $path に照合し、キャプチャを返す。
     * 一致しない場合は null。
     *
     * パターン内の {name} は URI コンポーネント（スラッシュを含まない）に一致する。
     * ページ名に '/' が含まれる場合（SubPage/Child など）は URL エンコードで渡す。
     */
    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', static fn($m) => '(?P<' . $m[1] . '>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#u';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $vars = [];
        foreach ($matches as $k => $v) {
            if (is_string($k)) {
                $vars[$k] = urldecode($v); // %2F → / などを戻す
            }
        }
        return $vars;
    }
}
