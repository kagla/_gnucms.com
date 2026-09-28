<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Payment\{InicisGateway, KcpConfig, KcpGateway, KcpLegacyConfig, NicepayGateway, NicepayProvider, ProviderConfig, TossGateway, TossProvider};
use GnuCms\Tests\Payment\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real adapters, a member order, and recorded PG replies; no live card data or network calls. */
final class DirectProviderFlowTest extends ShopTestCase
{
    public static function providers(): array
    {
        $config = self::mysqlConfig();
        return array_combine(['inicis', 'kcp_legacy', 'kcp', 'toss', 'nicepay'],
            array_map(static fn (string $id): array => [$config, $id], ['inicis', 'kcp_legacy', 'kcp', 'toss', 'nicepay']));
    }

    #[DataProvider('providers')]
    public function testCardApprovalThenCustomerCancellationRestoresStock(array $config, string $provider): void
    {
        $this->setupShop($config);
        if ($provider === 'kcp_legacy') $this->installLegacyModuleFixture();
        $credentials = match ($provider) {
            'inicis' => ProviderConfig::testCredentials(),
            'kcp_legacy' => KcpLegacyConfig::testCredentials(),
            'kcp' => KcpConfig::testCredentials(),
            'toss' => TossProvider::testCredentials(),
            'nicepay' => NicepayProvider::testCredentials(),
        };
        $settings = $this->app->paymentSettings($provider);
        $settings->save('test', $credentials);
        $http = new FakeTransport();
        if ($provider !== 'kcp_legacy') {
            $gateway = match ($provider) {
                'inicis' => new InicisGateway($settings, $http),
                'kcp' => new KcpGateway($settings, $http),
                'toss' => new TossGateway($settings, $http),
                'nicepay' => new NicepayGateway($settings, $http),
            };
            $this->app->setPaymentGateway($gateway);
        }
        $shopSettings = $this->shop->settings->all();
        $shopSettings['payment']['provider'] = $provider;
        $shopSettings['payment']['environment'] = 'test';
        $this->app->db()->insert('yc_settings', ['id' => 'settings',
            'payload' => json_encode($shopSettings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

        $product = $this->product(['price' => '12000', 'stock' => '3']);
        $cart = $this->shop->cart->add([], ['product_id' => $product['id'], 'quantity' => 1]);
        $input = ['buyer_name' => '구매자', 'email' => 'buyer@example.test', 'phone' => '01012345678',
            'recipient' => '수령인', 'recipient_phone' => '01012345678', 'postcode' => '04524',
            'address' => '테스트 배송지', 'agree' => '1', 'payment_method' => 'card'];
        $payment = $this->shop->payments->forPlacing($input);
        $intent = $this->shop->checkoutIntents->stage($cart, $input, bin2hex(random_bytes(32)),
            bin2hex(random_bytes(32)), $this->memberId(), $this->shop->cart->quote($cart, [], true)['fingerprint'],
            [], $payment, 'buy');
        $this->shop->checkoutIntents->checkout($intent, 'web', 'https://shop.example.test/shop/checkout',
            'https://shop.example.test/shop/pay/callback?provider=' . $provider);
        self::assertSame(0, (int) $this->app->db()->selectOne('SELECT COUNT(*) AS n FROM '
            . $this->app->db()->table('yc_orders'))['n']);

        $id = $payment['id'];
        $approvedAt = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Seoul'));
        $tid = match ($provider) {
            'kcp', 'kcp_legacy' => '12345678901234',
            'nicepay' => 'N' . substr($id, 0, 20),
            default => 'test_' . $id,
        };
        $callback = $this->queueApproval($http, $provider, $id, $tid, $credentials, $approvedAt);
        $paid = $this->shop->checkoutIntents->complete($intent, $callback);
        self::assertSame('paid', $paid['status'], $provider);
        self::assertSame(12000, (int) $paid['paid_amount'], $provider);
        self::assertSame($provider, $paid['payment_provider']);
        self::assertSame(2, (int) $this->shop->products->get((int) $product['id'])['stock']);

        $this->queueCancellation($http, $provider, $id, $tid, $credentials, $approvedAt->modify('+1 minute'));
        $cancelled = $this->shop->orders->cancelForCustomer((int) $paid['id'], $this->memberId(),
            ['cancel_reason' => 'change_mind'], $this->shop->payments);
        self::assertSame('cancelled', $cancelled['status'], $provider);
        self::assertSame(12000, (int) $cancelled['refunded_amount'], $provider);
        self::assertSame(3, (int) $this->shop->products->get((int) $product['id'])['stock']);
    }

    private function queueApproval(FakeTransport $http, string $provider, string $id, string $tid, array $credentials,
        \DateTimeImmutable $at): array
    {
        switch ($provider) {
            case 'inicis':
                $http->responses[] = ['status' => 200, 'body' => ['P_STATUS' => '00', 'P_MID' => 'INIpayTest',
                    'P_OID' => $id, 'P_AMT' => '12000', 'P_TYPE' => 'CARD', 'P_APPL_TID' => $tid]];
                $http->responses[] = ['status' => 200, 'body' => ['resultCode' => 'SUCCESS', 'transactionStatus' => 'APPROVAL',
                    'mid' => 'INIpayTest', 'oid' => $id, 'price' => '12000', 'tid' => $tid, 'paymethod' => 'Card',
                    'approvedDate' => $at->format('Ymd'), 'approvedTime' => $at->format('His'), 'cardInfo' => ['currencyCode' => 'WON'],
                    'partCancelTransInfo' => []]];
                return ['P_STATUS' => '00', 'P_MID' => 'INIpayTest', 'P_OID' => $id, 'P_AMT' => '12000',
                    'P_AUTH_TID' => 'auth_' . $id, 'P_IDCNAME' => 'stg'];
            case 'kcp':
                $http->responses[] = ['status' => 200, 'body' => ['res_cd' => '0000', 'order_no' => $id,
                    'amount' => '12000', 'pay_method' => 'PACA', 'tno' => $tid]];
                $http->responses[] = ['status' => 200, 'body' => ['res_cd' => '0000', 'tno' => $tid,
                    'order_no' => $id, 'pay_method' => 'PACA', 'amount' => '12000', 'rem_mny' => '12000',
                    'stat_ca_cd' => 'STSR', 'app_time' => $at->format('YmdHis')]];
                return ['res_cd' => '0000', 'site_cd' => 'T0000', 'ordr_idxx' => $id,
                    'tran_cd' => '00100000', 'enc_data' => 'encrypted_test', 'enc_info' => 'encrypted_info'];
            case 'kcp_legacy':
                return ['res_cd' => '0000', 'site_cd' => 'T0000', 'ordr_idxx' => $id,
                    'tran_cd' => '00100000', 'good_mny' => '12000', 'enc_data' => 'encrypted_test',
                    'enc_info' => 'encrypted_info'];
            case 'toss':
                $body = ['paymentKey' => $tid, 'orderId' => $id, 'totalAmount' => 12000,
                    'balanceAmount' => 12000, 'status' => 'DONE', 'method' => '카드', 'currency' => 'KRW',
                    'approvedAt' => $at->format('c'), 'card' => ['issuerCode' => '41', 'number' => '************1234'],
                    'cancels' => []];
                $http->responses[] = ['status' => 200, 'body' => $body];
                $http->responses[] = ['status' => 200, 'body' => $body];
                return ['paymentKey' => $tid, 'orderId' => $id, 'amount' => '12000'];
            case 'nicepay':
                $ediDate = $at->format('YmdHis');
                $signed = ['tid' => $tid, 'orderId' => $id, 'amount' => 12000, 'ediDate' => $ediDate,
                    'signature' => hash('sha256', $tid . '12000' . $ediDate . $credentials['secret_key']),
                    'resultCode' => '0000'];
                $http->responses[] = ['status' => 200, 'body' => $signed];
                $http->responses[] = ['status' => 200, 'body' => $signed + ['status' => 'paid', 'payMethod' => 'card',
                    'currency' => 'KRW', 'balanceAmt' => 12000, 'paidAt' => $at->format('c'),
                    'card' => ['cardName' => '테스트 카드', 'cardNum' => '************1234'], 'cancels' => []]];
                $token = 'auth_' . $id;
                return ['authResultCode' => '0000', 'clientId' => $credentials['client_key'],
                    'orderId' => $id, 'amount' => '12000', 'authToken' => $token, 'tid' => $tid,
                    'signature' => hash('sha256', $token . $credentials['client_key'] . '12000' . $credentials['secret_key'])];
        }
        throw new \LogicException('Unknown provider');
    }

    private function queueCancellation(FakeTransport $http, string $provider, string $id, string $tid, array $credentials,
        \DateTimeImmutable $at): void
    {
        switch ($provider) {
            case 'inicis':
                $http->responses[] = ['status' => 200, 'body' => ['resultCode' => '00',
                    'cancelDate' => $at->format('Ymd'), 'cancelTime' => $at->format('His')]];
                break;
            case 'kcp':
                $http->responses[] = ['status' => 200, 'body' => ['res_cd' => '0000', 'tno' => $tid,
                    'canc_time' => $at->format('YmdHis')]];
                break;
            case 'toss':
                $http->responses[] = ['status' => 200, 'body' => ['paymentKey' => $tid, 'orderId' => $id,
                    'totalAmount' => 12000, 'balanceAmount' => 0, 'status' => 'CANCELED', 'method' => '카드',
                    'currency' => 'KRW', 'lastTransactionKey' => 'cancel_' . $id,
                    'cancels' => [['transactionKey' => 'cancel_' . $id, 'cancelAmount' => 12000,
                        'cancelStatus' => 'DONE', 'canceledAt' => $at->format('c')]]]];
                break;
            case 'nicepay':
                $ediDate = $at->format('YmdHis');
                $http->responses[] = ['status' => 200, 'body' => ['resultCode' => '0000',
                    'tid' => $tid, 'orderId' => $id, 'amount' => 12000, 'balanceAmt' => 0,
                    'ediDate' => $ediDate, 'signature' => hash('sha256', $tid . '12000' . $ediDate . $credentials['secret_key']),
                    'cancels' => [['tid' => 'C' . substr($id, 0, 20), 'amount' => 12000,
                        'cancelledAt' => $at->format('c')]]]];
                break;
        }
    }

    private function installLegacyModuleFixture(): void
    {
        $dir = $this->root . '/payment/kcp_legacy/bin';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/pub.key', 'test fixture');
        file_put_contents($dir . '/pp_cli_x64', <<<'SH'
#!/bin/sh
case "$2" in
  *tx_cd=00100000*) echo 'res_cd=0000&tno=12345678901234' ;;
  *tx_cd=00200000*) echo 'res_cd=0000&tno=12345678901234' ;;
  *) exit 1 ;;
esac
SH
        );
        chmod($dir . '/pp_cli_x64', 0700);
    }
}
