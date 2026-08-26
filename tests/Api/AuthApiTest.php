<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Api;

use ApiBoard\Auth\TokenVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use ApiBoard\Tests\Support\ApiTestCase;

final class AuthApiTest extends ApiTestCase
{
    #[DataProvider('connectionProvider')]
    public function testLoginReturnsAdminToken(array $config): void
    {
        $app = $this->makeApp($config);

        $response = $this->call($app, 'POST', '/auth/login', ['id' => 'root', 'password' => 'rootpass']);

        $this->assertSame(200, $response->status());
        $identity = (new TokenVerifier(self::SECRET, 60))->verify($response->payload()['token']);
        $this->assertTrue($identity->isAdmin());
        $this->assertSame('root', $identity->sub());
    }

    #[DataProvider('connectionProvider')]
    public function testWrongPasswordIsRejected(array $config): void
    {
        $app = $this->makeApp($config);

        $response = $this->call($app, 'POST', '/auth/login', ['id' => 'root', 'password' => 'nope']);

        $this->assertSame(401, $response->status());
    }

    #[DataProvider('connectionProvider')]
    public function testWrongIdIsRejected(array $config): void
    {
        $app = $this->makeApp($config);

        $response = $this->call($app, 'POST', '/auth/login', ['id' => 'someone', 'password' => 'rootpass']);

        $this->assertSame(401, $response->status());
    }
}
