<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use DateTimeImmutable;
use DateTimeZone;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

/**
 * 예약 발송 시각 한 곳. 화면·확장이 준 값을 검증해 저장용 UTC 문자열로 바꾸고
 * (parse()), 그 저장값을 알리고에 보낼 형식으로 바꾼다(alimtalk()·sms()).
 *
 * 시간대는 이 클래스 밖에서 다루지 않는다. parse() 가 받는 문자열이 명시적인
 * 오프셋을 달고 있으면(예: "2026-09-20T14:00:00+09:00", 또는 "Z") 그 오프셋 그대로
 * 절대 시각을 읽는다. 오프셋이 없으면(화면의 datetime-local 입력처럼
 * "2026-09-20T14:00") 사이트가 실제로 쓰는 시간대, 즉 한국 표준시(KST, UTC+9,
 * 서머타임 없음) 벽시계로 읽는다. 화면과 확장(모듈·플러그인, $app->aligo()->
 * send(['scheduled_at' => …]))은 이 규칙 하나만 공유한다 — 화면이 보내는 값은
 * datetime-local 이 오프셋을 낼 수 없으므로 언제나 오프셋 없이 KST 로 읽힌다.
 * 확장이 오프셋 없이 한국 시각 벽시계 값을 그대로 넘겨도 똑같이 KST 로 읽혀 제
 * 시각에 예약되고, 이미 다른 시간대에서 계산해 둔 절대 시각을 쥐고 있다면
 * 오프셋을 붙여 넘기면 그대로 존중된다. 형식·하한·상한 검사는 오프셋 유무와
 * 무관하게 완전히 같다. 저장은 언제나 message_jobs.scheduled_at 과 같은 UTC
 * 'Y-m-d H:i:s' 문자열이다(Clock 과 같은 규칙). 알리고에 보낼 때는 반대 방향을
 * alimtalk()·sms() 가 담당한다. KST와 UTC를 서로 바꾸는 곳은 이 클래스 안
 * (parse()·toKst())뿐이다 — 이 클래스 밖 어디서도 9시간을 더하거나 빼지 않는다.
 * 여기서 틀리면(빠뜨리거나 방향을 반대로 하면) 모든 예약 발송이 9시간 어긋난
 * 시각에 나간다.
 */
final class SendTime
{
    /** 하한 — 알리고 문자 API 가 요구하는 최소 여유. 알림톡에도 같은 규칙을 적용해 설명을 하나로 둔다. */
    public const MIN_MINUTES = 10;

    /** 상한 — 우리가 정한 값. 기한 없는 예약은 아무도 기억하지 못하는 발송이 된다. */
    public const MAX_DAYS = 30;

    /** 한국 표준시는 UTC+9, 서머타임이 없다. 오프셋 없는 입력을 읽거나 저장값을 되돌릴 때만 쓴다. */
    private const KST_OFFSET_SECONDS = 9 * 3600;

    /**
     * 화면·확장이 준 값을 검증해 UTC 'Y-m-d H:i:s' 문자열로 돌려준다. 비어 있으면
     * null(=즉시 발송)이다. 오프셋 유무에 따라 어느 시간대로 읽는지는 클래스 문서
     * 주석을 따른다. 형식이 잘못됐거나 하한·상한을 벗어나면
     * DomainError::validation(['scheduled_at' => …])를 던진다.
     */
    public static function parse(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $timestamp = self::toTimestamp($raw);
        if ($timestamp === null) {
            throw DomainError::validation(['scheduled_at' => '예약 시각의 형식이 올바르지 않습니다.']);
        }

        $min = Clock::timestamp() + self::MIN_MINUTES * 60;
        $max = Clock::timestamp() + self::MAX_DAYS * 86400;
        if ($timestamp < $min || $timestamp > $max) {
            throw DomainError::validation(['scheduled_at' =>
                '예약은 10분 뒤부터 30일 이내로 정해 주세요.']);
        }

        // 저장은 UTC. 오프셋을 적용해(또는 KST로 해석해) 얻은 절대 시각(타임스탬프)을
        // UTC 문자열로 되돌린다.
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * parse() 가 돌려준 저장용 UTC 문자열에 UTC 오프셋을 붙여 돌려준다. 그 값이 다시
     * parse() 를 거칠 때 오프셋 없는 벽시계로 보이지 않게 하는 표식이다 — 관리자 화면은
     * 검증이 끝난 값을 요청 배열에 담아 Dispatch::send() 에 넘기고, 그 안에서 parse() 가
     * 한 번 더 돈다(확장과 같은 문을 쓰기 때문이다). 표식이 없으면 이미 UTC 인 값이
     * 두 번째 parse() 에서 KST 로 다시 읽혀 9시간이 또 빠진다.
     *
     * 이 문자열 형식을 정하는 곳이 parse() 와 같아야 하므로(형식을 아는 것은 이 클래스
     * 하나뿐이다) 호출부가 '+00:00' 을 직접 이어 붙이지 않고 이 메서드를 쓴다.
     */
    public static function withUtcOffset(string $utc): string
    {
        return $utc . '+00:00';
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

    /** UTC 저장값을 한국 시각(KST)의 타임스탬프로 바꾼다 — 변환은 이 한 곳뿐이다. */
    private static function toKst(string $utc): int
    {
        return strtotime($utc . ' UTC') + self::KST_OFFSET_SECONDS;
    }

    /**
     * 여러 흔한 형식(T 또는 공백 구분, 초 유무)을 시간대 규칙(클래스 문서 주석 참고)에
     * 따라 해석한 타임스탬프로 바꾼다. 실패하면 null.
     *
     * 문자열 끝에 명시적 오프셋("+09:00"·"+0900"·"Z")이 있으면 그 오프셋으로,
     * 없으면 한국 표준시(KST) 벽시계로 읽는다. 오프셋 판정은 날짜 부분(T 앞)의
     * '-'와 섞이지 않도록 T 뒤(시각 부분)만 본다.
     */
    private static function toTimestamp(string $raw): ?int
    {
        $normalized = str_contains($raw, 'T') ? $raw : preg_replace('/\s+/', 'T', $raw, 1);
        $tPos = strpos($normalized, 'T');
        $timePart = $tPos === false ? '' : substr($normalized, $tPos + 1);

        if (preg_match('/Z$/i', $timePart) === 1) {
            $normalized = substr($normalized, 0, -1) . '+00:00';
            $timePart = substr($timePart, 0, -1) . '+00:00';
        }

        if (preg_match('/[+\-]\d{2}:?\d{2}$/', $timePart) === 1) {
            // +HHMM 을 +HH:MM 으로 맞춘다 — 형식 지정자 P(콜론 있는 오프셋) 하나로 둘 다 받는다.
            $normalized = (string) preg_replace('/([+\-]\d{2})(\d{2})$/', '$1:$2', $normalized, 1);

            return self::tryFormats($normalized, ['!Y-m-d\TH:i:sP', '!Y-m-d\TH:iP'], new DateTimeZone('UTC'));
        }

        return self::tryFormats($normalized, ['!Y-m-d\TH:i:s', '!Y-m-d\TH:i'], new DateTimeZone('Asia/Seoul'));
    }

    /** @param list<string> $formats */
    private static function tryFormats(string $normalized, array $formats, DateTimeZone $zone): ?int
    {
        foreach ($formats as $format) {
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
