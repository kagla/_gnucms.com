<?php

declare(strict_types=1);

namespace GnuCms\Payment;

final class InicisProvider implements Provider
{
    public function id(): string { return 'inicis'; }
    public function label(): string { return 'KG이니시스'; }
    public function fields(): array { return ProviderConfig::fields($this->id()); }
    public function manual(): string { return ProviderConfig::manual($this->id()); }
    public function validate(array $input, array $before, string $environment): array
    {
        return ProviderConfig::validate($this->id(), $input, $before, $environment);
    }
    public function credentials(array $revision, ?array $current): array
    {
        if ($current !== null && $current['merchant_id'] === $revision['merchant_id']) {
            foreach ($this->fields() as $key => $field) {
                if (($field['secret'] && ($current[$key] ?? '') !== '') || $key === 'client_ip') $revision[$key] = $current[$key];
            }
        }
        return $revision;
    }
    public function methods(): array { return ['card']; }
    public function supportsPartialRefund(): bool { return true; }
    public function checkoutTemplate(): string { return 'payment/inicis'; }
    public function gateway(Settings $settings): Gateway { return new InicisGateway($settings); }
}
