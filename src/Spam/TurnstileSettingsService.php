<?php

declare(strict_types=1);

namespace GnuCms\Spam;

use GnuCms\Auth\Acl;
use GnuCms\Mail\SecretCipher;
use GnuCms\Validation\Validator;

final class TurnstileSettingsService
{
    public const CONSOLE_URL = 'https://dash.cloudflare.com/?to=/:account/turnstile';

    public function __construct(
        private TurnstileSettingsRepository $settings,
        private SecretCipher $cipher,
        private array $fallback,
        private string $appUrl
    ) {
    }

    public function formValues(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        $stored = $this->settings->all();

        return [
            'enabled' => array_key_exists('enabled', $stored)
                ? $stored['enabled'] === '1' : (bool) ($this->fallback['enabled'] ?? false),
            'site_key' => $this->value($stored, 'site_key'),
            'secret_key_set' => $this->secretIsSet($stored),
            'hostname' => $this->hostname($stored),
            'console_url' => self::CONSOLE_URL,
        ];
    }

    public function save(Acl $acl, array $input): void
    {
        $acl->assertGlobalAdmin();
        $stored = $this->settings->all();
        $v = new Validator($input);
        $enabled = $v->bool('enabled', false);
        $siteKey = $v->optionalString('site_key', 500, '') ?? '';
        $secretKey = $v->optionalString('secret_key', 1000, null);
        $clearSecret = $v->bool('secret_key_clear', false);
        $hostname = strtolower($v->optionalString('hostname', 253, '') ?? '');
        $hasSecret = !$clearSecret && ($secretKey !== null || $this->secretIsSet($stored));

        if ($enabled && $siteKey === '') {
            $v->fail('site_key', 'Site Key를 입력해 주세요.');
        }
        if ($enabled && !$hasSecret) {
            $v->fail('secret_key', 'Secret Key를 입력해 주세요.');
        }
        if ($enabled && $hostname === '') {
            $v->fail('hostname', 'Turnstile을 적용할 호스트명을 입력해 주세요.');
        }
        if ($hostname !== '' && !$this->validHostname($hostname)) {
            $v->fail('hostname', 'https://와 경로를 제외한 호스트명만 입력해 주세요.');
        }
        if ($secretKey !== null && $clearSecret) {
            $v->fail('secret_key', '새 Secret Key 입력과 기존 키 삭제를 동시에 선택할 수 없습니다.');
        }

        $v->check();
        $saved = [
            'enabled' => $enabled ? '1' : '0',
            'site_key' => $siteKey,
            'hostname' => $hostname,
        ];
        if ($clearSecret) {
            // 빈 값을 명시적으로 저장해 config fallback도 덮어쓴다.
            $saved['secret_key'] = '';
        } elseif ($secretKey !== null) {
            $saved['secret_key'] = $this->cipher->encrypt($secretKey);
        }
        $this->settings->save($saved);
    }

    public function runtime(): array
    {
        $stored = $this->settings->all();
        $config = $this->fallback;
        $config['enabled'] = array_key_exists('enabled', $stored)
            ? $stored['enabled'] === '1' : (bool) ($this->fallback['enabled'] ?? false);
        $config['site_key'] = $this->value($stored, 'site_key');
        $config['hostname'] = $this->hostname($stored);
        if (array_key_exists('secret_key', $stored)) {
            $config['secret_key'] = $stored['secret_key'] === ''
                ? '' : $this->cipher->decrypt($stored['secret_key']);
        } else {
            $config['secret_key'] = (string) ($this->fallback['secret_key'] ?? '');
        }

        return $config;
    }

    public function secretKey(Acl $acl): string
    {
        $acl->assertGlobalAdmin();

        return (string) ($this->runtime()['secret_key'] ?? '');
    }

    private function value(array $stored, string $key): string
    {
        return array_key_exists($key, $stored)
            ? (string) $stored[$key] : trim((string) ($this->fallback[$key] ?? ''));
    }

    private function hostname(array $stored): string
    {
        $hostname = strtolower(trim($this->value($stored, 'hostname')));
        if ($hostname !== '') {
            return $hostname;
        }
        $fromUrl = parse_url($this->appUrl, PHP_URL_HOST);

        return is_string($fromUrl) ? strtolower($fromUrl) : '';
    }

    private function secretIsSet(array $stored): bool
    {
        if (array_key_exists('secret_key', $stored)) {
            return $stored['secret_key'] !== '';
        }

        return trim((string) ($this->fallback['secret_key'] ?? '')) !== '';
    }

    private function validHostname(string $hostname): bool
    {
        if ($hostname === 'localhost') {
            return true;
        }

        return filter_var($hostname, FILTER_VALIDATE_IP) !== false
            || (bool) preg_match(
                '/^(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+'
                . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
                $hostname
            );
    }
}
