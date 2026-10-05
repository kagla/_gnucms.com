<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Error\DomainError;
use GnuCms\Mail\MailerInterface;

/**
 * 메일 채널. 기본·저장 문구는 MailBodies·MailEditor가 만들고, 보내는 일은 지금 코어가 쓰는
 * MailerInterface 가 그대로 한다 — 이 채널은 그 둘을 잇기만 한다.
 */
final class MailChannel implements ChannelInterface
{
    private MailerInterface $mailer;
    private \Closure $enabled;
    private \Closure $accepts;
    private \Closure $footer;
    private ?NotifySettings $settings;

    public function __construct(MailerInterface $mailer, ?callable $enabled = null,
        ?callable $accepts = null, ?callable $footer = null, ?NotifySettings $settings = null)
    {
        $this->mailer = $mailer;
        $this->settings = $settings;
        $this->accepts = $accepts === null ? static fn (): bool => true : \Closure::fromCallable($accepts);
        $this->footer = $footer === null ? static fn (): string => '' : \Closure::fromCallable($footer);
        $this->enabled = $enabled === null
            ? static fn (): bool => true
            : \Closure::fromCallable($enabled);
    }

    public function key(): string
    {
        return 'mail';
    }

    public function available(string $event, Recipient $to): bool
    {
        return ($this->enabled)() && $to->email !== null && MailBodies::has($event)
            && ($this->accepts)($event, $to);
    }

    public function send(string $event, Recipient $to, array $vars): void
    {
        if (!$this->available($event, $to)) {
            throw DomainError::validation(['mail' => '메일로 보낼 수 없는 알림입니다.']);
        }

        $mail = $this->settings === null ? MailBodies::render($event, $vars)
            : MailEditor::render($event, $vars, $this->settings->mailTemplate($event));
        $this->mailer->send((string) $to->email, $mail['subject'], $mail['body'] . ($this->footer)($event, $to));
    }
}
