<?php

declare(strict_types=1);

namespace ApiBoard\Db;

use ApiBoard\Db\Dialect\DialectInterface;
use ApiBoard\Db\Dialect\MysqlDialect;
use ApiBoard\Db\Dialect\PgsqlDialect;
use ApiBoard\Db\Dialect\SqliteDialect;
use ApiBoard\Error\DomainError;

final class DialectFactory
{
    public static function fromDsn(string $dsn): DialectInterface
    {
        $driver = strtolower(substr($dsn, 0, (int) strpos($dsn, ':')));

        switch ($driver) {
            case 'sqlite':
                return new SqliteDialect();
            case 'mysql':
                return new MysqlDialect();
            case 'pgsql':
                return new PgsqlDialect();
        }

        throw DomainError::internal('지원하지 않는 DB 드라이버입니다: ' . $driver);
    }
}
