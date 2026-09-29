<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Payment\{KcpConfig, KcpLegacyConfig, NicepayProvider, ProviderConfig, TossProvider};
use PHPUnit\Framework\Attributes\DataProvider;

/** Each real adapter must accept the same member order and prepare its own card window. */
final class AllProviderCheckoutTest extends ShopTestCase
{
    #[DataProvider('connectionProvider')]
    public function testMemberCanStartAndRetryCardCheckoutWithEveryProvider(array $config): void
    {
        $this->setupShop($config);
        $this->installLegacyModuleFixture();
        $product = $this->product(['price' => '12000', 'stock' => '20']);
        $cart = $this->shop->cart->add([], ['product_id' => $product['id'], 'quantity' => 1]);
        $input = ['buyer_name' => '구매자', 'email' => 'buyer@example.test', 'phone' => '01012345678',
            'recipient' => '수령인', 'recipient_phone' => '01012345678', 'postcode' => '04524',
            'address' => '테스트 배송지', 'agree' => '1', 'payment_method' => 'card'];
        $fingerprint = $this->shop->cart->quote($cart, [], true)['fingerprint'];
        $owner = bin2hex(random_bytes(32));
        $userId = $this->memberId();
        $expected = ['inicis' => 'inicis-pro', 'kcp_legacy' => 'kcp-legacy',
            'kcp' => 'kcp-web', 'toss' => 'toss', 'nicepay' => 'nicepay'];
        $ids = [];

        foreach ($expected as $provider => $kind) {
            $this->app->paymentSettings($provider)->save('test', match ($provider) {
                'inicis' => ProviderConfig::testCredentials(),
                'kcp_legacy' => KcpLegacyConfig::testCredentials(),
                'kcp' => KcpConfig::testCredentials(),
                'toss' => TossProvider::testCredentials(),
                'nicepay' => NicepayProvider::testCredentials(),
            });
            $settings = $this->shop->settings->all();
            $settings['payment']['provider'] = $provider;
            $settings['payment']['environment'] = 'test';
            $settings['payment']['methods'] = ['card' => true];
            $this->app->db()->execute('INSERT INTO ' . $this->app->db()->table('yc_settings')
                . ' (id, payload) VALUES (?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload)',
                ['settings', json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            self::assertSame(['card' => '신용카드'], $this->shop->payments->methods(), $provider);

            $token = bin2hex(random_bytes(32));
            $payment = $this->shop->payments->forPlacing($input);
            $intent = $this->shop->checkoutIntents->stage($cart, $input, $token, $owner, $userId,
                $fingerprint, [], $payment, 'buy');
            $window = $this->shop->checkoutIntents->checkout($intent, 'web',
                'https://shop.example.test/shop/checkout',
                'https://shop.example.test/shop/pay/callback?provider=' . $provider);
            self::assertSame($kind, $window['kind'], $provider);
            self::assertSame('ready', $this->app->paymentGateway($provider)->approvalState(
                \GnuCms\Shop\Commerce\CheckoutIntents::gatewayOrder($intent)), $provider);
            self::assertSame($payment['id'], match ($provider) {
                'inicis' => $window['fields']['P_OID'],
                'kcp_legacy', 'kcp' => $window['fields']['ordr_idxx'],
                'toss', 'nicepay' => $window['fields']['orderId'],
            }, $provider);
            self::assertSame(12000, (int) match ($provider) {
                'inicis' => $window['fields']['P_AMT'],
                'kcp_legacy', 'kcp' => $window['fields']['good_mny'],
                'toss', 'nicepay' => $window['fields']['amount'],
            }, $provider);
            $ids[] = $payment['id'];

            // Cardholder closes or declines authentication; a retry must use a fresh payment ID.
            $this->shop->checkoutIntents->decline($intent, 'TEST_CANCEL');
            $retry = $this->shop->checkoutIntents->stage($cart, $input, $token, $owner, $userId,
                $fingerprint, [], $this->shop->payments->forPlacing($input), 'buy', $payment['id']);
            self::assertNotSame($payment['id'], $retry['payment']['id'], $provider);
            self::assertSame($provider, $retry['payment']['provider']);
        }

        self::assertCount(5, array_unique($ids));
        self::assertSame(0, (int) $this->app->db()->selectOne('SELECT COUNT(*) AS n FROM '
            . $this->app->db()->table('yc_orders'))['n'], 'An unpaid card window must not create an order.');
    }

    private function installLegacyModuleFixture(): void
    {
        $dir = $this->root . '/payment/kcp_legacy/bin';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/pub.key', 'test fixture');
        file_put_contents($dir . '/pp_cli_x64', "#!/bin/sh\nexit 1\n");
        chmod($dir . '/pp_cli_x64', 0700);
    }
}
