<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use DateTimeImmutable;
use DateTimeZone;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

/**
 * 예약 발송 시각 한 곳. 화면·API가 준 값을 검증해 저장용 UTC 문자열로 바꾸고
 * (parse()·parseKst()), 그 저장값을 알리고에 보낼 형식으로 바꾼다(alimtalk()·sms()).
 *
 * 시간대는 이 클래스 밖에서 다루지 않는다. parse() 는 받은 문자열을 이미 UTC로 본다 —
 * 오프셋을 적용하지 않는다. 화면의 datetime-local 입력처럼 호출부가 한국 표준시(KST,
 * UTC+9, 서머타임 없음) 벽시계 값을 쥐고 있을 때는 parse() 가 아니라 parseKst() 를
 * 쓴다 — 형식·하한·상한 검사는 완전히 같은 규칙을 공유하고, KST→UTC 로 해석하는
 * 시간대 하나만 다르다. 두 메서드 모두 message_jobs.scheduled_at 과 같은 UTC
 * 'Y-m-d H:i:s' 문자열을 돌려준다(Clock 과 같은 규칙). 알리고에 보낼 때는 반대 방향을
 * alimtalk()·sms() 가 담당한다. KST와 UTC를 서로 바꾸는 곳은 이 클래스 안
 * (parseKst()·toKst())뿐이다 — 이 클래스 밖 어디서도 9시간을 더하거나 빼지 않는다.
 * 여기서 틀리면(빠뜨리거나 방향을 반대로 하면) 모든 예약 발송이 9시간 어긋난 시각에
 * 나간다.
 */
final class SendTime
{
    /** 하한 — 알리고 문자 API 가 요구하는 최소 여유. 알림톡에도 같은 규칙을 적용해 설명을 하나로 둔다. */
    public const MIN_MINUTES = 10;

    /** 상한 — 우리가 정한 값. 기한 없는 예약은 아무도 기억하지 못하는 발송이 된다. */
    public const MAX_DAYS = 30;

    /** 한국 표준시는 UTC+9, 서머타임이 없다. */
    private const KST_OFFSET_SECONDS = 9 * 3600;

    /**
     * 화면·API가 준 값(이미 UTC로 본다)을 검증해 UTC 'Y-m-d H:i:s' 문자열로 돌려준다.
     * 비어 있으면 null(=즉시 발송)이다. 형식이 잘못됐거나 하한·상한을 벗어나면
     * DomainError::validation(['scheduled_at' => …])를 던진다.
     */
    public static function parse(mixed $value): ?string
    {
        return self::parseIn($value, new DateTimeZone('UTC'));
    }

    /**
     * parse() 와 검증 규칙(형식·하한·상한)은 완전히 같지만, 받은 값을 UTC가 아니라
     * 한국 표준시(KST) 벽시계로 해석한다. 화면의 다른 모든 시각(요청 시각 등)과
     * 마찬가지로 관리자는 발송 시각도 KST로 읽고 쓴다 — datetime-local 입력처럼 그
     * 값을 그대로 쥐고 있는 호출부는 반드시 이 메서드를 써야 한다. parse() 로 잘못
     * 넣으면 9시간 어긋난 시각에 나간다.
     */
    public static function parseKst(mixed $value): ?string
    {
        return self::parseIn($value, new DateTimeZone('Asia/Seoul'));
    }

    /** 알림톡(senddate)용 — 한국 시각 'YYYYMMDDHHMMSS'. $utc 는 저장돼 있는 UTC 문자열이다. */
    public static function alimtalk(string $utc): string
    {
        return gmdate('YmdHis', self::toKst($utc));
    }

    /** 문자(rdate·rtime)용 — 한국 시각 날짜와 분. $utc 는 저장돼 있는 UTC 문자열이다. */
    public static function sms(string $utc): array
    {
        $kst = self::toKst($utc);

        return ['rdate' => gmdate('Ymd', $kst), 'rtime' => gmdate('Hi', $kst)];
    }

    /** parse()·parseKst() 가 공유하는 실제 검증. 입력을 어느 시간대로 읽을지($zone)만 다르다. */
    private static function parseIn(mixed $value, DateTimeZone $zone): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $timestamp = self::toTimestamp($raw, $zone);
        if ($timestamp === null) {
            throw DomainError::validation(['scheduled_at' => '예약 시각의 형식이 올바르지 않습니다.']);
        }

        $min = Clock::timestamp() + self::MIN_MINUTES * 60;
        $max = Clock::timestamp() + self::MAX_DAYS * 86400;
        if ($timestamp < $min || $timestamp > $max) {
            throw DomainError::validation(['scheduled_at' =>
                '예약은 10분 뒤부터 30일 이내로 정해 주세요.']);
        }

        // 저장은 UTC. $zone 으로 해석해 얻은 절대 시각(타임스탬프)을 UTC 문자열로 되돌린다.
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /** UTC 저장값을 한국 시각(KST)의 타임스탬프로 바꾼다 — 변환은 이 한 곳뿐이다. */
    private static function toKst(string $utc): int
    {
        return strtotime($utc . ' UTC') + self::KST_OFFSET_SECONDS;
    }

    /** 여러 흔한 형식(T 또는 공백 구분, 초 유무)을 주어진 시간대로 해석한 타임스탬프로 바꾼다. 실패하면 null. */
    private static function toTimestamp(string $raw, DateTimeZone $zone): ?int
    {
        $normalized = str_contains($raw, 'T') ? $raw : preg_replace('/\s+/', 'T', $raw, 1);

        foreach (['!Y-m-d\TH:i:s', '!Y-m-d\TH:i'] as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $normalized, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
            if ($dt !== false && $clean) {
                return $dt->getTimestamp();
            }
        }

        return null;
    }
}
