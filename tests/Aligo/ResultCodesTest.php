<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\ResultCodes;
use PHPUnit\Framework\TestCase;

final class ResultCodesTest extends TestCase
{
    public function testExplainsCommonFailuresWithAFix(): void
    {
        self::assertStringContainsString('발신번호', ResultCodes::smsReason('-101', ''));
        self::assertStringContainsString('-99', ResultCodes::smsReason('-99', ''));
        self::assertStringContainsString('발신프로필', ResultCodes::alimtalkReason('509', ''));
        self::assertStringContainsString('템플릿', ResultCodes::deliveryReason('U', ''));
    }

    public function testUnknownCodeKeepsTheOriginalMessage(): void
    {
        $reason = ResultCodes::smsReason('-12345', '알 수 없는 오류');
        self::assertStringContainsString('-12345', $reason);
        self::assertStringContainsString('알 수 없는 오류', $reason);
    }

    public function testUnknownCodeWithoutMessageStillReads(): void
    {
        self::assertStringContainsString('-12345', ResultCodes::smsReason('-12345', ''));
    }

    public function testGenericErrorKeepsTheProviderReasonInsteadOfInventingABalanceFailure(): void
    {
        foreach (['발신번호가 등록되지 않았습니다.', '제목을 확인해 주세요.', '인증오류입니다.'] as $message) {
            $reason = ResultCodes::alimtalkReason('-99', $message);
            self::assertStringContainsString($message, $reason);
            self::assertStringNotContainsString('잔여 건수가 부족', $reason);
        }
        self::assertStringContainsString('포인트가 부족합니다.', ResultCodes::alimtalkReason('-99', '포인트가 부족합니다.'));
    }
}
