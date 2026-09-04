<?php

declare(strict_types=1);

namespace GnuCms\Oauth;

use GnuCms\Auth\Acl;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Validation\Validator;

final class OauthSettingsService
{
    public const PROVIDERS = [
        'google' => 'Google',
        'naver' => '네이버',
        'kakao' => '카카오',
    ];

    private const CONSOLE_URLS = [
        'google' => 'https://console.cloud.google.com/auth/clients',
        'naver' => 'https://developers.naver.com/apps/#/list',
        'kakao' => 'https://developers.kakao.com/console/app',
    ];

    public function __construct(
        private OauthSettingsRepository $settings,
        private SecretCipher $cipher,
        private array $fallback,
        private string $appUrl
    ) {
    }

    public function formValues(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        $stored = $this->settings->all();
        $values = [];
        foreach (self::PROVIDERS as $key => $label) {
            $fallback = is_array($this->fallback[$key] ?? null) ? $this->fallback[$key] : [];
            $secret = (string) ($stored[$key . '.client_secret'] ?? $fallback['client_secret'] ?? '');
            $defaultEnabled = (string) ($fallback['client_id'] ?? '') !== ''
                && ($key === 'kakao' || $secret !== '');
            $values[$key] = [
                'label' => $label,
                'client_id_label' => $key === 'kakao' ? 'REST API 키' : 'Client ID',
                'enabled' => array_key_exists($key . '.enabled', $stored)
                    ? $stored[$key . '.enabled'] === '1' : $defaultEnabled,
                'client_id' => (string) ($stored[$key . '.client_id'] ?? $fallback['client_id'] ?? ''),
                'client_secret_set' => $secret !== '',
                'client_secret_optional' => $key === 'kakao',
                'console_url' => self::CONSOLE_URLS[$key],
                'redirect_uri' => $this->redirectUri($key),
            ];
        }

        return $values;
    }

    public function save(Acl $acl, array $input): void
    {
        $acl->assertGlobalAdmin();
        $stored = $this->settings->all();
        $v = new Validator($input);
        $saved = [];

        foreach (self::PROVIDERS as $key => $label) {
            $enabled = $v->bool($key . '_enabled', false);
            $clientId = $v->optionalString($key . '_client_id', 500, '') ?? '';
            $secret = $v->optionalString($key . '_client_secret', 1000, null);
            $clearSecret = $v->bool($key . '_client_secret_clear', false);
            $fallback = is_array($this->fallback[$key] ?? null) ? $this->fallback[$key] : [];
            $hasSecret = !$clearSecret && ($secret !== null
                || (string) ($stored[$key . '.client_secret'] ?? '') !== ''
                || (string) ($fallback['client_secret'] ?? '') !== '');

            if ($enabled && $clientId === '') {
                $idLabel = $key === 'kakao' ? 'REST API 키' : 'Client ID';
                $v->fail($key . '_client_id', $label . ' ' . $idLabel . '를 입력해 주세요.');
            }
            if ($enabled && $key !== 'kakao' && !$hasSecret) {
                $v->fail($key . '_client_secret', $label . ' Client Secret을 입력해 주세요.');
            }
            if ($secret !== null && $clearSecret) {
                $v->fail($key . '_client_secret', '새 Client Secret 입력과 기존 비밀키 삭제를 동시에 선택할 수 없습니다.');
            }

            $saved[$key . '.enabled'] = $enabled ? '1' : '0';
            $saved[$key . '.client_id'] = $clientId;
            if ($clearSecret) {
                $saved[$key . '.client_secret'] = '';
            } elseif ($secret !== null) {
                $saved[$key . '.client_secret'] = $this->cipher->encrypt($secret);
            }
        }

        $v->check();
        $this->settings->save($saved);
    }

    public function runtime(): array
    {
        $stored = $this->settings->all();
        $config = [];
        foreach (self::PROVIDERS as $key => $label) {
            $fallback = is_array($this->fallback[$key] ?? null) ? $this->fallback[$key] : [];
            $enabled = array_key_exists($key . '.enabled', $stored)
                ? $stored[$key . '.enabled'] === '1'
                : (string) ($fallback['client_id'] ?? '') !== ''
                    && ($key === 'kakao' || (string) ($fallback['client_secret'] ?? '') !== '');
            if (!$enabled) {
                continue;
            }
            $encrypted = (string) ($stored[$key . '.client_secret'] ?? '');
            $config[$key] = [
                'client_id' => (string) ($stored[$key . '.client_id'] ?? $fallback['client_id'] ?? ''),
                'client_secret' => $encrypted !== ''
                    ? $this->cipher->decrypt($encrypted)
                    : (string) ($fallback['client_secret'] ?? ''),
                'redirect_uri' => $this->redirectUri($key),
            ];
        }

        return $config;
    }

    public function clientSecret(Acl $acl, string $provider): string
    {
        $acl->assertGlobalAdmin();
        if (!array_key_exists($provider, self::PROVIDERS)) {
            throw DomainError::notFound('지원하지 않는 소셜 로그인 제공자입니다.');
        }

        $stored = $this->settings->all();
        $key = $provider . '.client_secret';
        if (array_key_exists($key, $stored)) {
            return $stored[$key] === '' ? '' : $this->cipher->decrypt($stored[$key]);
        }
        $fallback = is_array($this->fallback[$provider] ?? null) ? $this->fallback[$provider] : [];

        return (string) ($fallback['client_secret'] ?? '');
    }

    private function redirectUri(string $key): string
    {
        $fallback = is_array($this->fallback[$key] ?? null) ? $this->fallback[$key] : [];
        return (string) ($fallback['redirect_uri'] ?? '') !== ''
            ? (string) $fallback['redirect_uri']
            : rtrim($this->appUrl, '/') . '/auth/' . $key . '/callback';
    }
}
