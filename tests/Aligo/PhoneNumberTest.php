<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\PhoneNumber;
use GnuCms\Error\DomainError;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase
{
    public function testStripsHyphensAndSpaces(): void
    {
        self::assertSame('01012345678', PhoneNumber::normalize('010-1234-5678'));
        self::assertSame('01012345678', PhoneNumber::normalize(' 010 1234 5678 '));
        self::assertSame('01112345678', PhoneNumber::normalize('011-1234-5678'));
    }

    public function testRejectsWhatIsNotAKoreanMobileNumber(): void
    {
        foreach (['0212345678', '15881234', '821012345678', '010123456', '', 'abc'] as $value) {
            self::assertFalse(PhoneNumber::isMobile($value), $value . ' 는 휴대폰이 아니다');
        }
        $this->expectException(DomainError::class);
        PhoneNumber::normalize('02-1234-5678');
    }

    public function testFormatsAndMasks(): void
    {
        self::assertSame('010-1234-5678', PhoneNumber::format('01012345678'));
        self::assertSame('010-****-5678', PhoneNumber::mask('01012345678'));
    }

    public function testSenderAcceptsLandlineAndRepresentativeNumbers(): void
    {
        self::assertSame('0212345678', PhoneNumber::normalizeSender('02-1234-5678'));
        self::assertSame('15881234', PhoneNumber::normalizeSender('1588-1234'));
        self::assertSame('01012345678', PhoneNumber::normalizeSender('010-1234-5678'));
    }

    public function testSenderRejectsNumbersThatAreNotAllocated(): void
    {
        foreach (['1234-5678', '1999-9999', '1000-1234', '123'] as $value) {
            try {
                PhoneNumber::normalizeSender($value);
                self::fail($value . ' should have thrown DomainError');
            } catch (DomainError) {
                // Expected
            }
        }
        // Verify that valid representative numbers still work
        self::assertSame('15881234', PhoneNumber::normalizeSender('1588-1234'));
    }
}
