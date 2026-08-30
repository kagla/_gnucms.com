<?php

declare(strict_types=1);

namespace GnuCms\Tests\Install;

use GnuCms\Db\Connection;
use GnuCms\Db\Schema;
use GnuCms\Error\DomainError;
use GnuCms\Install\DbSetup;
use PHPUnit\Framework\TestCase;

final class DbSetupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/' . GNUCMS_ID . '-dbsetup-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/board.sqlite');
        @rmdir($this->dir);
    }

    public function testAvailableTypesFollowLoadedDrivers(): void
    {
        self::assertSame(['sqlite', 'pgsql'], DbSetup::availableTypes(['pdo', 'pdo_sqlite', 'pdo_pgsql']));
        self::assertSame([], DbSetup::availableTypes(['pdo']));
    }

    public function testSqliteDsnFromAbsolutePath(): void
    {
        $db = DbSetup::dsnFrom(['type' => 'sqlite', 'sqlite_path' => $this->dir . '/board.sqlite']);

        self::assertSame(['dsn' => 'sqlite:' . $this->dir . '/board.sqlite', 'username' => null, 'password' => null], $db);
    }

    public function testSqliteRejectsRelativePathAndUnwritableFolder(): void
    {
        $this->assertValidation(['type' => 'sqlite', 'sqlite_path' => 'storage/board.sqlite'], 'sqlite_path');
        $this->assertValidation(['type' => 'sqlite', 'sqlite_path' => '/nonexistent-' . bin2hex(random_bytes(3)) . '/board.sqlite'], 'sqlite_path');
    }

    public function testMysqlDsnIsAssembled(): void
    {
        $db = DbSetup::dsnFrom(['type' => 'mysql', 'host' => 'db.local', 'port' => '3307', 'name' => 'site', 'user' => 'u', 'password' => 'p']);

        self::assertSame('mysql:host=db.local;port=3307;dbname=site;charset=utf8mb4', $db['dsn']);
        self::assertSame('u', $db['username']);
        self::assertSame('p', $db['password']);
    }

    public function testPgsqlDsnUsesDefaultPort(): void
    {
        $db = DbSetup::dsnFrom(['type' => 'pgsql', 'host' => 'localhost', 'name' => 'site', 'user' => 'u']);

        self::assertSame('pgsql:host=localhost;port=5432;dbname=site', $db['dsn']);
        self::assertSame('', $db['password']);
    }

    public function testServerFieldsAreValidated(): void
    {
        try {
            DbSetup::dsnFrom(['type' => 'mysql', 'host' => 'a;b', 'port' => '70000', 'name' => '', 'user' => '']);
            self::fail('422 가 나와야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertSame(['host', 'port', 'name', 'user'], array_keys($e->details()));
        }
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->assertValidation(['type' => 'oracle'], 'type');
    }

    public function testProbeReportsEmptyThenTablesThenAdmin(): void
    {
        $config = DbSetup::dsnFrom(['type' => 'sqlite', 'sqlite_path' => $this->dir . '/board.sqlite']);

        $empty = DbSetup::probe($config);
        self::assertSame(['dialect' => 'sqlite', 'has_tables' => false, 'has_admin' => false], $empty);

        $db = Connection::create($config);
        (new Schema($db))->create();
        self::assertSame(['dialect' => 'sqlite', 'has_tables' => true, 'has_admin' => false], DbSetup::probe($config));

        $db->insert('users', [
            'email' => 'a@example.com', 'email_verified' => 1, 'password_hash' => 'x', 'display_name' => '관리자',
            'is_admin' => 1, 'status' => 'active', 'session_epoch' => 0,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        self::assertTrue(DbSetup::probe($config)['has_admin']);
    }

    public function testProbeFailureIsAValidationError(): void
    {
        try {
            DbSetup::probe(['dsn' => 'mysql:host=127.0.0.1;port=1;dbname=nope', 'username' => 'x', 'password' => 'y']);
            self::fail('422 가 나와야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertArrayHasKey('_', $e->details());
        }
    }

    private function assertValidation(array $input, string $field): void
    {
        try {
            DbSetup::dsnFrom($input);
            self::fail('422 가 나와야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertArrayHasKey($field, $e->details());
        }
    }
}
