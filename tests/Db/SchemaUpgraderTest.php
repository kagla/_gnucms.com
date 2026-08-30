<?php

declare(strict_types=1);

namespace GnuCms\Tests\Db;

use GnuCms\Db\Connection;
use GnuCms\Db\MaintenanceRequired;
use GnuCms\Db\Schema;
use GnuCms\Db\SchemaUpgrader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaUpgraderTest extends TestCase
{
    private string $storage;
    private Connection $db;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/' . GNUCMS_ID . '-upgrader-' . bin2hex(random_bytes(4));
        mkdir($this->storage, 0775, true);
        $this->db = Connection::create(['dsn' => 'sqlite::memory:']);
        (new Schema($this->db))->create();
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

    public function testUpgradesWithBackupAndRecordsStamp(): void
    {
        $this->setStoredStamp('9.oldhash');

        $this->upgrader()->run();

        $schema = new Schema($this->db);
        self::assertSame($schema->stamp(), $schema->storedStamp());
        $backups = glob($this->storage . '/backups/*.sqlite') ?: [];
        self::assertCount(1, $backups);
        self::assertMatchesRegularExpression('~/board-v9-\d{8}-\d{6}\.sqlite$~', $backups[0]);
        self::assertSame($backups[0], $this->setting('schema_backup'));
        self::assertNotNull($this->setting('schema_upgraded_at'));
        // 백업은 열 수 있는 SQLite 파일이고 표가 들어 있다.
        $copy = Connection::create(['dsn' => 'sqlite:' . $backups[0]]);
        self::assertTrue((new Schema($copy))->exists());
        self::assertFileDoesNotExist($this->storage . '/upgrade-failed.json');
    }

    public function testKeepsOnlyFiveBackups(): void
    {
        mkdir($this->storage . '/backups', 0775, true);
        for ($i = 1; $i <= 5; $i++) {
            touch($this->storage . '/backups/board-v1-20260101-00000' . $i . '.sqlite');
        }
        $this->setStoredStamp('9.oldhash');

        $this->upgrader()->run();

        $names = array_map('basename', glob($this->storage . '/backups/*.sqlite') ?: []);
        self::assertCount(5, $names);
        self::assertNotContains('board-v1-20260101-000001.sqlite', $names);
        self::assertContains('board-v1-20260101-000005.sqlite', $names);
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
            self::assertNotNull($e->backup());
            self::assertFileExists((string) $e->backup());
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
        file_put_contents($this->storage . '/upgrade-failed.json', json_encode(['at' => time(), 'message' => 'x', 'backup' => '/tmp/b.sqlite']));
        $calls = 0;

        try {
            $this->upgrader(function () use (&$calls): void { $calls++; })->run();
            self::fail('MaintenanceRequired 가 나와야 한다');
        } catch (MaintenanceRequired $e) {
            self::assertSame(MaintenanceRequired::FAILED, $e->kind());
            self::assertSame('/tmp/b.sqlite', $e->backup());
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

    public function testRetryReusesExistingBackup(): void
    {
        $this->setStoredStamp('9.oldhash');
        mkdir($this->storage . '/backups', 0775, true);
        $existing = $this->storage . '/backups/board-v9-20260101-000000.sqlite';
        touch($existing);
        file_put_contents($this->storage . '/upgrade-failed.json', json_encode(['at' => time() - 61, 'message' => 'x', 'backup' => $existing]));

        try {
            $this->upgrader(static function (): void { throw new RuntimeException('boom again'); })->run();
            self::fail('MaintenanceRequired 가 나와야 한다');
        } catch (MaintenanceRequired $e) {
            self::assertSame(MaintenanceRequired::FAILED, $e->kind());
            self::assertSame($existing, $e->backup());
        }

        $files = glob($this->storage . '/backups/*.sqlite') ?: [];
        self::assertCount(1, $files);
        self::assertSame($existing, $files[0]);
    }

    public function testRetryTakesNewBackupWhenMarkerBackupIsGone(): void
    {
        $this->setStoredStamp('9.oldhash');
        $gone = $this->storage . '/backups/board-v9-20260101-000000.sqlite';
        file_put_contents($this->storage . '/upgrade-failed.json', json_encode(['at' => time() - 61, 'message' => 'x', 'backup' => $gone]));

        try {
            $this->upgrader(static function (): void { throw new RuntimeException('boom again'); })->run();
            self::fail('MaintenanceRequired 가 나와야 한다');
        } catch (MaintenanceRequired $e) {
            self::assertSame(MaintenanceRequired::FAILED, $e->kind());
            self::assertNotNull($e->backup());
            self::assertNotSame($gone, $e->backup());
        }

        $files = glob($this->storage . '/backups/board-v9-*.sqlite') ?: [];
        self::assertCount(1, $files);
        self::assertFileExists($files[0]);
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

    public function testStatusListsBackupsNewestFirst(): void
    {
        mkdir($this->storage . '/backups', 0775, true);
        touch($this->storage . '/backups/board-v8-20260101-000000.sqlite');
        touch($this->storage . '/backups/board-v9-20260201-000000.sqlite');

        $status = $this->upgrader()->status();

        self::assertSame(Schema::VERSION, $status['version']);
        self::assertSame((new Schema($this->db))->stamp(), $status['stamp']);
        self::assertTrue($status['can_backup']);
        self::assertSame(5, $status['keep']);
        self::assertNull($status['upgraded_at']);
        self::assertSame(
            ['board-v9-20260201-000000.sqlite', 'board-v8-20260101-000000.sqlite'],
            array_column($status['backups'], 'name')
        );
    }

    private function upgrader(?callable $migrate = null, ?callable $log = null): SchemaUpgrader
    {
        return new SchemaUpgrader($this->db, $this->storage, $migrate, $log ?? static function (string $line): void {});
    }

    private function setStoredStamp(string $stamp): void
    {
        $this->db->execute('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?', [$stamp, 'schema_version']);
    }

    private function setting(string $key): ?string
    {
        $row = $this->db->selectOne('SELECT setting_value FROM site_settings WHERE setting_key = ?', [$key]);
        return $row === null ? null : (string) $row['setting_value'];
    }
}
