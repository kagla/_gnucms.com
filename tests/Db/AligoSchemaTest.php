<?php

declare(strict_types=1);

namespace GnuCms\Tests\Db;

use GnuCms\Db\Schema;
use GnuCms\Error\DomainError;
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
        // create() 가 users.phone 을 이미 넣어 두므로, 22 판 이하 설치처럼 지워서
        // addColumnIfMissing() 경로를 실제로 태운다. 최신 SQLite 와 MySQL 은 둘 다
        // ALTER TABLE ... DROP COLUMN 을 지원한다 — 같은 방식을 이미
        // testProfileImageMigrationAddsUserColumns() 가 users 표의 다른 칸에 쓰고 있다.
        $db->execute('ALTER TABLE ' . $db->table('users') . ' DROP COLUMN phone');

        try {
            $db->selectOne('SELECT phone FROM ' . $db->table('users') . ' LIMIT 1');
            self::fail('phone 칸이 지워지지 않았다');
        } catch (DomainError $e) {
            // 지워졌다. 의도한 상태다.
        }

        (new Schema($db))->migrateAligoMessaging();

        foreach (['message_jobs', 'message_recipients', 'alimtalk_templates'] as $table) {
            self::assertSame(0, (int) $db->selectOne(
                'SELECT COUNT(*) AS c FROM ' . $db->table($table))['c'], $table);
        }

        // 칸이 되살아났을 뿐 아니라 실제로 쓸 수 있는지까지 확인한다.
        $id = $db->insert('users', [
            'email' => 'phone-upgrade@example.com', 'email_verified' => 1, 'password_hash' => null,
            'display_name' => '전화회원', 'is_admin' => 0, 'status' => 'active', 'session_epoch' => 0,
            'phone' => '010-1234-5678',
            'created_at' => '2026-09-17 00:00:00', 'updated_at' => '2026-09-17 00:00:00',
        ]);
        $row = $db->selectOne('SELECT phone FROM ' . $db->table('users') . ' WHERE id = ?', [$id]);
        self::assertSame('010-1234-5678', $row['phone']);
    }

    /**
     * 세 표가 한 번에 사라지지 않은 설치도 있을 수 있다. alimtalk_templates 만 없는 상황을
     * 흉내 내, 없는 표만 새로 생기고 이미 있던 두 표는 (안의 행까지) 그대로인지 본다.
     * 행을 미리 심어 두는 이유는, 마이그레이션이 있던 표를 조용히 다시 만들어 버리는
     * 실수를 잡기 위해서다 — 단순히 표가 '있다'는 것만으로는 그 실수를 못 잡는다.
     */
    #[DataProvider('connectionProvider')]
    public function testUpgradingWithOnlyOneMissingTableLeavesTheOthersAlone(array $config): void
    {
        $db = $this->freshDatabase($config);

        $jobId = $db->insert('message_jobs', [
            'channel' => 'sms', 'sender' => '01000000000', 'body' => '본문',
            'status' => 'queued', 'created_at' => '2026-09-17 00:00:00',
        ]);
        $recipientId = $db->insert('message_recipients', [
            'job_id' => (int) $jobId, 'phone' => '01011112222', 'body' => '본문',
            'status' => 'queued',
        ]);

        $db->execute('DROP TABLE ' . $db->table('alimtalk_templates'));

        (new Schema($db))->migrateAligoMessaging();

        self::assertSame(0, (int) $db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $db->table('alimtalk_templates'))['c']);

        $job = $db->selectOne('SELECT id FROM ' . $db->table('message_jobs') . ' WHERE id = ?', [(int) $jobId]);
        self::assertNotNull($job, 'message_jobs 의 기존 행이 지워지면 안 된다');
        $recipient = $db->selectOne('SELECT id FROM ' . $db->table('message_recipients') . ' WHERE id = ?',
            [(int) $recipientId]);
        self::assertNotNull($recipient, 'message_recipients 의 기존 행이 지워지면 안 된다');
    }

    public function testSchemaVersionIsTwentyThree(): void
    {
        self::assertSame('23', Schema::VERSION);
    }
}
