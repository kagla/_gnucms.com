<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobStatusTest extends TestCase
{
    public function testSevenValuesIncludingScheduleAndCancellation(): void
    {
        self::assertSame(
            ['scheduled', 'sending', 'sent', 'failed', 'partial', 'unknown', 'cancelled'],
            JobStatus::VALUES
        );
    }

    public function testTallyRulesAreUnchanged(): void
    {
        // 예약·취소는 수신자 집계가 아니라 작업 자체의 사실이므로 of() 는 그대로다.
        self::assertSame('sending', JobStatus::of(0, 0, 1, 0));
        self::assertSame('partial', JobStatus::of(1, 1, 0, 0));
        self::assertSame('unknown', JobStatus::of(1, 0, 0, 1));
        self::assertSame('sent', JobStatus::of(2, 0, 0, 0));
        self::assertSame('failed', JobStatus::of(0, 2, 0, 0));
    }
}
