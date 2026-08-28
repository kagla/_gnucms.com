<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Support;

use ApiBoard\Mail\MailerInterface;

final class CollectingMailer implements MailerInterface
{
    public array $messages = [];

    public function send(string $to, string $subject, string $body): void
    {
        $this->messages[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }
}
