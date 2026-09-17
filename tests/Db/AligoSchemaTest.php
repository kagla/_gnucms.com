<?php

declare(strict_types=1);

namespace GnuCms\Tests\Db;

use GnuCms\Db\Schema;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AligoSchemaTest extends DatabaseTestCase
{
    #[DataProvider('connectionProvider')]
    public function testFreshInstallHasTheMessagingTables(array $config): void
    {
        $db = $this->freshDatabase($config);
        foreach (['message_jobs', 'message_recipients', 'alimtalk_templates'] as $table) {
            self::assertSame(0, (int) $db->selectOne(
                'SELECT COUNT(*) AS c FROM ' . $db->table($table))['c'], $table);
        }
        self::assertSame([], $db->select('SELECT phone FROM ' . $db->table('users')));
    }

    #[DataProvider('connectionProvider')]
    public function testUpgradingAnOlderInstallCreatesThem(array $config): void
    {
        $db = $this->freshDatabase($config);
        foreach (['message_recipients', 'message_jobs', 'alimtalk_templates'] as $table) {
            $db->execute('DROP TABLE ' . $db->table($table));
        }

        (new Schema($db))->migrateAligoMessaging();

        foreach (['message_jobs', 'message_recipients', 'alimtalk_templates'] as $table) {
            self::assertSame(0, (int) $db->selectOne(
                'SELECT COUNT(*) AS c FROM ' . $db->table($table))['c'], $table);
        }
    }

    public function testSchemaVersionIsTwentyThree(): void
    {
        self::assertSame('23', Schema::VERSION);
    }
}
