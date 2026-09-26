<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

/** 이니시스 상점 설정 항목과 검증. 리셀러 코드는 가맹점 등록용이며 결제 설정·전문에 넣지 않는다. */
final class ProviderConfig
{
    public const TEST_MID = 'INIpayTest';

    /** KG이니시스가 공개한 공용 테스트 상점 값. 운영 인증 정보로 사용하지 않는다. */
    public static function testCredentials(string $mode = 'general'): array
    {
        return ['mode' => PaymentMode::validate($mode), 'merchant_id' => self::TEST_MID,
            'sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'hash_key' => '3CB8183A4BE283555ACC8363C0360223',
            'api_key' => 'ItEQKi3rY7uvDS8l',
            'client_ip' => '127.0.0.1'];
    }

    public static function fields(string $provider): array
    {
        if ($provider !== 'inicis') throw DomainError::internal('결제사를 확인해 주세요.');
        $names = ['merchant_id' => '상점 아이디 (MID)', 'sign_key' => '기존 웹표준 결제 SignKey (기존 거래가 있을 때만)', 'hash_key' => 'PayPro 금액 위변조 Hash Key',
            'api_key' => 'INIAPI Key', 'client_ip' => '기본 요청 서버 IPv4 주소'];
        $fields = ['mode' => ['label' => '결제 방식', 'secret' => false, 'multiline' => false]];
        foreach ($names as $key => $label) {
            $fields[$key] = ['label' => $label, 'secret' => !in_array($key, ['merchant_id', 'client_ip'], true), 'multiline' => false];
        }
        return $fields;
    }

    public static function manual(string $provider): string
    {
        self::fields($provider);
        return 'https://manual.inicis.com/inipaypro/';
    }

    public static function validate(string $provider, array $input, array $before, string $environment = 'test'): array
    {
        Settings::environment($environment);
        $data = ['mode' => PaymentMode::validate($input['mode'] ?? ($before['mode'] ?? 'general'))];
        foreach (self::fields($provider) as $key => $field) {
            if ($key === 'mode') continue;
            $value = $input[$key] ?? '';
            if (!is_string($value) || strlen($value) > 16384 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
                throw DomainError::validation([$key => '결제 연동 값을 확인해 주세요.']);
            }
            $value = trim($value);
            if ($value === '' && $field['secret'] && $data['mode'] === ($before['mode'] ?? 'general')
                && ($data['merchant_id'] ?? '') === ($before['merchant_id'] ?? null)) $value = $before[$key] ?? '';
            if ($value === '' && $key === 'sign_key') { $data[$key] = ''; continue; }
            if ($value === '') throw DomainError::validation([$key => $field['label'] . '을 입력해 주세요.']);
            if (preg_match('/[\r\n]/', $value)) throw DomainError::validation([$key => '한 줄로 입력해 주세요.']);
            $data[$key] = $value;
        }
        if (!preg_match('/^[A-Za-z0-9]{10}$/D', $data['merchant_id'])) throw DomainError::validation(['merchant_id' => 'PG에서 발급한 상점 코드를 확인해 주세요.']);
        if (!filter_var($data['client_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw DomainError::validation(['client_ip' => '서버의 IPv4 주소를 입력해 주세요.']);
        foreach (['hash_key', 'api_key'] as $key) {
            if (strlen($data[$key]) < 16 || preg_match('/\s/', $data[$key])) throw DomainError::validation([$key => '발급받은 인증키를 확인해 주세요.']);
        }
        if ($data['sign_key'] !== '' && (strlen($data['sign_key']) < 16 || preg_match('/\s/', $data['sign_key']))) {
            throw DomainError::validation(['sign_key' => '발급받은 인증키를 확인해 주세요.']);
        }
        return $data;
    }
}
