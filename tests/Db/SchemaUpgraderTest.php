<?php

declare(strict_types=1);

namespace GnuCms\Tests\Db;

use GnuCms\Db\Connection;
use GnuCms\Db\MaintenanceRequired;
use GnuCms\Db\Schema;
use GnuCms\Db\SchemaUpgrader;
use GnuCms\Tests\Support\DatabaseTestCase;
use RuntimeException;

final class SchemaUpgraderTest extends DatabaseTestCase
{
    private string $storage;
    private Connection $db;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/' . GNUCMS_ID . '-upgrader-' . bin2hex(random_bytes(4));
        mkdir($this->storage, 0775, true);
        $this->db =  $this->freshDatabase(self::mysqlConfig());
    }

    protected function tearDown(): void
    {
        @chmod($this->storage, 0775);
        foreach (glob($this->storage . '/backups/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storage . '/backups');
        foreach (glob($this->storage . '/logs/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storage . '/logs');
        @unlink($this->storage . '/upgrade.lock');
        @unlink($this->storage . '/upgrade-failed.json');
        @rmdir($this->storage);
    }

    public function testDoesNothingWhenStampMatches(): void
    {
        $calls = 0;
        $this->upgrader(function () use (&$calls): void { $calls++; })->run();

        self::assertSame(0, $calls);
        self::assertDirectoryDoesNotExist($this->storage . '/backups');
    }

    public function testUpgradesAndRecordsStamp(): void
    {
        $this->setStoredStamp('9.oldhash');

        $this->upgrader()->run();

        $schema = new Schema($this->db);
        self::assertSame($schema->stamp(), $schema->storedStamp());
        self::assertSame('', $this->setting('system.schema_backup'));
        self::assertNotNull($this->setting('system.schema_upgraded_at'));
        self::assertFalse($this->upgrader()->status()['can_backup']);
        self::assertFileDoesNotExist($this->storage . '/upgrade-failed.json');
    }

    public function testFailureWritesMarkerAndThrows(): void
    {
        $this->setStoredStamp('9.oldhash');
        $lines = [];
        $upgrader = $this->upgrader(
            static function (): void { throw new RuntimeException('column boom'); },
            static function (string $line) use (&$lines): void { $lines[] = $line; }
        );

        try {
            $upgrader->run();
            self::fail('MaintenanceRequired 가 나와야 한다');
        } catch (MaintenanceRequired $e) {
            self::assertSame(MaintenanceRequired::FAILED, $e->kind());
            self::assertNull($e->backup());
        }

        self::assertSame('9.oldhash', (new Schema($this->db))->storedStamp());
        $marker = json_decode((string) file_get_contents($this->storage . '/upgrade-failed.json'), true);
        self::assertSame('column boom', $marker['message']);
        self::assertEqualsWithDelta(time(), $marker['at'], 5);
        self::assertStringContainsString('column boom', implode("\n", $lines));
    }

    public function testRecentFailureSkipsRetry(): void
    {
        $this->setStoredStamp('9.oldhash');
        file_put_contents($this->storage . '/upgrade-failed.json', json_encode(['at' => time(), 'message' => 'x', 'backup' => '/tmp/previous-backup.sql']));
        $calls = 0;

        try {
            $this->upgrader(function () use (&$calls): void { $calls++; })->run();
            self::fail('MaintenanceRequired 가 나와야 한다');
        } catch (MaintenanceRequired $e) {
            self::assertSame(MaintenanceRequired::FAILED, $e->kind());
            self::assertSame('/tmp/previous-backup.sql', $e->backup());
        }
        self::assertSame(0, $calls);
    }

    public function testOldFailureIsRetriedAndMarkerRemovedOnSuccess(): void
    {
        $this->setStoredStamp('9.oldhash');
        file_put_contents($this->storage . '/upgrade-failed.json', json_encode(['at' => time() - 61, 'message' => 'x', 'backup' => null]));

        $this->upgrader()->run();

        self::assertFileDoesNotExist($this->storage . '/upgrade-failed.json');
        $schema = new Schema($this->db);
        self::assertSame($schema->stamp(), $schema->storedStamp());
    }

    public function testBusyWhenAnotherRequestHoldsTheLock(): void
    {
        $this->setStoredStamp('9.oldhash');
        $held = fopen($this->storage . '/upgrade.lock', 'c');
        self::assertTrue(flock($held, LOCK_EX));
        $calls = 0;

        try {
            $this->upgrader(function () use (&$calls): void { $calls++; })->run();
            self::fail('MaintenanceRequired 가 나와야 한다');
        } catch (MaintenanceRequired $e) {
            self::assertSame(MaintenanceRequired::BUSY, $e->kind());
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
        self::assertSame(0, $calls);
    }

    public function testUnwritableStorageIsReportedAsFailure(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root 로는 권한 제한을 시험할 수 없다');
        }
        $this->setStoredStamp('9.oldhash');
        chmod($this->storage, 0555);
        $lines = [];

        try {
            $this->upgrader(null, static function (string $line) use (&$lines): void { $lines[] = $line; })->run();
            self::fail('MaintenanceRequired 가 나와야 한다');
        } catch (MaintenanceRequired $e) {
            self::assertSame(MaintenanceRequired::FAILED, $e->kind());
        } finally {
            chmod($this->storage, 0775);
        }

        self::assertStringContainsString('잠금 파일', implode("\n", $lines));
    }

    private function upgrader(?callable $migrate = null, ?callable $log = null): SchemaUpgrader
    {
        return new SchemaUpgrader($this->db, $this->storage, $migrate, $log ?? static function (string $line): void {});
    }

    private function setStoredStamp(string $stamp): void
    {
        $this->db->execute('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?', [$stamp, 'system.schema_version']);
    }

    private function setting(string $key): ?string
    {
        $row = $this->db->selectOne('SELECT setting_value FROM site_settings WHERE setting_key = ?', [$key]);
        return $row === null ? null : (string) $row['setting_value'];
    }
}
