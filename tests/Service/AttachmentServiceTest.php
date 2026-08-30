<?php

declare(strict_types=1);

namespace GnuCms\Tests\Service;

use GnuCms\Service\AttachmentService;
use PHPUnit\Framework\TestCase;

final class AttachmentServiceTest extends TestCase
{
    public function testServerMaxMbIsPositive(): void
    {
        // php.ini 값에 따라 다르지만 항상 1 이상의 정수여야 한다.
        self::assertGreaterThanOrEqual(1, AttachmentService::serverMaxMb());
    }

    public function testIniShorthandIsParsed(): void
    {
        self::assertSame(8, AttachmentService::iniToMb('8M'));
        self::assertSame(1024, AttachmentService::iniToMb('1G'));
        self::assertSame(1, AttachmentService::iniToMb('1536K'));
        self::assertSame(2, AttachmentService::iniToMb('2097152'));
        self::assertSame(PHP_INT_MAX, AttachmentService::iniToMb('0'), '0 은 무제한이라는 뜻이다');
        self::assertSame(PHP_INT_MAX, AttachmentService::iniToMb('-1'));
    }
}
