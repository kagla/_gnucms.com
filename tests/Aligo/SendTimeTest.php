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

    /** 명시적 오프셋이 붙어 있어도 하한·상한 검증은 똑같이 적용된다 — 오프셋이 검증을 피해 가지 않는다. */
    public function testOffsetInputSharesTheSameBounds(): void
    {
        $tooSoon = gmdate('Y-m-d\TH:i', Clock::timestamp() + 60) . '+00:00';

        try {
            SendTime::parse($tooSoon);
            self::fail('10분 안쪽 예약은 오프셋이 있어도 거절해야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('scheduled_at', $e->details());
        }
    }

    /**
     * 오프셋 없는 입력은 한국 표준시(KST) 벽시계로 읽는다 — 그 절대 시각(지금부터
     * 1시간 뒤)이 UTC 로 저장된다. 정확한 숫자로 왕복을 검증한다(형식만 보는 정규식이
     * 아니라): 저장값이 실제로 "그 벽시계가 가리키는 절대 시각"과 같아야 한다.
     */
    public function testAcceptsAValidTimeAndStoresItAsUtc(): void
    {
        $targetUtc = Clock::timestamp() + 3600;
        $bareKst = gmdate('Y-m-d\TH:i', $targetUtc + 9 * 3600);

        $at = SendTime::parse($bareKst);

        self::assertSame(gmdate('Y-m-d H:i:00', $targetUtc), $at);
    }

    public function testFormatsForBothApisInKoreanTime(): void
    {
        // 2026-09-18 01:00 UTC 는 한국 시각 10:00 이다. 알리고는 한국 시각으로 받는다.
        self::assertSame('20260918100000', SendTime::alimtalk('2026-09-18 01:00:00'));
        self::assertSame(['rdate' => '20260918', 'rtime' => '1000'],
            SendTime::sms('2026-09-18 01:00:00'));
    }

    /**
     * 오프셋 없는 문자열은 한국 표준시(KST) 벽시계로 읽는다 — 화면의 datetime-local
     * 입력과 확장이 오프셋 없이 넘기는 값 모두 이 규칙 하나를 공유한다. "14:00" 을
     * 오프셋 없이 넣으면 저장은 그보다 9시간 이른 UTC "05:00" 이어야 한다 — 이
     * 부호(빼기)와 크기(9시간)를 둘 다 못박는다.
     */
    public function testBareInputIsReadAsKoreanWallClockTime(): void
    {
        $date = gmdate('Y-m-d', Clock::timestamp() + 86400); // 내일 날짜(하한·상한 안에 들어오게)
        $typedKst = $date . 'T14:00';

        self::assertSame($date . ' 05:00:00', SendTime::parse($typedKst));
    }

    /**
     * FIX 1 의 핵심: 확장이 명시적으로 "+09:00" 오프셋을 붙이면, 오프셋을 붙이지 않은
     * 같은 벽시계 문자열과 정확히 같은 절대 시각(=같은 저장값)이 나와야 한다 — 오프셋
     * 없는 입력이 이미 KST 로 읽히므로, "+09:00"은 그 읽기를 명시적으로 확인해 줄 뿐
     * 다른 결과를 내면 안 된다.
     */
    public function testExplicitKstOffsetBooksTheSameInstantAsBareInput(): void
    {
        $date = gmdate('Y-m-d', Clock::timestamp() + 86400);
        $bare = $date . 'T14:00';
        $withOffset = $bare . '+09:00';

        self::assertSame(SendTime::parse($bare), SendTime::parse($withOffset));
        self::assertSame($date . ' 05:00:00', SendTime::parse($withOffset));
    }

    /**
     * 다른 명시적 오프셋("+00:00")은 같은 벽시계 숫자라도 다른 절대 시각을 가리켜야
     * 한다 — 오프셋이 실제로 존중되고 있다는 증거는 "같은 것으로 읽는다"가 아니라
     * "다른 오프셋은 다르게 읽는다"에 있다. "+00:00"으로 준 "14:00"은 UTC 14:00 그
     * 자체이므로, 오프셋 없이(KST 로) 읽은 "14:00"(=UTC 05:00)보다 정확히 9시간 뒤다.
     */
    public function testADifferentExplicitOffsetBooksADifferentInstant(): void
    {
        $date = gmdate('Y-m-d', Clock::timestamp() + 86400);
        $kstReading = SendTime::parse($date . 'T14:00');
        $utcReading = SendTime::parse($date . 'T14:00+00:00');

        self::assertNotSame($kstReading, $utcReading);
        self::assertSame($date . ' 14:00:00', $utcReading);
        self::assertSame(
            strtotime($utcReading . ' UTC') - strtotime($kstReading . ' UTC'),
            9 * 3600
        );
    }

    /** "Z"(UTC를 뜻하는 ISO-8601 표기)도 명시적 오프셋으로 취급되어 "+00:00"과 같은 결과를 낸다. */
    public function testZSuffixIsTreatedAsAnExplicitUtcOffset(): void
    {
        $date = gmdate('Y-m-d', Clock::timestamp() + 86400);

        self::assertSame(SendTime::parse($date . 'T14:00+00:00'), SendTime::parse($date . 'T14:00Z'));
    }

    /**
     * 관리자 화면이 실제로 만들어 내는 철자를 직접 확인한다: parse() 가 돌려준 저장용
     * UTC 문자열(공백 구분, 초 있음)에 withUtcOffset() 이 오프셋을 붙인 값이다. 이 값은
     * Dispatch::send() 안에서 parse() 를 한 번 더 지나므로, 여기서 오프셋이 무시되고
     * KST 로 다시 읽히면 모든 화면 예약이 9시간 이르게 나간다. 그동안 이 철자는 화면
     * 전체를 도는 시험에서만 간접적으로 지켜지고 있었다.
     */
    public function testTheSpaceSeparatedUtcSpellingTheScreenProducesSurvivesASecondParse(): void
    {
        $once = SendTime::parse(gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z');
        self::assertNotNull($once);

        $tagged = SendTime::withUtcOffset($once);
        self::assertSame($once . '+00:00', $tagged, '저장값 그대로에 오프셋만 붙는다');
        self::assertStringContainsString(' ', $tagged, '화면이 넘기는 값은 공백으로 구분된 철자다');

        self::assertSame($once, SendTime::parse($tagged),
            '두 번째 parse() 는 이미 절대 시각인 값을 그대로 존중해야 한다');
    }
}
