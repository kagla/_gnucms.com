<?php

declare(strict_types=1);

namespace GnuCms\Db;

use GnuCms\Db\Dialect\DialectInterface;
use GnuCms\Db\Dialect\MysqlDialect;
use GnuCms\Error\DomainError;

final class DialectFactory
{
    public static function fromDsn(string $dsn): DialectInterface
    {
        $driver = strtolower(substr($dsn, 0, (int) strpos($dsn, ':')));

        switch ($driver) {
            case 'mysql':
                return new MysqlDialect();
        }

        throw DomainError::internal('MySQL/MariaDB만 지원합니다. 지원하지 않는 DB 드라이버: ' . $driver);
    }
}
