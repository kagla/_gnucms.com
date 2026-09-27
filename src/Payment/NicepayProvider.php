<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

final class NicepayProvider extends KeyedProvider
{
    /** 나이스페이먼츠 공식 서버 승인·Basic 인증 샌드박스 샘플의 공개 테스트 키. */
    public static function testCredentials(): array
    {
        return ['mode' => 'general',
            'client_key' => 'S2_af4543a0be4d49a98122e01ec2059a56',
            'secret_key' => '9eb85607103646da9f9c02b128f2e5ee'];
    }

    public function id(): string { return 'nicepay'; }
    public function label(): string { return '나이스페이먼츠'; }
    public function manual(): string { return 'https://github.com/nicepayments/nicepay-manual/blob/main/api/payment-window-server.md'; }
    public function fields(): array
    {
        $fields = parent::fields();
        $fields['client_key']['label'] = '서버 승인용 클라이언트 키';
        $fields['secret_key']['label'] = 'Basic 인증 시크릿 키';
        return $fields;
    }
    public function validate(array $input, array $before, string $environment): array
    {
        Settings::environment($environment);
        if ($environment === 'test') return self::testCredentials();
        $data = parent::validate($input, $before, $environment);
        if (strlen($data['client_key']) > 50) {
            throw DomainError::validation(['client_key' => '나이스페이 클라이언트 키는 50자 이하여야 합니다.']);
        }
        return $data;
    }
    public function checkoutTemplate(): string { return 'payment/nicepay'; }
    public function gateway(Settings $settings): Gateway { return new NicepayGateway($settings); }
}
