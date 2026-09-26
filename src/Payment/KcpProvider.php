<?php

declare(strict_types=1);

namespace GnuCms\Payment;

final class KcpProvider implements Provider
{
    public function id(): string { return 'kcp'; }
    public function label(): string { return 'NHN KCP REST API 방식'; }
    public function fields(): array { return KcpConfig::fields(); }
    public function manual(): string { return 'https://developer.kcp.co.kr/guide/payment'; }

    public function validate(array $input, array $before, string $environment): array
    {
        return KcpConfig::validate($input, $before, $environment);
    }

    public function credentials(array $revision, ?array $current): array
    {
        if ($current !== null && ($current['mode'] ?? 'general') === ($revision['mode'] ?? 'general')
            && ($current['site_cd'] ?? '') === ($revision['site_cd'] ?? '')) {
            foreach (['certificate', 'private_key', 'private_key_password'] as $key) {
                if (($current[$key] ?? '') !== '') $revision[$key] = $current[$key];
            }
        }
        return $revision;
    }

    public function methods(): array { return ['card', 'bank_transfer', 'mobile']; }
    public function supportsPartialRefund(): bool { return true; }
    public function checkoutTemplate(): string { return 'payment/kcp'; }
    public function gateway(Settings $settings): Gateway { return new KcpGateway($settings); }
}
