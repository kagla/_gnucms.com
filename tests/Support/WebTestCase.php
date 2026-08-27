<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Support;

use ApiBoard\App;
use ApiBoard\Auth\Acl;
use ApiBoard\Auth\Identity;
use ApiBoard\Db\Schema;
use ApiBoard\Web\Kernel;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

abstract class WebTestCase extends DatabaseTestCase
{
    protected function makeApp(array $dbConfig): App
    {
        $app = new App([
            'db'   => $dbConfig,
            'auth' => ['secret' => 'web-test-secret-that-is-long-enough'],
            'uploads' => [
                'dir'         => sys_get_temp_dir() . '/apiboard-test-uploads',
                'max_bytes'   => 1024 * 1024,
                'allowed_ext' => ['txt', 'png', 'pdf'],
            ],
            'log'   => ['file' => null],
            'debug' => true,
        ]);

        $schema = new Schema($app->db());
        $schema->drop();
        $schema->create();

        return $app;
    }

    /** 게시판·글을 만들 때 쓴다. 1단계에는 로그인이 없으므로 화면은 항상 게스트다. */
    protected function adminAcl(): Acl
    {
        return new Acl(Identity::user('1', '관리자', true));
    }

    protected function get(App $app, string $path, array $query = []): ResponseInterface
    {
        $uri = $path . ($query === [] ? '' : '?' . http_build_query($query));
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);

        return Kernel::create($app, dirname(__DIR__, 2) . '/templates', null, '')->handle($request);
    }

    protected function body(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }
}
