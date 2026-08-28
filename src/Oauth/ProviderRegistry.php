<?php

declare(strict_types=1);

namespace ApiBoard\Oauth;

use ApiBoard\Error\DomainError;

final class ProviderRegistry
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function __construct(array $config = [], ?array $providers = null)
    {
        if ($providers !== null) {
            foreach ($providers as $provider) {
                if ($provider instanceof ProviderInterface) {
                    $this->providers[$provider->key()] = $provider;
                }
            }
            return;
        }

        $classes = [
            'google' => GoogleProvider::class,
            'naver' => NaverProvider::class,
            'kakao' => KakaoProvider::class,
            'github' => GithubProvider::class,
        ];
        foreach ($classes as $key => $class) {
            $item = isset($config[$key]) && is_array($config[$key]) ? $config[$key] : [];
            if (($item['client_id'] ?? '') !== '' && ($item['client_secret'] ?? '') !== '' && ($item['redirect_uri'] ?? '') !== '') {
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
        return array_map(static fn(ProviderInterface $provider): array => [
            'key' => $provider->key(), 'label' => $provider->label(),
        ], array_values($this->providers));
    }
}
