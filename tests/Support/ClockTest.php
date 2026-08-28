<?php

declare(strict_types=1);

namespace GnuCms\Tests\Support;

use PHPUnit\Framework\TestCase;
use GnuCms\Support\Clock;

final class ClockTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    public function testNowReturnsSortableUtcFormat(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            Clock::now()
        );
    }

    public function testNowIsUtcNotLocalTime(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Seoul');
        try {
            $this->assertSame(gmdate('Y-m-d H:i'), substr(Clock::now(), 0, 16));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testFreezeMakesTimeDeterministic(): void
    {
        Clock::freeze('2026-08-26 01:02:03');

        $this->assertSame('2026-08-26 01:02:03', Clock::now());
        $this->assertSame('2026-08-26 01:02:03', gmdate('Y-m-d H:i:s', Clock::timestamp()));
    }

    public function testUnfreezeRestoresRealTime(): void
    {
        Clock::freeze('2000-01-01 00:00:00');
        Clock::unfreeze();

        $this->assertNotSame('2000-01-01 00:00:00', Clock::now());
    }
}
