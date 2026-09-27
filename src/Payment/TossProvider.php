<?php

declare(strict_types=1);

namespace GnuCms\Payment;

final class TossProvider extends KeyedProvider
{
    /** 토스페이먼츠 공식 SDK v1 PHP 결제창 샘플의 공개 테스트 키 한 쌍. */
    public static function testCredentials(): array
    {
        return ['mode' => 'general',
            'client_key' => 'test_ck_D5GePWvyJnrK0W0k6q8gLzN97Eoq',
            'secret_key' => 'test_sk_zXLkKEypNArWmo50nX3lmeaxYG5R'];
    }

    public function id(): string { return 'toss'; }
    public function label(): string { return '토스페이먼츠'; }
    public function manual(): string { return 'https://docs.tosspayments.com/sdk/payment-js'; }
    public function fields(): array
    {
        $fields = parent::fields();
        $fields['client_key']['label'] = 'API 개별 연동 클라이언트 키';
        $fields['secret_key']['label'] = 'API 개별 연동 시크릿 키';
        return $fields;
    }
    public function checkoutTemplate(): string { return 'payment/toss'; }
    public function gateway(Settings $settings): Gateway { return new TossGateway($settings); }

    public function validate(array $input, array $before, string $environment): array
    {
        Settings::environment($environment);
        return $environment === 'test' ? self::testCredentials() : parent::validate($input, $before, $environment);
    }
}
