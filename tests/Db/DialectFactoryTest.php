<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Db;

use PHPUnit\Framework\TestCase;
use ApiBoard\Db\DialectFactory;
use ApiBoard\Http\ApiError;

final class DialectFactoryTest extends TestCase
{
    public function testResolvesSqlite(): void
    {
        $this->assertSame('sqlite', DialectFactory::fromDsn('sqlite::memory:')->name());
    }

    public function testResolvesMysql(): void
    {
        $this->assertSame('mysql', DialectFactory::fromDsn('mysql:host=localhost;dbname=b')->name());
    }

    public function testResolvesPgsql(): void
    {
        $this->assertSame('pgsql', DialectFactory::fromDsn('pgsql:host=localhost;dbname=b')->name());
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(ApiError::class);
        DialectFactory::fromDsn('oracle:host=localhost');
    }

    public function testQuotingDiffersPerDialect(): void
    {
        $this->assertSame('"posts"', DialectFactory::fromDsn('sqlite::memory:')->quoteIdentifier('posts'));
        $this->assertSame('`posts`', DialectFactory::fromDsn('mysql:host=h')->quoteIdentifier('posts'));
        $this->assertSame('"posts"', DialectFactory::fromDsn('pgsql:host=h')->quoteIdentifier('posts'));
    }

    public function testEveryDialectDefinesAllTypePlaceholders(): void
    {
        foreach (['sqlite::memory:', 'mysql:host=h', 'pgsql:host=h'] as $dsn) {
            $map = DialectFactory::fromDsn($dsn)->typeMap();
            $this->assertArrayHasKey('{AUTO_PK}', $map, $dsn);
            $this->assertArrayHasKey('{DATETIME}', $map, $dsn);
            $this->assertArrayHasKey('{TEXT}', $map, $dsn);
        }
    }

    public function testIdentifierWithQuoteCharacterIsRejected(): void
    {
        $this->expectException(ApiError::class);
        DialectFactory::fromDsn('mysql:host=h')->quoteIdentifier('posts`; DROP TABLE posts; --');
    }
}
