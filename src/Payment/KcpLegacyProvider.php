<?php

declare(strict_types=1);

namespace GnuCms\Payment;

/** NHN KCP 구형 표준결제 브라우저 인증과 pp_cli TCP/IP 서버 승인을 연결한다. */
final class KcpLegacyProvider implements Provider
{
    public function id(): string { return 'kcp_legacy'; }
    public function label(): string { return 'NHN KCP 기존 방식 (TCP/IP)'; }
    public function fields(): array { return KcpLegacyConfig::fields(); }
    public function manual(): string { return 'https://developer.kcp.co.kr/guide/rest-api-guide'; }

    public function validate(array $input, array $before, string $environment): array
    {
        return KcpLegacyConfig::validate($input, $before, $environment);
    }

    public function credentials(array $revision, ?array $current): array
    {
        if ($current !== null && ($current['mode'] ?? 'general') === ($revision['mode'] ?? 'general')
            && ($current['site_cd'] ?? '') === ($revision['site_cd'] ?? '') && ($current['site_key'] ?? '') !== '') {
            $revision['site_key'] = $current['site_key'];
        }
        return $revision;
    }

    public function methods(): array { return ['card']; }
    public function supportsPartialRefund(): bool { return false; }
    public function checkoutTemplate(): string { return 'payment/kcp_legacy'; }
    public function gateway(Settings $settings): Gateway { return new KcpLegacyGateway($settings); }
}
