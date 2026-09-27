<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

final class KcpConfig
{
    /** KCP 개발자센터에서 공개한 T0000 테스트 인증서와 암호화된 개인키. */
    public static function testCredentials(): array
    {
        $directory = dirname(__DIR__, 2) . '/config/payment/kcp/test';
        if (!is_file($directory . '/settings.php')) {
            throw DomainError::serviceUnavailable('KCP 테스트 설정 파일을 확인해 주세요.');
        }
        $settings = require $directory . '/settings.php';
        if (!is_array($settings) || ($settings['site_cd'] ?? '') !== KcpLegacyConfig::TEST_SITE_CD
            || !is_string($settings['private_key_password'] ?? null) || $settings['private_key_password'] === '') {
            throw DomainError::serviceUnavailable('KCP 테스트 설정 값을 확인해 주세요.');
        }
        $certificate = @file_get_contents($directory . '/splCert.pem');
        $privateKey = @file_get_contents($directory . '/splPrikeyPKCS8.pem');
        $key = is_string($privateKey) ? @openssl_pkey_get_private($privateKey, $settings['private_key_password']) : false;
        if (!is_string($certificate) || !is_string($privateKey)
            || !str_contains($certificate, '-----BEGIN CERTIFICATE-----')
            || $key === false || !@openssl_x509_check_private_key($certificate, $key)) {
            throw DomainError::serviceUnavailable('KCP 테스트 인증서와 개인키 파일을 확인해 주세요.');
        }
        return ['mode' => 'general', 'site_cd' => $settings['site_cd'],
            'certificate' => trim($certificate), 'private_key' => trim($privateKey),
            'private_key_password' => $settings['private_key_password']];
    }

    public static function fields(): array
    {
        return [
            'mode' => ['label' => '결제 방식', 'secret' => false, 'multiline' => false],
            'site_cd' => ['label' => '사이트 코드 (site_cd)', 'secret' => false, 'multiline' => false],
            'certificate' => ['label' => '서비스 인증서 PEM', 'secret' => true, 'multiline' => true],
            'private_key' => ['label' => '개인키 PEM', 'secret' => true, 'multiline' => true],
            'private_key_password' => ['label' => '개인키 비밀번호 (발급 시 설정)', 'secret' => true, 'multiline' => false],
        ];
    }

    public static function validate(array $input, array $before, string $environment): array
    {
        Settings::environment($environment);
        if ($environment === 'test') return self::testCredentials();
        $data = ['mode' => 'general'];
        foreach (self::fields() as $key => $field) {
            if ($key === 'mode') continue;
            $value = $input[$key] ?? '';
            if (!is_string($value) || strlen($value) > 32768 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
                throw DomainError::validation([$key => 'KCP 연동 값을 확인해 주세요.']);
            }
            $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
            if ($value === '' && $field['secret'] && ($input['site_cd'] ?? null) === ($before['site_cd'] ?? null)) {
                $value = (string) ($before[$key] ?? '');
            }
            if ($value === '') throw DomainError::validation([$key => $field['label'] . '을 입력해 주세요.']);
            if (!$field['multiline'] && preg_match('/\s/', $value)) throw DomainError::validation([$key => '공백 없이 입력해 주세요.']);
            $data[$key] = $value;
        }
        if (!preg_match('/^[A-Z0-9]{5}$/D', $data['site_cd'])) {
            throw DomainError::validation(['site_cd' => 'KCP에서 발급한 5자리 사이트 코드를 확인해 주세요.']);
        }
        if (in_array($data['site_cd'], [KcpLegacyConfig::TEST_SITE_CD, KcpLegacyConfig::TEST_ESCROW_SITE_CD], true)) {
            throw DomainError::validation(['site_cd' => '테스트 사이트 코드는 T0000이며, 운영 사이트 코드는 KCP에서 받은 값을 입력해 주세요.']);
        }
        if (!str_contains($data['certificate'], '-----BEGIN CERTIFICATE-----') || !str_contains($data['certificate'], '-----END CERTIFICATE-----')) {
            throw DomainError::validation(['certificate' => 'KCP 서비스 인증서 PEM 형식을 확인해 주세요.']);
        }
        if (!str_contains($data['private_key'], '-----BEGIN') || !str_contains($data['private_key'], 'PRIVATE KEY-----')) {
            throw DomainError::validation(['private_key' => 'KCP 개인키 PEM 형식을 확인해 주세요.']);
        }
        $key = @openssl_pkey_get_private($data['private_key'], $data['private_key_password']);
        if ($key === false) throw DomainError::validation(['private_key' => '개인키와 비밀번호를 확인해 주세요.']);
        if (!@openssl_x509_check_private_key($data['certificate'], $key)) {
            throw DomainError::validation(['certificate' => '서비스 인증서와 개인키가 서로 일치하는지 확인해 주세요.']);
        }
        return $data;
    }
}
