<?php

declare(strict_types=1);

namespace GnuCms\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** 사용자에게 보이는 날짜·시간. 저장값과 API 날짜 형식에는 사용하지 않는다. */
final class DateTimeDisplay
{
    public const FORMAT = 'y-m-d H:i:s';

    /** DB 문자열은 UTC로 읽고, 타임스탬프는 지정한 표시 시간대로 바꾼다. */
    public static function format(mixed $value, string $timezone, string $format = self::FORMAT): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            $date = is_int($value)
                ? new DateTimeImmutable('@' . $value)
                : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));

            return $date->setTimezone(new DateTimeZone($timezone))->format($format);
        } catch (Throwable $e) {
            return '';
        }
    }
}
