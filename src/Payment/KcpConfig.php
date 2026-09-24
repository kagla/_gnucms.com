<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

final class KcpConfig
{
    public static function fields(): array
    {
        return [
            'site_cd' => ['label' => '사이트 코드 (site_cd)', 'secret' => false, 'multiline' => false],
            'certificate' => ['label' => '서비스 인증서 PEM', 'secret' => true, 'multiline' => true],
            'private_key' => ['label' => '개인키 PEM', 'secret' => true, 'multiline' => true],
            'private_key_password' => ['label' => '개인키 비밀번호', 'secret' => true, 'multiline' => false],
        ];
    }

    public static function validate(array $input, array $before, string $environment): array
    {
        Settings::environment($environment);
        $data = [];
        foreach (self::fields() as $key => $field) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || strlen($value) > 32768 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
                throw DomainError::validation([$key => 'KCP 연동 값을 확인해 주세요.']);
            }
            $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
            if ($value === '' && $field['secret'] && ($data['site_cd'] ?? '') === ($before['site_cd'] ?? null)) {
                $value = (string) ($before[$key] ?? '');
            }
            if ($value === '') throw DomainError::validation([$key => $field['label'] . '을 입력해 주세요.']);
            if (!$field['multiline'] && preg_match('/\s/', $value)) throw DomainError::validation([$key => '공백 없이 입력해 주세요.']);
            $data[$key] = $value;
        }
        if (!preg_match('/^[A-Z0-9]{5}$/D', $data['site_cd'])) {
            throw DomainError::validation(['site_cd' => 'KCP에서 발급한 5자리 사이트 코드를 확인해 주세요.']);
        }
        if (($environment === 'test') !== ($data['site_cd'] === 'T0000')) {
            throw DomainError::validation(['site_cd' => '테스트 환경에는 T0000, 운영 환경에는 운영 사이트 코드를 입력해 주세요.']);
        }
        if (!str_contains($data['certificate'], '-----BEGIN CERTIFICATE-----') || !str_contains($data['certificate'], '-----END CERTIFICATE-----')) {
            throw DomainError::validation(['certificate' => 'KCP 서비스 인증서 PEM 형식을 확인해 주세요.']);
        }
        if (!str_contains($data['private_key'], '-----BEGIN') || !str_contains($data['private_key'], 'PRIVATE KEY-----')) {
            throw DomainError::validation(['private_key' => 'KCP 개인키 PEM 형식을 확인해 주세요.']);
        }
        $key = @openssl_pkey_get_private($data['private_key'], $data['private_key_password']);
        if ($key === false) throw DomainError::validation(['private_key' => '개인키와 비밀번호를 확인해 주세요.']);
        return $data;
    }
}
