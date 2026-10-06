<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Error\DomainError;

/**
 * 문자 단말기 기준의 SMS/LMS 길이를 EUC-KR 바이트로 검사한다.
 * API 요청은 UTF-8이며 이 검사 결과로 HTTP 본문 인코딩을 바꾸지 않는다.
 * 변환할 수 없는 글자는 깨진 문자를 내보내는 대신 거절한다.
 */
final class MessageText
{
    public const SMS_BYTES = 90;
    public const LMS_BYTES = 2000;
    public const TITLE_BYTES = 44;

    public static function toEucKr(string $utf8, string $fieldName = 'body'): string
    {
        $unsupported = self::unsupported($utf8);
        if ($unsupported !== []) {
            throw DomainError::validation([$fieldName =>
                '문자로 보낼 수 없는 글자가 있습니다: ' . implode(' ', $unsupported) . '. 지우고 다시 시도해 주세요.']);
        }

        return (string) mb_convert_encoding($utf8, 'EUC-KR', 'UTF-8');
    }

    /** @return list<string> EUC-KR 로 옮길 수 없는 글자들 */
    public static function unsupported(string $utf8): array
    {
        $found = [];
        $previous = mb_substitute_character();
        mb_substitute_character(0x3F); // '?'
        try {
            foreach (preg_split('//u', $utf8, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                $encoded = (string) mb_convert_encoding($char, 'EUC-KR', 'UTF-8');
                $back = (string) mb_convert_encoding($encoded, 'UTF-8', 'EUC-KR');
                if ($back !== $char && !in_array($char, $found, true)) {
                    $found[] = $char;
                }
            }
        } finally {
            mb_substitute_character($previous);
        }

        return $found;
    }

    public static function byteLength(string $utf8): int
    {
        return strlen((string) mb_convert_encoding($utf8, 'EUC-KR', 'UTF-8'));
    }

    public static function channelFor(string $body): string
    {
        return self::byteLength($body) <= self::SMS_BYTES ? 'sms' : 'lms';
    }

    public static function assertFits(string $body, ?string $title): void
    {
        self::toEucKr($body);
        if (self::byteLength($body) > self::LMS_BYTES) {
            throw DomainError::validation(['body' =>
                '본문이 너무 깁니다. 한글 기준 1,000자(2,000바이트)까지 보낼 수 있습니다.']);
        }
        if ($title !== null && $title !== '') {
            self::toEucKr($title, 'title');
            if (self::byteLength($title) > self::TITLE_BYTES) {
                throw DomainError::validation(['title' =>
                    '제목이 너무 깁니다. 한글 기준 22자(44바이트)까지 넣을 수 있습니다.']);
            }
        }
    }
}
