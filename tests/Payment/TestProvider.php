<?php

declare(strict_types=1);

namespace GnuCms\Tests\Payment;

use GnuCms\Error\DomainError;
use GnuCms\Payment\{DirectGateway, Gateway, Provider, Settings};

/** 외부 요청 없이 서로 다른 PG 계약을 검증하는 테스트 전용 어댑터. */
final class TestProvider implements Provider
{
    public function __construct(private string $name = 'testpg', private array $supported = ['card'], private bool $partial = true) {}
    public function id(): string { return $this->name; }
    public function label(): string { return 'Test PG ' . $this->name; }
    public function fields(): array
    {
        return ['account' => ['label' => 'Account', 'secret' => false, 'multiline' => false],
            'token' => ['label' => 'Token', 'secret' => true, 'multiline' => false]];
    }
    public function manual(): string { return 'https://example.test/docs'; }
    public function validate(array $input, array $before, string $environment): array
    {
        if (!is_string($input['account'] ?? null) || $input['account'] === '' || !is_string($input['token'] ?? null) || $input['token'] === '') {
            throw DomainError::validation(['account' => 'Account and token required']);
        }
        return array_intersect_key($input, $this->fields());
    }
    public function credentials(array $revision, ?array $current): array
    {
        if ($current !== null && $revision['account'] === $current['account']) $revision['token'] = $current['token'];
        return $revision;
    }
    public function methods(): array { return $this->supported; }
    public function supportsPartialRefund(): bool { return $this->partial; }
    public function checkoutTemplate(): string { return 'payment/testpg'; }
    public function gateway(Settings $settings): Gateway
    {
        return new class($settings) extends DirectGateway {
            public array $calls = [];
            public function checkout(array $order, array $customer, string $returnUrl, string $callbackUrl, string $device = 'web'): array
            {
                $this->prepare($order, $returnUrl, $callbackUrl);
                $this->calls[] = ['checkout', $order['id']];
                return ['kind' => 'testpg', 'fields' => [], 'action' => 'https://example.test/pay'];
            }
            protected function validateCallback(array $config, array $order, array $callback): void
            {
                if (($callback['proof'] ?? '') !== $order['id']) throw DomainError::forbidden('Invalid proof');
            }
            protected function approve(array $config, array $order, array $callback): array
            {
                $this->calls[] = ['approve', $order['id']];
                return ['transaction_id' => 'tx-' . $order['id']];
            }
            protected function query(array $config, array $order, array $state): array
            {
                $this->calls[] = ['fetch', $order['id']];
                $cancelled = 0;
                foreach ($state['refunds'] ?? [] as $refund) if ($refund['status'] === 'succeeded') $cancelled += $refund['amount'];
                return ['status' => 'PAID', 'valid' => true, 'cancelled' => $cancelled,
                    'transaction_id' => 'tx-' . $order['id'], 'paid_at' => time()];
            }
            protected function refund(array $config, array $order, array $state, int $amount, int $remaining, string $reason, string $key): array
            {
                $this->calls[] = ['refund', $order['id']];
                return ['id' => 'refund-' . $key, 'amount' => $amount, 'at' => time()];
            }
        };
    }
}
