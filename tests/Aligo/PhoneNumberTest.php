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

    /**
     * 가리는 것이 유일한 일인 함수는 못 가릴 때 열리는 쪽으로 실패하면 안 된다.
     * 형식을 맞추지 못한 값(잘린 번호·외국 번호 등)을 그대로 돌려주면, 번호를
     * 가려 보여 주는 것이 존재 이유인 관리자 회원 목록이 그 번호를 통째로 보여 준다.
     */
    public function testMaskingHidesAValueItCannotFormat(): void
    {
        self::assertSame('*******', PhoneNumber::mask('0101234'));
        self::assertStringNotContainsString('1234', PhoneNumber::mask('0101234'));
        self::assertStringNotContainsString('12345678', PhoneNumber::mask('821012345678'));
        self::assertSame('', PhoneNumber::mask(''), '번호가 없으면 별도 남기지 않는다');
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
