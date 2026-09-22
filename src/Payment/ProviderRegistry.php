<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

final class ProviderRegistry
{
    /** @var array<string,Provider> */
    private array $providers = [];

    public function __construct() { $this->register(new InicisProvider()); }

    public function register(Provider $provider): void
    {
        $id = $provider->id();
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id) || isset($this->providers[$id])) {
            throw new \InvalidArgumentException('결제사 ID가 잘못되었거나 이미 등록되어 있습니다.');
        }
        $this->providers[$id] = $provider;
    }

    public function get(string $id): Provider
    {
        return $this->providers[$id] ?? throw DomainError::validation(['provider' => '지원하지 않는 결제사입니다.']);
    }

    /** @return array<string,string> */
    public function labels(): array
    {
        return array_map(static fn (Provider $provider): string => $provider->label(), $this->providers);
    }
}
