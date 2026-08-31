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
}
