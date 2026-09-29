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
            throw DomainError::internal($this->failureMessage($mail, $e), $e);
        }
    }

    private function failureMessage(PHPMailer $mail, MailException $exception): string
    {
        $detail = strtolower($exception->getMessage() . ' ' . $mail->ErrorInfo);
        if (str_contains($detail, 'no permitted from-header address')
            || str_contains($detail, 'sender address rejected')
            || str_contains($detail, 'mail from command failed')) {
            return 'SMTP 서버가 발신 이메일 주소를 허용하지 않았습니다. 발신 이메일을 로그인한 메일 계정과 같은 주소로 저장해 주세요.';
        }
        if (str_contains($detail, 'authenticate') || str_contains($detail, 'authentication')) {
            return 'SMTP 인증에 실패했습니다. 사용자 이름, 앱 비밀번호와 메일 서비스의 SMTP 사용 설정을 확인해 주세요.';
        }
        if (str_contains($detail, 'connect') || str_contains($detail, 'connection')) {
            return 'SMTP 서버에 연결하지 못했습니다. 서버 주소, 포트와 보안 연결 방식을 확인해 주세요.';
        }
        if (str_contains($detail, 'recipient address rejected')
            || str_contains($detail, 'rcpt to command failed')) {
            return 'SMTP 서버가 수신 이메일 주소를 거부했습니다. 테스트할 이메일 주소를 확인해 주세요.';
        }
        if (str_contains($detail, 'certificate') || str_contains($detail, 'crypto')) {
            return 'SMTP 보안 연결을 맺지 못했습니다. SSL/TLS 방식과 서버 인증서를 확인해 주세요.';
        }
        if (str_contains($detail, 'data not accepted') || str_contains($detail, 'data end command failed')) {
            return 'SMTP 서버가 메일 내용을 거부했습니다. 발신 이메일과 메일 서비스의 발송 정책을 확인해 주세요.';
        }

        return 'SMTP 서버가 메일을 받지 않았습니다. 저장한 서버 설정과 메일 서비스 상태를 확인해 주세요.';
    }
}
