<?php

declare(strict_types=1);

namespace GnuCms\Mail;

use GnuCms\Error\DomainError;

final class NativeMailer implements MailerInterface
{
    private string $from;

    private string $fromName;

    public function __construct(string $from, string $fromName = GNUCMS)
    {
        $this->from = str_replace(["\r", "\n"], '', $from);
        $this->fromName = str_replace(["\r", "\n"], '', $fromName);
    }

    public function send(string $to, string $subject, string $body): void
    {
        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8') : $subject;
        $headers = "From: {$this->fromName} <{$this->from}>\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit";
        if (!mail($to, $encodedSubject, $body, $headers)) {
            throw DomainError::internal('인증 메일을 보내지 못했습니다.');
        }
    }
}
