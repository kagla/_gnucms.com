<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Error\DomainError;
use GnuCms\Mail\MailerInterface;

/**
 * 메일 채널. 문구는 MailBodies 가 만들고, 보내는 일은 지금 코어가 쓰는
 * MailerInterface 가 그대로 한다 — 이 채널은 그 둘을 잇기만 한다.
 */
final class MailChannel implements ChannelInterface
{
    private MailerInterface $mailer;

    public function __construct(MailerInterface $mailer)
    {
        $this->mailer = $mailer;
    }

    public function key(): string
    {
        return 'mail';
    }

    public function available(string $event, Recipient $to): bool
    {
        return $to->email !== null && MailBodies::has($event);
    }

    public function send(string $event, Recipient $to, array $vars): void
    {
        if (!$this->available($event, $to)) {
            throw DomainError::validation(['mail' => '메일로 보낼 수 없는 알림입니다.']);
        }

        $mail = MailBodies::render($event, $vars);
        $this->mailer->send((string) $to->email, $mail['subject'], $mail['body']);
    }
}
