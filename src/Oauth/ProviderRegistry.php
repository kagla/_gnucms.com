<?php

declare(strict_types=1);

namespace GnuCms\Oauth;

use GnuCms\Error\DomainError;

final class ProviderRegistry
{
    private const ALLOWED = ['google', 'naver', 'kakao'];

    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function __construct(array $config = [], ?array $providers = null)
    {
        if ($providers !== null) {
            foreach ($providers as $provider) {
                if ($provider instanceof ProviderInterface && in_array($provider->key(), self::ALLOWED, true)) {
                    $this->providers[$provider->key()] = $provider;
                }
            }
            return;
        }

        $classes = [
            'google' => GoogleProvider::class,
            'naver' => NaverProvider::class,
            'kakao' => KakaoProvider::class,
        ];
        foreach ($classes as $key => $class) {
            $item = isset($config[$key]) && is_array($config[$key]) ? $config[$key] : [];
            $hasCredentials = ($item['client_id'] ?? '') !== ''
                && ($key === 'kakao' || ($item['client_secret'] ?? '') !== '');
            if ($hasCredentials && ($item['redirect_uri'] ?? '') !== '') {
                $this->providers[$key] = new $class($item);
            }
        }
    }

    public function get(string $key): ProviderInterface
    {
        if (!isset($this->providers[$key])) {
            throw DomainError::notFound('사용할 수 없는 소셜 로그인입니다.');
        }
        return $this->providers[$key];
    }

    public function options(): array
    {
        $options = [];
        foreach (self::ALLOWED as $key) {
            if (isset($this->providers[$key])) {
                $options[] = ['key' => $key, 'label' => $this->providers[$key]->label()];
            }
        }
        return $options;
    }
}
