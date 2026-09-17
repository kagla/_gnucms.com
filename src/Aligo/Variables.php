<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Error\DomainError;

/**
 * 본문의 #{변수}를 다룬다. 빈 값으로 보내면 알림톡은 템플릿 불일치(rslt: U)로 떨어지고
 * 문자는 뜻이 깨진 채로 나가므로, 값이 없으면 보내기 전에 거절한다.
 */
final class Variables
{
    private const PATTERN = '/#\{([^}\r\n]{1,50})\}/u';

    /** @return list<string> */
    public static function names(string $body): array
    {
        preg_match_all(self::PATTERN, $body, $matches);
        $names = [];
        foreach ($matches[1] as $name) {
            $name = trim($name);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return list<string> */
    public static function missing(string $body, array $values): array
    {
        $missing = [];
        foreach (self::names($body) as $name) {
            $value = $values[$name] ?? null;
            if ($value === null || trim((string) $value) === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    public static function apply(string $body, array $values): string
    {
        $missing = self::missing($body, $values);
        if ($missing !== []) {
            throw DomainError::validation(['vars' =>
                '값이 비어 있는 변수가 있습니다: ' . implode(', ', $missing)]);
        }

        return (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $m): string => (string) $values[trim($m[1])],
            $body
        );
    }
}
