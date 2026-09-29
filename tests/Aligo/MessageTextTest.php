<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\MessageText;
use GnuCms\Error\DomainError;
use PHPUnit\Framework\TestCase;

final class MessageTextTest extends TestCase
{
    public function testCountsBytesInEucKrNotUtf8(): void
    {
        // 한글은 EUC-KR 2바이트, UTF-8 3바이트다. 45자는 EUC-KR 90바이트로 아직 SMS다.
        self::assertSame(90, MessageText::byteLength(str_repeat('가', 45)));
        self::assertSame('sms', MessageText::channelFor(str_repeat('가', 45)));
        self::assertSame('lms', MessageText::channelFor(str_repeat('가', 46)));
        self::assertSame('sms', MessageText::channelFor(str_repeat('a', 90)));
        self::assertSame('lms', MessageText::channelFor(str_repeat('a', 91)));
    }

    public function testRejectsCharactersEucKrCannotCarry(): void
    {
        try {
            MessageText::toEucKr('주문이 완료되었습니다 🎉');
            self::fail('이모지는 거절해야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('body', $e->details());
            self::assertStringContainsString('🎉', $e->details()['body']);
        }
    }

    public function testRejectsTooLongBodyAndTitle(): void
    {
        $this->expectException(DomainError::class);
        MessageText::assertFits(str_repeat('가', 1001), null); // 2002바이트
    }

    public function testRejectsTooLongTitle(): void
    {
        $this->expectException(DomainError::class);
        MessageText::assertFits('짧은 본문', str_repeat('가', 23)); // 46바이트
    }

    public function testAcceptsBoundaryValues(): void
    {
        // 정확한 경계값이 통과해야 한다.
        // 2000바이트 본문 (한글 1000자)
        MessageText::assertFits(str_repeat('가', 1000), null);
        // 44바이트 제목 (한글 22자)
        MessageText::assertFits('짧은 본문', str_repeat('가', 22));
        // SMS 경계값: 90바이트는 SMS, 91바이트는 LMS
        self::assertSame('sms', MessageText::channelFor(str_repeat('a', 90)));
        self::assertSame('lms', MessageText::channelFor(str_repeat('a', 91)));
    }
}
