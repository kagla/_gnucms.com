<?php

declare(strict_types=1);

namespace StandardBoard\Db;

use StandardBoard\Db\Dialect\DialectInterface;
use StandardBoard\Db\Dialect\MysqlDialect;
use StandardBoard\Db\Dialect\PgsqlDialect;
use StandardBoard\Db\Dialect\SqliteDialect;
use StandardBoard\Http\ApiError;

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

        throw ApiError::internal('지원하지 않는 DB 드라이버입니다: ' . $driver);
    }
}
