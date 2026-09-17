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

    /**
     * parseKst() 는 parse() 와 달리 받은 문자열을 한국 표준시(KST) 벽시계로 본다 —
     * 화면의 datetime-local 입력이 쓰는 유일한 입구다. "14:00" 을 KST 로 넣으면 저장은
     * 그보다 9시간 이른 UTC "05:00" 이어야 한다 — 이 부호(빼기)와 크기(9시간)를 둘 다
     * 못박는다. parse() 로 같은 문자열을 넣으면(이미 UTC로 본다) 다른 값이 나온다는
     * 것도 함께 확인해, 두 메서드가 실제로 다른 시간대를 본다는 것을 증명한다.
     */
    public function testParseKstReadsTheInputAsKoreanWallClockTime(): void
    {
        $date = gmdate('Y-m-d', Clock::timestamp() + 86400); // 내일 날짜(하한·상한 안에 들어오게)
        $typedKst = $date . 'T14:00';

        self::assertSame($date . ' 05:00:00', SendTime::parseKst($typedKst));
        self::assertNotSame(SendTime::parseKst($typedKst), SendTime::parse($typedKst));
    }

    /** parseKst() 도 parse() 와 같은 하한·상한을 적용한다 — 검증 규칙은 공유된다. */
    public function testParseKstSharesTheSameBoundsAsParse(): void
    {
        $tooSoonKst = gmdate('Y-m-d\TH:i', Clock::timestamp() + 60 + 9 * 3600);

        try {
            SendTime::parseKst($tooSoonKst);
            self::fail('10분 안쪽 예약은 KST 입구에서도 거절해야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('scheduled_at', $e->details());
        }
    }
}
