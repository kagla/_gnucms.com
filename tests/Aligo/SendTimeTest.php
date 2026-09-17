<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\SendTime;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;
use PHPUnit\Framework\TestCase;

final class SendTimeTest extends TestCase
{
    public function testBlankMeansSendNow(): void
    {
        self::assertNull(SendTime::parse(''));
        self::assertNull(SendTime::parse(null));
    }

    public function testRefusesTooSoonAndTooFar(): void
    {
        $soon = gmdate('Y-m-d\TH:i', Clock::timestamp() + 5 * 60);
        $far = gmdate('Y-m-d\TH:i', Clock::timestamp() + 31 * 86400);

        foreach ([$soon, $far, '어제', '2026-13-45T99:99'] as $value) {
            try {
                SendTime::parse($value);
                self::fail($value . ' 는 거절해야 한다');
            } catch (DomainError $e) {
                self::assertArrayHasKey('scheduled_at', $e->details());
            }
        }
    }

    public function testAcceptsAValidTimeAndStoresItAsUtc(): void
    {
        $at = SendTime::parse(gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $at);
    }

    public function testFormatsForBothApisInKoreanTime(): void
    {
        // 2026-09-18 01:00 UTC 는 한국 시각 10:00 이다. 알리고는 한국 시각으로 받는다.
        self::assertSame('20260918100000', SendTime::alimtalk('2026-09-18 01:00:00'));
        self::assertSame(['rdate' => '20260918', 'rtime' => '1000'],
            SendTime::sms('2026-09-18 01:00:00'));
    }
}
