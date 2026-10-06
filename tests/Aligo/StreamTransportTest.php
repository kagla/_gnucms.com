<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\StreamTransport;
use GnuCms\Aligo\TransportFailure;
use PHPUnit\Framework\TestCase;

final class StreamTransportTest extends TestCase
{
    public function testEncodesFieldsAsFormBody(): void
    {
        $transport = new StreamTransport();
        // 실제 요청 없이 본문 구성만 확인한다.
        self::assertSame('key=a+b&user_id=%ED%99%8D', $transport->encode(['key' => 'a b', 'user_id' => '홍']));
    }

    public function testKoreanMessageAndTitleRoundTripAsUtf8(): void
    {
        $fields = ['title' => '한글 안내', 'msg_1' => '회원가입 주문 배송 취소 가나다', 'rec_1' => '01012345678'];
        parse_str((new StreamTransport())->encode($fields), $decoded);
        self::assertSame($fields, $decoded);
        self::assertTrue(mb_check_encoding($decoded['msg_1'], 'UTF-8'));
        self::assertStringNotContainsString('?', $decoded['msg_1']);
    }

    public function testDroppedFieldsWithNullValueAreNotSent(): void
    {
        $transport = new StreamTransport();
        self::assertSame('key=a', $transport->encode(['key' => 'a', 'title' => null]));
    }

    public function testUnreachableHostFails(): void
    {
        $transport = new StreamTransport();
        $this->expectException(TransportFailure::class);
        $transport->post('https://127.0.0.1:1/none', ['key' => 'x'], 1);
    }
}
