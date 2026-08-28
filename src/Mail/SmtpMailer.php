<?php

declare(strict_types=1);

namespace GnuCms\Mail;

use GnuCms\Error\DomainError;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailer implements MailerInterface
{
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function send(string $to, string $subject, string $body): void
    {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string) $this->settings['host'];
            $mail->Port = (int) $this->settings['port'];
            $mail->SMTPAuth = true;
            $mail->Username = (string) $this->settings['username'];
            $mail->Password = (string) $this->settings['password'];
            $mail->SMTPSecure = $this->settings['encryption'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = false;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom((string) $this->settings['from_email'], (string) $this->settings['from_name']);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();
        } catch (MailException $e) {
            throw DomainError::internal('SMTP 메일을 보내지 못했습니다. 계정과 앱 비밀번호를 확인해 주세요.');
        }
    }
}
