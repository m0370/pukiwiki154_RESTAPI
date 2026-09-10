<?php
declare(strict_types=1);
require_once __DIR__ . '/WikiFiles.php';

function rest_register_extra_routes(Router $router, Auth $auth): void
{
    // 独立した名前空間にして、既存ページ名の末尾との衝突を避ける。
    $files = static function (string $scope = 'read') use ($auth): WikiFiles {
        global $REST_REQUEST, $REST_PAGES, $REST_AUDIT;
        $key = $auth->authenticate($REST_REQUEST['authorization'], $scope, $REST_REQUEST['remote_addr']);
        return new WikiFiles($REST_PAGES->withIdentity(new Identity($key['label'], $key['wiki_user'])), $REST_AUDIT, $key);
    };
    $router->get('/backups', static fn() => Response::ok($files()->backups(rest_query('page'))));
    $router->get('/backup', static fn() => Response::ok($files()->backups(rest_query('page'), rest_query_int('age', 0, 0, PHP_INT_MAX))));
    $router->get('/attachments', static fn() => Response::ok($files()->list(rest_query('page'))));
    $router->get('/attachment', static fn() => Response::ok($files()->read(rest_query('page'), rest_query('name'), rest_query_int('age', 0, 0, PHP_INT_MAX))));
    $router->post('/attachments', static function () use ($files) {
        $service = $files('write');
        $b = rest_json_body();
        foreach (['page','name','content_base64'] as $field) {
            if (!isset($b[$field]) || !is_string($b[$field])) throw new ApiException(400, 'Missing field: ' . $field, 'invalid_parameter');
        }
        return Response::created($service->upload($b['page'], $b['name'], $b['content_base64']));
    });
    $router->post('/attachment/delete', static function () use ($files) {
        $service = $files('write');
        $b = rest_json_body();
        foreach (['page','name','sha256'] as $field) {
            if (!isset($b[$field]) || !is_string($b[$field])) throw new ApiException(400, 'Missing field: ' . $field, 'invalid_parameter');
        }
        return Response::ok($service->delete($b['page'], $b['name'], $b['sha256']));
    });
    $router->get('/capabilities', static function () use ($auth) {
        global $REST_REQUEST;
        $key = $auth->authenticate($REST_REQUEST['authorization'], 'read', $REST_REQUEST['remote_addr']);
        return Response::ok(['version' => '2.2.0', 'scope' => $key['scope'], 'wiki_user' => $key['wiki_user'],
            'attachments_write' => $key['attachments_write'], 'search_modes' => ['PHRASE','AND','OR'],
            'max_attachment_bytes' => WikiFiles::MAX_BYTES, 'standard_backups' => function_exists('get_backup')]);
    });
}
