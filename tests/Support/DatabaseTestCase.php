<?php

declare(strict_types=1);

namespace GnuCms\Tests\Support;

use PHPUnit\Framework\TestCase;
use GnuCms\Db\Connection;
use GnuCms\Db\Schema;

/**
 * 전용 MySQL/MariaDB 테스트 DB를 사용한다. 운영 DB를 지정하지 않는다.
 */
abstract class DatabaseTestCase extends TestCase
{
    public static function mysqlConfig(string $prefix = ''): array
    {
        $dsn = (string) getenv('TEST_MYSQL_DSN');
        if (!str_starts_with($dsn, 'mysql:')) {
            throw new \RuntimeException('전용 테스트 DB의 TEST_MYSQL_DSN을 설정하세요. 테스트는 해당 DB의 테이블을 삭제합니다.');
        }
        return [
            'dsn' => $dsn,
            'username' => getenv('TEST_MYSQL_USER') ?: null,
            'password' => getenv('TEST_MYSQL_PASS') ?: null,
            'prefix' => $prefix,
        ];
    }

    public static function connectionProvider(): array
    {
        return ['mysql' => [self::mysqlConfig()]];
    }

    protected function freshDatabase(array $config): Connection
    {
        $db = Connection::create($config);
        $schema = new Schema($db);
        $schema->drop();
        $schema->create();

        return $db;
    }
}
