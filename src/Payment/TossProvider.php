<?php

declare(strict_types=1);

namespace GnuCms\Payment;

final class TossProvider extends KeyedProvider
{
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
}
