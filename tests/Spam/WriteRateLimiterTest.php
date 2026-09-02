<?php

declare(strict_types=1);

namespace GnuCms\Tests\Spam;

use GnuCms\Auth\Acl;
use GnuCms\Auth\Identity;
use GnuCms\Error\DomainError;
use GnuCms\Spam\WriteRateLimiter;
use GnuCms\Support\Clock;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WriteRateLimiterTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCooldownRejectsAndDoesNotConsumeOtherWindows(array $config): void
    {
        $db = $this->freshDatabase($config);
        $limiter = new WriteRateLimiter($db, ['post' => [[30, 1], [600, 5], [86400, 20]]]);
        $guest = new Acl(Identity::guest());
        Clock::freeze('2026-09-02 00:00:00');

        $limiter->consume('post', $guest, '203.0.113.10');
        try {
            $limiter->consume('post', $guest, '203.0.113.10');
            self::fail('두 번째 글은 등록 간격 제한에 걸려야 한다.');
        } catch (DomainError $e) {
            self::assertSame(429, $e->status());
            self::assertSame(30, $e->retryAfter());
            self::assertStringContainsString('30초 후', $e->getMessage());
        }

        $rows = $db->select('SELECT hit_count FROM write_rate_limits ORDER BY window_seconds');
        self::assertCount(3, $rows);
        self::assertSame([1, 1, 1], array_map('intval', array_column($rows, 'hit_count')));

        Clock::freeze('2026-09-02 00:00:30');
        $limiter->consume('post', $guest, '203.0.113.10');
    }

    #[DataProvider('connectionProvider')]
    public function testMembersUseAccountInsteadOfIp(array $config): void
    {
        $db = $this->freshDatabase($config);
        $limiter = new WriteRateLimiter($db, ['comment' => [[5, 1]]]);
        $one = new Acl(Identity::user('10', '한명', false));
        $two = new Acl(Identity::user('20', '다른명', false));
        Clock::freeze('2026-09-02 00:00:00');

        $limiter->consume('comment', $one, '203.0.113.1');
        $limiter->consume('comment', $two, '203.0.113.1');

        $this->expectException(DomainError::class);
        $limiter->consume('comment', $one, '203.0.113.99');
    }

    #[DataProvider('connectionProvider')]
    public function testWindowLimitAndDisabledRules(array $config): void
    {
        $db = $this->freshDatabase($config);
        $guest = new Acl(Identity::guest());
        Clock::freeze('2026-09-02 00:00:00');
        $limiter = new WriteRateLimiter($db, ['post' => [[0, 1], [600, 2], [86400, 0]]]);

        $limiter->consume('post', $guest, '198.51.100.1');
        $limiter->consume('post', $guest, '198.51.100.1');
        try {
            $limiter->consume('post', $guest, '198.51.100.1');
            self::fail('10분 한도 이후에는 거절되어야 한다.');
        } catch (DomainError $e) {
            self::assertSame(600, $e->retryAfter());
        }

        // 다른 IP는 별도 방문자이고 모든 값이 0인 action은 제한하지 않는다.
        $limiter->consume('post', $guest, '198.51.100.2');
        (new WriteRateLimiter($db, ['comment' => [[0, 1], [600, 0]]]))
            ->consume('comment', $guest, '198.51.100.1');
    }

    #[DataProvider('connectionProvider')]
    public function testGlobalAdminIsNotLimited(array $config): void
    {
        $db = $this->freshDatabase($config);
        $limiter = new WriteRateLimiter($db, ['post' => [[30, 1]]]);
        $admin = new Acl(Identity::user('1', '관리자', true));

        $limiter->consume('post', $admin, '203.0.113.1');
        $limiter->consume('post', $admin, '203.0.113.1');

        self::assertSame(0, (int) $db->selectOne('SELECT COUNT(*) AS c FROM write_rate_limits')['c']);
    }
}
