<?php

declare(strict_types=1);

namespace GnuCms\Mail;

use GnuCms\Auth\Acl;
use GnuCms\Validation\Validator;

final class MailSettingsService
{
    public const MODE_DISABLED = 'disabled';
    public const MODE_NATIVE = 'native';
    public const MODE_SMTP = 'smtp';
    public const MODES = [self::MODE_DISABLED, self::MODE_NATIVE, self::MODE_SMTP];

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
        $mode = $this->modeFrom($stored);
        return array_merge([
            'mode' => self::MODE_NATIVE, 'enabled' => false,
            'provider' => 'gmail', 'host' => 'smtp.gmail.com', 'port' => 465,
            'encryption' => 'ssl', 'username' => '', 'from_email' => $this->fallbackFrom,
            'from_name' => GNUCMS, 'password_set' => false,
        ], $stored, [
            'mode' => $mode,
            // 예전 화면·확장에서 읽을 수 있도록 SMTP 여부도 계속 제공한다.
            'enabled' => $mode === self::MODE_SMTP,
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
        // mode가 없는 요청은 이전 버전 폼과 호환한다.
        $mode = array_key_exists('mode', $input)
            ? $v->inList('mode', self::MODES, self::MODE_NATIVE)
            : ($v->bool('enabled', false) ? self::MODE_SMTP : self::MODE_NATIVE);

        $provider = $v->inList('provider', ['gmail', 'naver', 'daum', 'custom'],
            (string) ($current['provider'] ?? 'gmail'));
        $host = strtolower((string) ($current['host'] ?? 'smtp.gmail.com'));
        $port = (int) ($current['port'] ?? 465);
        $encryption = (string) ($current['encryption'] ?? 'ssl');
        $username = (string) ($current['username'] ?? '');
        $fromEmail = (string) ($current['from_email'] ?? $this->fallbackFrom);
        $fromName = (string) ($current['from_name'] ?? GNUCMS);
        $password = $v->optionalPassword('password');

        if ($mode === self::MODE_SMTP) {
            $host = strtolower($v->requiredString('host', 253));
            $port = $v->int('port', 465, 1, 65535);
            $encryption = $v->inList('encryption', ['ssl', 'tls'], 'ssl');
            $username = $v->requiredString('username', 254);
            $fromEmail = strtolower($v->requiredString('from_email', 254));
            $fromName = $v->requiredString('from_name', 100);

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
            if ($password === null && ($current['password'] ?? '') === '') {
                $v->fail('password', '처음 설정할 때는 앱 비밀번호가 필요합니다.');
            }
        }
        $v->check();

        $saved = [
            'mode' => $mode,
            // 이전 버전과 외부 코드가 SMTP 사용 여부로 읽던 값은 함께 유지한다.
            'enabled' => $mode === self::MODE_SMTP ? '1' : '0',
            'provider' => $provider, 'host' => $host,
            'port' => (string) $port, 'encryption' => $encryption, 'username' => $username,
            'from_email' => $fromEmail, 'from_name' => $fromName,
            'password' => $password === null ? (string) ($current['password'] ?? '') : $this->cipher->encrypt($password),
        ];
        $this->settings->save($saved);
    }

    public function password(Acl $acl): string
    {
        $acl->assertGlobalAdmin();
        $stored = (string) ($this->settings->all()['password'] ?? '');

        return $stored === '' ? '' : $this->cipher->decrypt($stored);
    }

    public function runtime(): ?array
    {
        $stored = $this->settings->all();
        if ($this->modeFrom($stored) !== self::MODE_SMTP || ($stored['password'] ?? '') === '') {
            return null;
        }
        return [
            'host' => (string) $stored['host'], 'port' => (int) $stored['port'],
            'encryption' => (string) $stored['encryption'], 'username' => (string) $stored['username'],
            'password' => $this->cipher->decrypt((string) $stored['password']),
            'from_email' => (string) $stored['from_email'], 'from_name' => (string) $stored['from_name'],
        ];
    }

    public function mode(): string
    {
        return $this->modeFrom($this->settings->all());
    }

    public function enabled(): bool
    {
        return $this->mode() !== self::MODE_DISABLED;
    }

    private function modeFrom(array $stored): string
    {
        $mode = (string) ($stored['mode'] ?? '');
        if (in_array($mode, self::MODES, true)) {
            return $mode;
        }

        return ($stored['enabled'] ?? '0') === '1' ? self::MODE_SMTP : self::MODE_NATIVE;
    }
}
