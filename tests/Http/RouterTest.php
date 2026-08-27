<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Http;

use PHPUnit\Framework\TestCase;
use ApiBoard\Error\DomainError;
use ApiBoard\Http\Request;
use ApiBoard\Http\Response;
use ApiBoard\Http\Router;

final class RouterTest extends TestCase
{
    public function testMatchesStaticPath(): void
    {
        $router = new Router();
        $router->get('/boards', static function (Request $r, array $p): Response {
            return Response::json(['ok' => true]);
        });

        $response = $router->dispatch($this->request('GET', '/boards'));

        $this->assertSame(200, $response->status());
        $this->assertSame(['ok' => true], $response->payload());
    }

    public function testExtractsNamedParameters(): void
    {
        $router = new Router();
        $router->get('/boards/{key}/posts', static function (Request $r, array $p): Response {
            return Response::json(['key' => $p['key']]);
        });

        $response = $router->dispatch($this->request('GET', '/boards/free/posts'));

        $this->assertSame(['key' => 'free'], $response->payload());
    }

    public function testExtractsMultipleParameters(): void
    {
        $router = new Router();
        $router->get('/posts/{id}/files/{index}', static function (Request $r, array $p): Response {
            return Response::json($p);
        });

        $response = $router->dispatch($this->request('GET', '/posts/12/files/0'));

        $this->assertSame(['id' => '12', 'index' => '0'], $response->payload());
    }

    public function testTrailingSlashIsIgnored(): void
    {
        $router = new Router();
        $router->get('/boards', static function (Request $r, array $p): Response {
            return Response::json(['ok' => true]);
        });

        $this->assertSame(200, $router->dispatch($this->request('GET', '/boards/'))->status());
    }

    public function testParameterDoesNotMatchAcrossSlash(): void
    {
        $router = new Router();
        $router->get('/boards/{key}', static function (Request $r, array $p): Response {
            return Response::json($p);
        });

        $this->expectException(DomainError::class);
        $router->dispatch($this->request('GET', '/boards/free/posts'));
    }

    public function testUnknownPathThrowsNotFound(): void
    {
        $router = new Router();

        try {
            $router->dispatch($this->request('GET', '/nope'));
            $this->fail('NOT_FOUND 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertSame(404, $e->status());
        }
    }

    public function testKnownPathWithWrongMethodThrowsNotFound(): void
    {
        $router = new Router();
        $router->get('/boards', static function (Request $r, array $p): Response {
            return Response::json([]);
        });

        try {
            $router->dispatch($this->request('DELETE', '/boards'));
            $this->fail('NOT_FOUND 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertSame(404, $e->status());
        }
    }

    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], [], []);
    }
}
