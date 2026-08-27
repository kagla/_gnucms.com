<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\Tests\Support\WebTestCase;

final class HealthTest extends WebTestCase
{
    /** @dataProvider connectionProvider */
    public function testHealthPageRendersDialect(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $response = $this->get($app, '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString($app->db()->dialect()->name(), $this->body($response));
    }

    /** @dataProvider connectionProvider */
    public function testUnknownPathRendersNotFoundPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $response = $this->get($app, '/없는경로');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('찾을 수 없', $this->body($response));
    }
}
