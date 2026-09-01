<?php

declare(strict_types=1);

namespace GnuCms\Support;

final class IpAddress
{
    /** 프록시 헤더는 신뢰하지 않고 웹 서버가 확인한 REMOTE_ADDR 만 받는다. */
    public static function fromServer(array $server): ?string
    {
        $raw = $server['REMOTE_ADDR'] ?? null;

        return is_scalar($raw) ? self::normalize((string) $raw) : null;
    }

    public static function normalize(?string $ip): ?string
    {
        $ip = $ip === null ? '' : trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = @inet_pton($ip);

        return $packed === false ? null : (string) inet_ntop($packed);
    }

    /** 공개 화면용. IPv4는 셋째 옥텟, IPv6는 뒤 64비트를 가린다. */
    public static function mask(?string $ip): ?string
    {
        $ip = self::normalize($ip);
        if ($ip === null) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);
            $parts[2] = 'xxx';

            return implode('.', $parts);
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        $groups = array_values(unpack('n8', $packed));
        $shown = array_map(static fn (int $group): string => dechex($group), array_slice($groups, 0, 4));

        return implode(':', array_merge($shown, ['xxxx', 'xxxx', 'xxxx', 'xxxx']));
    }
}
