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
        // 예약은 수신자 집계가 아니라 작업 자체의 사실이므로 of() 가 만들지 않는다.
        self::assertSame('sending', JobStatus::of(0, 0, 1, 0));
        self::assertSame('partial', JobStatus::of(1, 1, 0, 0));
        self::assertSame('unknown', JobStatus::of(1, 0, 0, 1));
        self::assertSame('sent', JobStatus::of(2, 0, 0, 0));
        self::assertSame('failed', JobStatus::of(0, 2, 0, 0));
    }

    /**
     * 취소된 수신자는 성공도 실패도 대기도 아니다 — 그 사람은 메시지를 받지 못했고
     * 앞으로도 받지 못한다(클래스 주석의 규칙). 그래서 취소가 하나라도 있으면 작업은
     * 절대 '성공'이 아니다: 전원이 취소됐으면 'cancelled', 일부만이면 'partial'.
     */
    public function testACancelledRecipientIsNeverCountedAsASuccess(): void
    {
        // 502명 전원 취소 — 아무에게도 가지 않았다.
        self::assertSame('cancelled', JobStatus::of(0, 0, 0, 0, 502));
        // 2명은 실제로 갔고 2명은 멈췄다 — "성공"이라 부르면 작업이 겨냥한 전원이
        // 받았다는 뜻이 된다.
        self::assertSame('partial', JobStatus::of(2, 0, 0, 0, 2));
        // 아직 기다리는 수신자가 있어도, 이미 멈춘 건이 있다는 사실은 지금 참이다.
        self::assertSame('partial', JobStatus::of(0, 0, 3, 0, 1));
        // 나간 것이 하나도 없는데 확인된 실패가 있으면 '일부만 발송'이 아니라 실패다.
        self::assertSame('failed', JobStatus::of(0, 2, 0, 0, 3));
        // 취소가 없으면 예전 그대로다 — 위 testTallyRulesAreUnchanged 와 같은 답.
        self::assertSame('sent', JobStatus::of(2, 0, 0, 0, 0));
    }
}
