<?php

declare(strict_types=1);

namespace GnuCms\Tests\Db;

use GnuCms\Db\Connection;
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
        // addColumnIfMissing() 경로를 실제로 태운다. MySQL은
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

    /**
     * 대체문자 결과 대기열은 이력 화면을 열 때마다 fallback_status·smid 로 훑는다.
     * 알림톡 대기열과 달리 인덱스가 없어 매번 표 전체를 읽고 있었다.
     */
    #[DataProvider('connectionProvider')]
    public function testFreshInstallIndexesTheFallbackQueue(array $config): void
    {
        $db = $this->freshDatabase($config);

        self::assertTrue($this->hasIndex($db, $config, 'ix_message_recipients_fallback'));
    }

    /** 이미 표가 있는 설치에도 인덱스만 따로 생겨야 한다. */
    #[DataProvider('connectionProvider')]
    public function testUpgradingAddsTheFallbackIndexToAnExistingTable(array $config): void
    {
        $db = $this->freshDatabase($config);
        $db->execute('DROP INDEX ' . $db->index('ix_message_recipients_fallback')
            . ' ON ' . $db->table('message_recipients'));
        self::assertFalse($this->hasIndex($db, $config, 'ix_message_recipients_fallback'));

        (new Schema($db))->migrateAligoMessaging();

        self::assertTrue($this->hasIndex($db, $config, 'ix_message_recipients_fallback'));
    }

    private function hasIndex(Connection $db, array $config, string $logicalName, string $table = 'message_recipients'): bool
    {
        $name = $db->prefix() . $logicalName;
        foreach ($db->select('SHOW INDEX FROM ' . $db->table($table)) as $row) {
            if ((string) ($row['Key_name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }

    #[DataProvider('connectionProvider')]
    public function testScheduleColumnsExistOnAFreshInstall(array $config): void
    {
        $db = $this->freshDatabase($config);
        self::assertSame([], $db->select('SELECT scheduled_at, cancelled_at FROM '
            . $db->table('message_jobs')));
    }

    #[DataProvider('connectionProvider')]
    public function testUpgradingAVersion23InstallAddsTheScheduleColumns(array $config): void
    {
        $db = $this->freshDatabase($config);
        // 판 23 설치는 이 인덱스가 없다 — scheduled_at 위에 얹혀 있으므로 칸보다 먼저 지운다.
        $db->execute('DROP INDEX ' . $db->index('ix_message_jobs_scheduled')
            . ' ON ' . $db->table('message_jobs'));
        $db->execute('ALTER TABLE ' . $db->table('message_jobs') . ' DROP COLUMN scheduled_at');
        $db->execute('ALTER TABLE ' . $db->table('message_jobs') . ' DROP COLUMN cancelled_at');

        (new Schema($db))->migrateAligoMessaging();

        self::assertSame([], $db->select('SELECT scheduled_at, cancelled_at FROM '
            . $db->table('message_jobs')));
    }

    /**
     * 취소 건수 칸(판 25). 이력 한 줄의 산수(총 = 성공 + 실패 + 취소 + 대기 + 불명확)가
     * 이 칸 하나에 걸려 있으므로, 없으면 화면이 아니라 집계 UPDATE 자체가 깨진다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheCancelledCountColumnExistsOnAFreshInstall(array $config): void
    {
        $db = $this->freshDatabase($config);
        self::assertSame([], $db->select('SELECT cancelled FROM ' . $db->table('message_jobs')));
    }

    /**
     * 판 24 설치(예약 칸까지는 있고 취소 건수 칸만 없는 상태)를 흉내 내 올려 본다.
     * 기본값이 0 이어야 이미 쌓여 있던 작업 행이 NULL 로 남지 않는다 — NOT NULL 로
     * 붙이므로 기본값이 없으면 기존 행이 있는 설치에서 마이그레이션 자체가 실패한다.
     */
    #[DataProvider('connectionProvider')]
    public function testUpgradingAVersion24InstallAddsTheCancelledCountColumn(array $config): void
    {
        $db = $this->freshDatabase($config);
        $db->execute('ALTER TABLE ' . $db->table('message_jobs') . ' DROP COLUMN cancelled');
        $jobId = (int) $db->insert('message_jobs', ['channel' => 'sms', 'sender' => '0212345678',
            'body' => '올리기 전에 이미 있던 작업', 'failover' => 0, 'total' => 2, 'success' => 2,
            'failure' => 0, 'status' => 'sent', 'test_mode' => 0, 'created_at' => '2026-09-17 10:00:00']);

        (new Schema($db))->migrateAligoMessaging();

        $row = $db->selectOne('SELECT * FROM ' . $db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertSame(0, (int) $row['cancelled'], '이미 있던 행은 취소 0 으로 채워져야 한다');
        self::assertSame(2, (int) $row['success'], '나머지 값은 그대로다');
    }

    #[DataProvider('connectionProvider')]
    public function testFreshInstallIndexesTheScheduledQueue(array $config): void
    {
        $db = $this->freshDatabase($config);

        self::assertTrue($this->hasIndex($db, $config, 'ix_message_jobs_scheduled', 'message_jobs'));
    }

    /**
     * 판 23 설치는 scheduled_at 칸도 인덱스도 없다. 칸이 생기기 전에 인덱스부터 만들려 하면
     * createIndexIfMissing() 이 "칸 없음" 오류를 "이미 있음"으로 오인해 조용히 삼키고,
     * 인덱스는 영영 만들어지지 않는다 — 칸을 먼저 붙인 뒤 인덱스를 만들어야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testUpgradingAVersion23InstallAddsTheScheduledIndex(array $config): void
    {
        $db = $this->freshDatabase($config);
        $db->execute('DROP INDEX ' . $db->index('ix_message_jobs_scheduled')
            . ' ON ' . $db->table('message_jobs'));
        $db->execute('ALTER TABLE ' . $db->table('message_jobs') . ' DROP COLUMN scheduled_at');
        $db->execute('ALTER TABLE ' . $db->table('message_jobs') . ' DROP COLUMN cancelled_at');
        self::assertFalse($this->hasIndex($db, $config, 'ix_message_jobs_scheduled', 'message_jobs'));

        (new Schema($db))->migrateAligoMessaging();

        self::assertTrue($this->hasIndex($db, $config, 'ix_message_jobs_scheduled', 'message_jobs'));
    }

    public function testSchemaVersionIsThirtyThree(): void
    {
        self::assertSame('33', Schema::VERSION);
    }
}
