<?php

declare(strict_types=1);

namespace GnuCms\Tests\Db;

use PHPUnit\Framework\TestCase;
use GnuCms\Db\Dialect\MysqlDialect;
use GnuCms\Error\DomainError;

final class MysqlDialectTest extends TestCase
{
    public function testMysqlQuotesIdentifiers(): void
    {
        $this->assertSame('`posts`', (new MysqlDialect())->quoteIdentifier('posts'));
    }

    public function testMysqlDefinesAllTypePlaceholders(): void
    {
        $map = (new MysqlDialect())->typeMap();
        $this->assertArrayHasKey('{AUTO_PK}', $map);
        $this->assertArrayHasKey('{DATETIME}', $map);
        $this->assertArrayHasKey('{TEXT}', $map);
    }

    public function testIdentifierWithQuoteCharacterIsRejected(): void
    {
        $this->expectException(DomainError::class);
        (new MysqlDialect())->quoteIdentifier('posts`; DROP TABLE posts; --');
    }
}
