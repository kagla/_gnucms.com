<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

/** 기존 KCP pp_cli 방식의 자격정보만 등록한다. 결제 실행은 별도 연동 단계다. */
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

    public function methods(): array { return []; }
    public function supportsPartialRefund(): bool { return false; }
    public function checkoutTemplate(): string
    {
        throw DomainError::serviceUnavailable('KCP 기존 방식의 결제창은 아직 연결되지 않았습니다.');
    }
    public function gateway(Settings $settings): Gateway
    {
        throw DomainError::serviceUnavailable('KCP 기존 방식의 결제 실행은 아직 연결되지 않았습니다.');
    }
}
