<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Db\MaintenanceRequired;
use GnuCms\Db\Schema;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class KernelUpgradeTest extends WebTestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/' . GNUCMS_ID . '-kernel-upgrade-' . bin2hex(random_bytes(4));
        mkdir($this->storage, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/backups/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storage . '/backups');
        @unlink($this->storage . '/upgrade.lock');
        @rmdir($this->storage);
        parent::tearDown();
    }

    #[DataProvider('connectionProvider')]
    public function testOldStampIsUpgradedOnFirstRequest(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['storage' => ['dir' => $this->storage]]);
        $app->db()->execute('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?', ['9.oldhash', 'schema_version']);

        $response = $this->get($app, '/');

        self::assertSame(200, $response->getStatusCode());
        $schema = new Schema($app->db());
        self::assertSame($schema->stamp(), $schema->storedStamp());
        if ($app->db()->dialect()->name() === 'sqlite') {
            self::assertCount(1, glob($this->storage . '/backups/board-v9-*.sqlite') ?: []);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testLockedUpgradeRaisesMaintenance(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['storage' => ['dir' => $this->storage]]);
        $app->db()->execute('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?', ['9.oldhash', 'schema_version']);
        $held = fopen($this->storage . '/upgrade.lock', 'c');
        flock($held, LOCK_EX);

        try {
            $this->expectException(MaintenanceRequired::class);
            $this->get($app, '/');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
    }
}
