<?php

declare(strict_types=1);

namespace StandardBoard\Tests\Support;

use StandardBoard\App;
use StandardBoard\Auth\TokenIssuer;
use StandardBoard\Db\Schema;
use StandardBoard\Http\ApiError;
use StandardBoard\Http\Request;
use StandardBoard\Http\Response;

abstract class ApiTestCase extends DatabaseTestCase
{
    protected const SECRET = 'api-test-secret-that-is-long-enough';

    protected function makeApp(array $dbConfig): App
    {
        $app = new App([
            'db'   => $dbConfig,
            'auth' => ['secret' => self::SECRET, 'ttl' => 3600, 'leeway' => 60],
            'bootstrap_admin' => [
                'id'            => 'root',
                'password_hash' => password_hash('rootpass', PASSWORD_DEFAULT),
            ],
            'uploads' => [
                'dir'         => sys_get_temp_dir() . '/standard-board-test-uploads',
                'max_bytes'   => 1024 * 1024,
                'allowed_ext' => ['txt', 'png', 'pdf'],
            ],
            'cors'  => ['allowed_origins' => []],
            'debug' => true,
        ]);

        $schema = new Schema($app->db());
        $schema->drop();
        $schema->create();

        return $app;
    }

    /** ApiError 도 Response 로 변환해 돌려준다. 테스트가 상태 코드를 그대로 볼 수 있다. */
    protected function call(App $app, string $method, string $path, array $body = [], ?string $token = null): Response
    {
        $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];
        $request = new Request($method, $path, [], $body, $headers, []);

        try {
            return $app->router()->dispatch($request);
        } catch (ApiError $e) {
            return Response::fromError($e, true);
        }
    }

    protected function tokenFor(App $app, string $sub, string $name, bool $admin): string
    {
        return (new TokenIssuer(self::SECRET, 3600))->issue($sub, $name, $admin);
    }

    protected function adminToken(App $app): string
    {
        return $this->tokenFor($app, 'root', '관리자', true);
    }
}
