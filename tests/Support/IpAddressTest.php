<?php

declare(strict_types=1);

namespace GnuCms\Tests\Support;

use GnuCms\Support\IpAddress;
use PHPUnit\Framework\TestCase;

final class IpAddressTest extends TestCase
{
    public function testUsesOnlyValidRemoteAddress(): void
    {
        self::assertSame('203.0.113.7', IpAddress::fromServer(['REMOTE_ADDR' => '203.0.113.7']));
        self::assertNull(IpAddress::fromServer(['REMOTE_ADDR' => 'not-an-ip']));
        self::assertNull(IpAddress::fromServer(['HTTP_X_FORWARDED_FOR' => '203.0.113.7']));
    }

    public function testMasksIpv4AndIpv6(): void
    {
        self::assertSame('255.255.xxx.255', IpAddress::mask('255.255.10.255'));
        self::assertSame(
            '2001:db8:abcd:12:xxxx:xxxx:xxxx:xxxx',
            IpAddress::mask('2001:db8:abcd:12:3456:789a:bcde:f012')
        );
    }
}
