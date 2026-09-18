<?php

declare(strict_types=1);

namespace GnuCms\Tests\Notify;

use GnuCms\Error\DomainError;
use GnuCms\Notify\MailChannel;
use GnuCms\Notify\Recipient;
use GnuCms\Tests\Support\CollectingMailer;
use GnuCms\Tests\Support\DatabaseTestCase;

/**
 * 채널 넷의 시험대.
 *
 * 알리고를 타는 두 채널은 진짜 AligoService 에 FakeAligoTransport 를 물려 돌린다.
 * 계획의 초안은 send(array): int 만 가진 익명 클래스로 대신하라고 했지만 AligoService·
 * NotifySettings 는 둘 다 final 이라 타입이 맞지 않는다(계획 4 의 초안도 NotifySettings
 * 에 대해 같은 이유로 진짜 객체를 쓰라고 적고 있다). 대신 실제로 남는 message_jobs·
 * message_recipients 행과 알리고로 나간 필드를 본다 — 흉내 낸 배열보다 강한 검사다.
 */
final class ChannelsTest extends DatabaseTestCase
{
    public function testMailIsUnavailableWithoutAnAddress(): void
    {
        $channel = new MailChannel(new CollectingMailer());

        self::assertSame('mail', $channel->key());
        self::assertTrue($channel->available('password_reset',
            Recipient::forEmail('a@example.com', '홍길동')));
        self::assertFalse($channel->available('password_reset',
            Recipient::forUser(['id' => '1', 'display_name' => '홍길동', 'email' => ''])));
    }

    public function testMailSendsTheRenderedSubjectAndBody(): void
    {
        $mailer = new CollectingMailer();
        (new MailChannel($mailer))->send('password_reset',
            Recipient::forEmail('a@example.com', '홍길동'),
            ['사이트명' => '우리 커뮤니티', '이름' => '홍길동',
                '링크' => 'https://example.com/r', '유효시간' => '1시간']);

        self::assertSame('a@example.com', $mailer->messages[0]['to']);
        self::assertSame('[우리 커뮤니티] 비밀번호 재설정', $mailer->messages[0]['subject']);
        self::assertStringContainsString('https://example.com/r', $mailer->messages[0]['body']);
    }

    /** available() 이 거절할 상태에서는 send() 도 보내지 않는다 — 조용히 넘어가지 않고
     *  거절해서, available() 을 건너뛴 호출자가 드러나게 한다. */
    public function testMailRefusesToSendWithoutAnAddress(): void
    {
        $mailer = new CollectingMailer();
        $channel = new MailChannel($mailer);

        try {
            $channel->send('password_reset', Recipient::forUser(['id' => '1', 'display_name' => '홍']), []);
            self::fail('주소가 없는데 메일을 보냈습니다');
        } catch (DomainError $e) {
            self::assertSame([], $mailer->messages);
        }
    }
}
