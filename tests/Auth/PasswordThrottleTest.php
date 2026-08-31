<?php

declare(strict_types=1);

namespace GnuCms\Tests\Auth;

use GnuCms\Auth\PasswordThrottle;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PasswordThrottleTest extends WebTestCase
{
    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    private function throttle(array $dbConfig, string $ip = '203.0.113.5'): PasswordThrottle
    {
        return new PasswordThrottle($this->freshDatabase($dbConfig), $ip);
    }

    #[DataProvider('connectionProvider')]
    public function testLocksAfterFiveFailuresInsideTheWindow(array $dbConfig): void
    {
        $t = $this->throttle($dbConfig);

        for ($i = 0; $i < 5; $i++) {
            $t->assertNotLocked('login:a@example.com', 'email');
            $t->recordFailure('login:a@example.com');
        }

        try {
            $t->assertNotLocked('login:a@example.com', 'email');
            self::fail('여섯 번째는 잠겨야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertStringContainsString('너무 많이 틀렸습니다', $e->details()['email']);
            self::assertStringContainsString('분 뒤 다시', $e->details()['email']);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testWindowExpiryResetsTheCounter(array $dbConfig): void
    {
        Clock::freeze('2026-08-31 00:00:00');
        $t = $this->throttle($dbConfig);
        for ($i = 0; $i < 5; $i++) {
            $t->recordFailure('modify:post:1');
        }

        Clock::freeze('2026-08-31 00:10:01');
        $t->assertNotLocked('modify:post:1');

        // 만료 뒤의 실패는 1부터 다시 센다: 다시 5번을 채워야 잠긴다.
        for ($i = 0; $i < 4; $i++) {
            $t->recordFailure('modify:post:1');
        }
        $t->assertNotLocked('modify:post:1');
        $t->recordFailure('modify:post:1');
        try {
            $t->assertNotLocked('modify:post:1');
            self::fail('다시 5번을 채우면 잠겨야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testOtherIpAndOtherKeyAreUnaffected(array $dbConfig): void
    {
        $db = $this->freshDatabase($dbConfig);
        $attacker = new PasswordThrottle($db, '203.0.113.5');
        for ($i = 0; $i < 5; $i++) {
            $attacker->recordFailure('secret:9');
        }

        (new PasswordThrottle($db, '198.51.100.7'))->assertNotLocked('secret:9');
        $attacker->assertNotLocked('secret:10');
        try {
            $attacker->assertNotLocked('secret:9');
            self::fail('같은 IP·같은 대상은 잠겨야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testClearForgivesEarlierFailures(array $dbConfig): void
    {
        $t = $this->throttle($dbConfig);
        for ($i = 0; $i < 5; $i++) {
            $t->recordFailure('login:a@example.com');
        }

        $t->clear('login:a@example.com');

        $t->assertNotLocked('login:a@example.com');
        // 지웠으니 1부터 다시 센다: 5번을 새로 채워야 잠긴다.
        for ($i = 0; $i < 4; $i++) {
            $t->recordFailure('login:a@example.com');
        }
        $t->assertNotLocked('login:a@example.com');
        $t->recordFailure('login:a@example.com');
        try {
            $t->assertNotLocked('login:a@example.com');
            self::fail('다시 5번을 채우면 잠겨야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testMissingIpStillThrottlesAsUnknown(array $dbConfig): void
    {
        $db = $this->freshDatabase($dbConfig);
        $t = new PasswordThrottle($db, null);
        for ($i = 0; $i < 5; $i++) {
            $t->recordFailure('login:a@example.com');
        }

        $this->expectException(DomainError::class);
        (new PasswordThrottle($db, ''))->assertNotLocked('login:a@example.com');
    }

    /** recordFailure 가 이제 원자적 UPDATE 를 먼저 시도한다 — 행이 이미 있을 때 카운트가 맞는지. */
    #[DataProvider('connectionProvider')]
    public function testUpdatePathCountsCorrectlyOnExistingRow(array $dbConfig): void
    {
        $t = $this->throttle($dbConfig);
        $t->recordFailure('login:a@example.com');
        $t->recordFailure('login:a@example.com');
        $t->assertNotLocked('login:a@example.com'); // 2번은 안 잠긴다

        for ($i = 0; $i < 3; $i++) {
            $t->recordFailure('login:a@example.com');
        }
        try {
            $t->assertNotLocked('login:a@example.com');
            self::fail('5번째로 잠겨야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
        }
    }

    /**
     * recordFailure 는 이제 select 후 write 가 아니라 원자적 UPDATE(행이 없을 때만 insert,
     * 그마저 경합에서 지면 재시도)로 되어 있다. 진짜 동시 요청은 한 프로세스 안에서 재현하기
     * 어려우므로, 이 테스트는 두 인스턴스가 같은 db+ip 를 공유할 때 카운트가 서로를 잃어버리지
     * 않고 한 행에 누적되는지만 확인한다. 원자성 자체는 SQL(원자적 UPDATE, UNIQUE 인덱스)이
     * 보장하는 것이지, 이 테스트가 진짜 경합을 재현해서 보장하는 게 아니다.
     */
    #[DataProvider('connectionProvider')]
    public function testTwoInstancesShareOneRowAndAccumulate(array $dbConfig): void
    {
        $db = $this->freshDatabase($dbConfig);
        $a = new PasswordThrottle($db, '203.0.113.5');
        $b = new PasswordThrottle($db, '203.0.113.5');

        for ($i = 0; $i < 3; $i++) {
            $a->recordFailure('login:shared@example.com');
        }
        for ($i = 0; $i < 2; $i++) {
            $b->recordFailure('login:shared@example.com');
        }

        try {
            $a->assertNotLocked('login:shared@example.com');
            self::fail('두 인스턴스의 실패가 합쳐져 5번째로 잠겨야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
        }
    }

    /** sweepExpired() 는 만료된 행만 지우고, 아직 창 안에 있는 행은 건드리지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testSweepExpiredDeletesOnlyExpiredRows(array $dbConfig): void
    {
        Clock::freeze('2026-08-31 00:00:00');
        $db = $this->freshDatabase($dbConfig);
        $old = new PasswordThrottle($db, '203.0.113.5');
        $old->recordFailure('login:old@example.com');

        Clock::freeze('2026-08-31 00:09:00');
        $fresh = new PasswordThrottle($db, '203.0.113.5');
        $fresh->recordFailure('login:fresh@example.com');

        Clock::freeze('2026-08-31 00:10:01'); // old 는 창(600초)을 넘겼고 fresh 는 아직 안이다
        $fresh->sweepExpired();

        $count = static function (string $key) use ($db): int {
            $row = $db->selectOne(
                'SELECT COUNT(*) AS c FROM ' . $db->q('password_attempts') . ' WHERE attempt_key = ?',
                [substr($key, 0, 120)]
            );
            return (int) ($row['c'] ?? 0);
        };
        self::assertSame(0, $count('login:old@example.com'), '만료된 행은 지워져야 한다');
        self::assertSame(1, $count('login:fresh@example.com'), '만료되지 않은 행은 남아야 한다');
    }

    /** modify:post:{id} 와 modify:comment:{id} 는 같은 id 여도 서로 다른 잠금 열쇠다. */
    #[DataProvider('connectionProvider')]
    public function testPostAndCommentLocksAreSeparate(array $dbConfig): void
    {
        $t = $this->throttle($dbConfig);
        for ($i = 0; $i < 5; $i++) {
            $t->recordFailure('modify:post:1');
        }
        try {
            $t->assertNotLocked('modify:post:1');
            self::fail('post:1 은 잠겨야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
        }
        $t->assertNotLocked('modify:comment:1'); // 다른 키이므로 영향이 없어야 한다
    }
}
