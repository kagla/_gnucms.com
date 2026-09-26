<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

/** KCP pp_cli(TCP/IP) 방식에서 발급받는 상점 코드와 사이트 키. */
final class KcpLegacyConfig
{
    public const TEST_SITE_CD = 'T0000';
    private const TEST_SITE_KEY = '3grptw1.zW0GSo4PQdaGvsF__';
    public const TEST_ESCROW_SITE_CD = 'T0007';
    private const TEST_ESCROW_SITE_KEY = '4Ho4YsuOZlLXUZUdOxM1Q7X__';

    public static function testCredentials(string $mode = 'general'): array
    {
        return PaymentMode::validate($mode) === 'escrow'
            ? ['mode' => 'escrow', 'site_cd' => self::TEST_ESCROW_SITE_CD, 'site_key' => self::TEST_ESCROW_SITE_KEY]
            : ['mode' => 'general', 'site_cd' => self::TEST_SITE_CD, 'site_key' => self::TEST_SITE_KEY];
    }

    public static function fields(): array
    {
        return [
            'mode' => ['label' => '결제 방식', 'secret' => false, 'multiline' => false],
            'site_cd' => ['label' => '사이트 코드 (site_cd)', 'secret' => false, 'multiline' => false],
            'site_key' => ['label' => '사이트 키 (site_key)', 'secret' => true, 'multiline' => false],
        ];
    }

    public static function validate(array $input, array $before, string $environment): array
    {
        Settings::environment($environment);
        $mode = PaymentMode::validate($input['mode'] ?? ($before['mode'] ?? 'general'));
        if ($environment === 'test') return self::testCredentials($mode);
        $siteCode = $input['site_cd'] ?? '';
        $siteKey = $input['site_key'] ?? '';
        if (!is_string($siteCode) || !preg_match('/^[A-Z0-9]{5}$/D', $siteCode)) {
            throw DomainError::validation(['site_cd' => 'KCP에서 발급한 5자리 사이트 코드를 확인해 주세요.']);
        }
        if (in_array($siteCode, [self::TEST_SITE_CD, self::TEST_ESCROW_SITE_CD], true)) {
            throw DomainError::validation(['site_cd' => '운영 환경에는 운영 사이트 코드를 입력해 주세요.']);
        }
        if (!is_string($siteKey) || strlen($siteKey) > 256 || preg_match('/[\x00-\x20\x7f]/', $siteKey)) {
            throw DomainError::validation(['site_key' => 'KCP 사이트 키를 공백 없이 입력해 주세요.']);
        }
        if ($siteKey === '' && $mode === ($before['mode'] ?? 'general') && $siteCode === ($before['site_cd'] ?? null)) $siteKey = (string) ($before['site_key'] ?? '');
        if ($siteKey === '') throw DomainError::validation(['site_key' => 'KCP에서 발급한 사이트 키를 입력해 주세요.']);
        return ['mode' => $mode, 'site_cd' => $siteCode, 'site_key' => $siteKey];
    }
}
