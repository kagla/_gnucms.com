<?php

declare(strict_types=1);

$provider = $argv[1] ?? '';
$orderId = str_repeat('a', 32);
$callback = 'https://shop.example.test/shop/pay/callback?order=' . $orderId;
$payment = match ($provider) {
    'toss' => ['kind' => 'toss', 'script' => 'https://js.tosspayments.com/v1/payment',
        'client_key' => 'test_ck_fixture', 'method' => '카드',
        'fields' => ['orderId' => $orderId, 'amount' => 12000, 'successUrl' => $callback]],
    'nicepay' => ['kind' => 'nicepay', 'script' => 'https://pay.nicepay.co.kr/v1/js/',
        'fields' => ['orderId' => $orderId, 'amount' => 12000, 'clientId' => 'fixture']],
    'kcp' => ['kind' => 'kcp-web', 'script' => 'https://testspay.kcp.co.kr/plugin/kcp_spay_hub.js',
        'fields' => ['site_cd' => 'T0000', 'ordr_idxx' => $orderId,
            'good_mny' => '12000', 'Ret_URL' => $callback]],
    'kcp_legacy' => ['kind' => 'kcp-legacy', 'script' => 'https://testpay.kcp.co.kr/plugin/payplus_web.jsp',
        'action' => $callback, 'fields' => ['site_cd' => 'T0000', 'ordr_idxx' => $orderId,
            'good_mny' => '12000', 'res_cd' => '', 'enc_data' => '', 'enc_info' => '']],
    default => throw new InvalidArgumentException('Unknown payment provider'),
};

$view = new class {
    public function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public function asset(string $path): string { return '/themes/default/' . $path; }
    public function render(string $provider, array $payment): string
    {
        ob_start();
        include dirname(__DIR__, 2) . '/templates/default/payment/' . $provider . '.php';
        include dirname(__DIR__, 2) . '/templates/default/payment/' . $provider . '_scripts.php';
        return (string) ob_get_clean();
    }
};

echo '<!doctype html><html><body>' . $view->render($provider, $payment) . '</body></html>';
