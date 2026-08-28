<?php

declare(strict_types=1);

namespace GnuCms\Mail;

use GnuCms\Auth\Acl;
use GnuCms\Validation\Validator;

final class MailSettingsService
{
    public const PRESETS = [
        'gmail' => ['host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'ssl'],
        'naver' => ['host' => 'smtp.naver.com', 'port' => 587, 'encryption' => 'tls'],
        'daum' => ['host' => 'smtp.daum.net', 'port' => 465, 'encryption' => 'ssl'],
    ];

    private MailSettingsRepository $settings;
    private SecretCipher $cipher;
    private string $fallbackFrom;

    public function __construct(MailSettingsRepository $settings, SecretCipher $cipher, string $fallbackFrom)
    {
        $this->settings = $settings;
        $this->cipher = $cipher;
        $this->fallbackFrom = $fallbackFrom;
    }

    public function formValues(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        $stored = $this->settings->all();
        return array_merge([
            'enabled' => false, 'provider' => 'gmail', 'host' => 'smtp.gmail.com', 'port' => 465,
            'encryption' => 'ssl', 'username' => '', 'from_email' => $this->fallbackFrom,
            'from_name' => 'gnucms.com', 'password_set' => false,
        ], $stored, [
            'enabled' => ($stored['enabled'] ?? '0') === '1',
            'port' => (int) ($stored['port'] ?? 465),
            'password_set' => ($stored['password'] ?? '') !== '',
            'password' => '',
        ]);
    }

    public function save(Acl $acl, array $input): void
    {
        $acl->assertGlobalAdmin();
        $current = $this->settings->all();
        $v = new Validator($input);
        $enabled = $v->bool('enabled', false);
        $provider = $v->inList('provider', ['gmail', 'naver', 'daum', 'custom'], 'gmail');
        $host = strtolower($v->requiredString('host', 253));
        $port = $v->int('port', 465, 1, 65535);
        $encryption = $v->inList('encryption', ['ssl', 'tls'], 'ssl');
        $username = $v->requiredString('username', 254);
        $fromEmail = strtolower($v->requiredString('from_email', 254));
        $fromName = $v->requiredString('from_name', 100);
        $password = $v->optionalPassword('password');

        if ($provider !== 'custom') {
            $host = self::PRESETS[$provider]['host'];
            $port = self::PRESETS[$provider]['port'];
            $encryption = self::PRESETS[$provider]['encryption'];
        } elseif (preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
            $v->fail('host', '올바른 SMTP 서버 주소를 입력해 주세요.');
        }
        if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            $v->fail('from_email', '올바른 발신 이메일 주소를 입력해 주세요.');
        }
        if ($enabled && $password === null && ($current['password'] ?? '') === '') {
            $v->fail('password', '처음 설정할 때는 앱 비밀번호가 필요합니다.');
        }
        $v->check();

        $saved = [
            'enabled' => $enabled ? '1' : '0', 'provider' => $provider, 'host' => $host,
            'port' => (string) $port, 'encryption' => $encryption, 'username' => $username,
            'from_email' => $fromEmail, 'from_name' => $fromName,
            'password' => $password === null ? (string) ($current['password'] ?? '') : $this->cipher->encrypt($password),
        ];
        $this->settings->save($saved);
    }

    public function runtime(): ?array
    {
        $stored = $this->settings->all();
        if (($stored['enabled'] ?? '0') !== '1' || ($stored['password'] ?? '') === '') {
            return null;
        }
        return [
            'host' => (string) $stored['host'], 'port' => (int) $stored['port'],
            'encryption' => (string) $stored['encryption'], 'username' => (string) $stored['username'],
            'password' => $this->cipher->decrypt((string) $stored['password']),
            'from_email' => (string) $stored['from_email'], 'from_name' => (string) $stored['from_name'],
        ];
    }
}
