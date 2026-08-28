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
use Psr\Http\Message\UploadedFileInterface;

abstract class WebTestCase extends DatabaseTestCase
{
    /**
     * @param array $configOverrides 기본 설정 위에 덮어쓸 값. 최상위 키 단위로 합쳐진다.
     *                                예: ['debug' => false] 로 프로덕션 오류 화면을 테스트한다.
     */
    protected function makeApp(array $dbConfig, array $configOverrides = []): App
    {
        $config = array_replace([
            'db'   => $dbConfig,
            'auth' => ['secret' => 'web-test-secret-that-is-long-enough'],
            'uploads' => [
                'dir'         => sys_get_temp_dir() . '/apiboard-test-uploads',
                'max_bytes'   => 1024 * 1024,
                'allowed_ext' => ['txt', 'png', 'pdf'],
            ],
            'log'   => ['file' => null],
            'debug' => true,
        ], $configOverrides);

        $app = new App($config);

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
        return $this->request($app, 'GET', $path, $query);
    }

    protected function request(App $app, string $method, string $path, array $query = []): ResponseInterface
    {
        $uri = $path . ($query === [] ? '' : '?' . http_build_query($query));
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);

        return Kernel::create($app, dirname(__DIR__, 2) . '/templates', '')->handle($request);
    }

    protected function post(App $app, string $path, array $body): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path)->withParsedBody($body);

        return Kernel::create($app, dirname(__DIR__, 2) . '/templates', '')->handle($request);
    }

    /** @param array<string, UploadedFileInterface> $files */
    protected function upload(App $app, string $path, array $files): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path)->withUploadedFiles($files);

        return Kernel::create($app, dirname(__DIR__, 2) . '/templates', '')->handle($request);
    }

    protected function body(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }
}
