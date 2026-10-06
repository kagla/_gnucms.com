<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\SmsResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmsResultTest extends TestCase
{
    public static function results(): array
    {
        return [
            'actual in progress' => ['전송중', 'accepted'],
            'documented completed' => ['발송완료', 'sent'],
            'sending' => ['발송중', 'accepted'],
            'waiting' => ['발송대기', 'accepted'],
            'empty result' => ['', 'accepted'],
            'new state' => ['새 전송 상태', 'accepted'],
            'legacy success' => ['전송성공', 'sent'],
            'completed' => ['전송완료', 'sent'],
            'failure' => ['전송실패', 'failed'],
            'recipient missing' => ['가입자없음', 'failed'],
            'blocked' => ['수신거부', 'failed'],
            'success word in failure' => ['전송성공 처리 오류', 'failed'],
        ];
    }

    #[DataProvider('results')]
    public function testTerminalAndPendingResults(string $state, string $expected): void
    {
        self::assertSame($expected, SmsResult::status(['sms_state' => $state]));
    }

    public function testMissingOrMalformedResultRemainsPending(): void
    {
        self::assertSame('accepted', SmsResult::status([]));
        self::assertSame('accepted', SmsResult::status(['sms_state' => []]));
    }
}
