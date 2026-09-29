<?php

declare(strict_types=1);

namespace GnuCms\Support;

/**
 * 모든 시각의 단일 출처. 저장 형식은 UTC 'Y-m-d H:i:s' 문자열이며,
 * MySQL에서 문자열 정렬 순서가 시간순과 일치한다.
 */
final class Clock
{
    /** @var string|null 테스트에서 고정한 시각 */
    private static $frozen = null;

    public static function now(): string
    {
        if (self::$frozen !== null) {
            return self::$frozen;
        }

        return gmdate('Y-m-d H:i:s');
    }

    public static function timestamp(): int
    {
        if (self::$frozen !== null) {
            return (int) strtotime(self::$frozen . ' UTC');
        }

        return time();
    }

    public static function freeze(string $utc): void
    {
        self::$frozen = $utc;
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
    }
}
