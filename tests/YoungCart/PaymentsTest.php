<?php

declare(strict_types=1);

namespace GnuCms\Tests\YoungCart;

use GnuCms\Error\DomainError;
use GnuCms\Modules\YoungCart\Commerce\Orders;
use GnuCms\Modules\YoungCart\Commerce\Payments;
use GnuCms\Payment\InicisGateway;
use GnuCms\Support\Clock;
use GnuCms\Tests\Payment\FakeTransport;
use GnuCms\Tests\Payment\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;

final class PaymentsTest extends YoungCartTestCase
{
    private FakeTransport $http;
    private array $config;

    /** 이니시스 테스트 환경을 저장·허용하고 쇼핑몰이 그 환경을 쓰게 한다. */
    private function setupPayments(array $config, bool $manual = true): void
    {
        $this->setupShop($config);
        $this->config = Fixtures::config();
        $this->app->paymentSettings()->save('test', $this->config);
        $this->app->paymentSettings()->enable('test', true);
        $this->http = new FakeTransport();
        $this->app->setInicisGateway(new InicisGateway($this->app->paymentSettings(), $this->http));
        $this->savePayment(['environment' => 'test', 'manual' => ['enabled' => $manual, 'bank' => '국민은행', 'account' => '123-45', 'holder' => '상점'],
            'deadline_hours' => ['card' => 1, 'virtual_account' => 72, 'manual_transfer' => 72]]);
    }

    /** 설정 폼을 거치지 않고 payment 블록만 바꾼다(다른 설정은 그대로). yc_settings 에 행이 없으면(첫 저장) 만든다. */
    private function savePayment(array $payment): void
    {
        $settings = $this->shop->settings->all();
        $settings['payment'] = $payment;
        $payload = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($this->app->db()->selectOne('SELECT id FROM ' . $this->app->db()->table('yc_settings') . " WHERE id = 'settings'") === null) {
            $this->app->db()->insert('yc_settings', ['id' => 'settings', 'payload' => $payload]);
        } else {
            $this->app->db()->update('yc_settings', ['payload' => $payload], 'id = :id', ['id' => 'settings']);
        }
    }

    private function buyer(array $extra = []): array
    {
        return $extra + ['buyer_name' => '테스트 구매자', 'email' => 'buyer@example.test', 'phone' => '010-0000-0000',
            'recipient' => '받는 사람', 'recipient_phone' => '010-0000-0000', 'postcode' => '04524', 'address' => '테스트 배송지',
            'address_detail' => '', 'delivery_note' => '', 'password' => bin2hex(random_bytes(12)), 'agree' => '1'];
    }

    private function place(string $method, array $extra = []): array
    {
        $cart = $this->shop->cart->add([], ['product_id' => $this->product(['price' => '12000'])['id'], 'quantity' => 1]);
        $input = $this->buyer($extra + ['payment_method' => $method]);
        return $this->shop->orders->place($cart, $input, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), null,
            $this->shop->cart->quote($cart, [], true)['fingerprint'], [], $this->shop->payments->forPlacing($input));
    }

    private function authCallback(array $order): array
    {
        return ['resultCode' => '0000', 'mid' => $this->config['merchant_id'], 'orderNumber' => $order['payment_id'], 'idc_name' => 'stg',
            'authToken' => bin2hex(random_bytes(32)), 'authUrl' => 'https://stgstdpay.inicis.com/api/payAuth', 'netCancelUrl' => 'https://stgstdpay.inicis.com/api/netCancel'];
    }

    private function queueApproval(array $order, string $tid): void
    {
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => '0000', 'mid' => $this->config['merchant_id'], 'MOID' => $order['payment_id'],
            'TotPrice' => (string) $order['total'], 'payMethod' => 'Card', 'tid' => $tid, 'currency' => 'WON']];
        $this->queueInquiry($order, $tid);
    }

    private function queueInquiry(array $order, string $tid, string $status = 'APPROVAL'): void
    {
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => 'SUCCESS', 'transactionStatus' => $status, 'mid' => $this->config['merchant_id'],
            'oid' => $order['payment_id'], 'price' => (string) $order['total'], 'tid' => $tid, 'paymethod' => 'Card', 'approvedDate' => '20260920', 'approvedTime' => '120000',
            'cardInfo' => ['currencyCode' => 'WON'], 'partCancelTransInfo' => []]];
    }

    #[DataProvider('connectionProvider')]
    public function testMethodsFollowThePaymentSettingsAndTheManualAccount(array $config): void
    {
        $this->setupPayments($config);
        self::assertSame(['card' => '카드 결제', 'manual_transfer' => '무통장입금'], $this->shop->payments->methods());
        $this->app->paymentSettings()->enable('test', false);
        self::assertSame(['manual_transfer' => '무통장입금'], $this->shop->payments->methods());
        $this->savePayment(['environment' => 'test', 'manual' => ['enabled' => false, 'bank' => '', 'account' => '', 'holder' => ''],
            'deadline_hours' => ['card' => 1, 'virtual_account' => 72, 'manual_transfer' => 72]]);
        self::assertSame([], $this->shop->payments->methods());
        self::assertSame([], $this->shop->payments->forPlacing(['payment_method' => 'card']), '수단이 하나도 없으면 접수 전용이다');
    }

    #[DataProvider('connectionProvider')]
    public function testPlacingACardOrderRecordsTheJournalKeyEnvironmentRevisionAndDeadline(array $config): void
    {
        $this->setupPayments($config);
        $order = $this->place('card');
        self::assertSame('card', $order['payment_method']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $order['payment_id']);
        self::assertSame('test', $order['payment_environment']);
        self::assertSame($this->app->paymentSettings()->summary('test')['revision'], $order['payment_revision']);
        self::assertEqualsWithDelta(Clock::timestamp() + 3600, (int) $order['pay_by'], 5);
        self::assertSame('pending', $order['status']);
        self::assertTrue($this->shop->payments->isPgOrder($order));
        self::assertSame($order['number'], $this->shop->orders->byPaymentId($order['payment_id'])['number']);
        self::assertNull($this->shop->orders->byPaymentId(str_repeat('0', 32)));
        self::assertNull($this->shop->orders->byPaymentId('not-hex'));
    }

    #[DataProvider('connectionProvider')]
    public function testPlacingAManualTransferOrderKeepsTheAccountAndDepositor(array $config): void
    {
        $this->setupPayments($config);
        $order = $this->place('manual_transfer', ['depositor' => '홍길동']);
        self::assertSame('manual_transfer', $order['payment_method']);
        self::assertSame('', $order['payment_id']);
        self::assertSame(['depositor' => '홍길동', 'bank' => '국민은행', 'account' => '123-45', 'holder' => '상점'], $order['payment']);
        self::assertEqualsWithDelta(Clock::timestamp() + 72 * 3600, (int) $order['pay_by'], 5);
        self::assertFalse($this->shop->payments->isPgOrder($order));
    }

    #[DataProvider('connectionProvider')]
    public function testUnknownMethodIsRejected(array $config): void
    {
        $this->setupPayments($config);
        try { $this->shop->payments->forPlacing(['payment_method' => 'virtual_account']); self::fail('아직 없는 수단'); }
        catch (DomainError $e) { self::assertArrayHasKey('payment_method', $e->details()); }
    }

    #[DataProvider('connectionProvider')]
    public function testCheckoutOpensTheInicisWindowWithTheOrderTotalAndCallbackState(array $config): void
    {
        $this->setupPayments($config);
        $order = $this->place('card');
        $window = $this->shop->payments->checkout($order, 'web', 'https://shop.example.test/shop/order?number=' . $order['number'], 'https://shop.example.test/shop/pay/callback');
        self::assertSame('inicis', $window['kind']);
        self::assertSame((string) $order['total'], $window['fields']['price']);
        self::assertSame($order['payment_id'], $window['fields']['oid']);
        self::assertStringStartsWith('https://shop.example.test/shop/pay/callback?order=' . $order['payment_id'] . '&state=', $window['fields']['returnUrl']);
        self::assertSame('테스트 구매자', $window['fields']['buyername']);
        self::assertSame([], $this->http->calls);
    }

    #[DataProvider('connectionProvider')]
    public function testCompleteApprovesAndMarksTheOrderPaidOnce(array $config): void
    {
        $this->setupPayments($config);
        $order = $this->place('card');
        $this->shop->payments->checkout($order, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $tid = bin2hex(random_bytes(20));
        $this->queueApproval($order, $tid);
        $paid = $this->shop->payments->complete($order, $this->authCallback($order));
        self::assertSame('paid', $paid['status']);
        self::assertSame((int) $order['total'], (int) $paid['paid_amount']);
        self::assertSame($tid, $paid['payment']['tid']);
        self::assertSame('카드 결제', $paid['payment']['label']);
        self::assertGreaterThan(0, (int) $paid['paid_at']);
        self::assertSame('paid', end($paid['history'])['status']);
        // 콜백이 다시 와도(재전송) 두 번 승인하지 않고 두 번 기록하지 않는다. 조회만 한 번 더 한다.
        $this->queueInquiry($order, $tid);
        $again = $this->shop->payments->complete($this->shop->orders->get((int) $order['id']), $this->authCallback($order));
        self::assertSame('paid', $again['status']);
        self::assertCount(2, $again['history']);
    }

    #[DataProvider('connectionProvider')]
    public function testConfirmDepositOnlyForManualTransferAndOnlyOnce(array $config): void
    {
        $this->setupPayments($config);
        $card = $this->place('card');
        try { $this->shop->orders->confirmDeposit((int) $card['id'], 'admin'); self::fail('카드 주문은 입금 확인이 없다'); }
        catch (DomainError $e) { self::assertSame(422, $e->status()); }
        $manual = $this->place('manual_transfer');
        $paid = $this->shop->orders->confirmDeposit((int) $manual['id'], 'admin');
        self::assertSame('paid', $paid['status']);
        self::assertSame((int) $manual['total'], (int) $paid['paid_amount']);
        try { $this->shop->orders->confirmDeposit((int) $manual['id'], 'admin'); self::fail('두 번 확인할 수 없다'); }
        catch (DomainError $e) { self::assertSame(422, $e->status()); }
        // 결제 수단을 모두 끄면 접수 전용 주문이 된다. 무통장과 같은 "입금 확인"으로 진행한다(재정 3).
        $this->app->paymentSettings()->enable('test', false);
        $this->savePayment(['environment' => 'test', 'manual' => ['enabled' => false, 'bank' => '', 'account' => '', 'holder' => ''],
            'deadline_hours' => ['card' => 1, 'virtual_account' => 72, 'manual_transfer' => 72]]);
        $cart = $this->shop->cart->add([], ['product_id' => $this->product(['price' => '12000'])['id'], 'quantity' => 1]);
        $input = $this->buyer(['payment_method' => '']);
        self::assertSame([], $this->shop->payments->forPlacing($input), '접수 전용 확인');
        $receiptOnly = $this->shop->orders->place($cart, $input, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), null,
            $this->shop->cart->quote($cart, [], true)['fingerprint'], [], $this->shop->payments->forPlacing($input));
        self::assertSame('', $receiptOnly['payment_method']);
        $confirmed = $this->shop->orders->confirmDeposit((int) $receiptOnly['id'], 'admin');
        self::assertSame('paid', $confirmed['status']);
        self::assertSame((int) $receiptOnly['total'], (int) $confirmed['paid_amount']);
    }

    #[DataProvider('connectionProvider')]
    public function testCustomersCannotCancelAfterPaymentAndAdminsNeedARefundFirst(array $config): void
    {
        $this->setupPayments($config);
        $order = $this->place('manual_transfer');
        $this->shop->orders->confirmDeposit((int) $order['id'], 'admin');
        try { $this->shop->orders->transition((int) $order['id'], 'paid', 'cancelled', 'guest', [], true); self::fail('고객은 결제 뒤 취소 못 한다'); }
        catch (DomainError $e) { self::assertSame(422, $e->status()); }
        $card = $this->place('card');
        $this->shop->payments->checkout($card, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $this->queueApproval($card, bin2hex(random_bytes(20)));
        $this->shop->payments->complete($card, $this->authCallback($card));
        try { $this->shop->orders->transition((int) $card['id'], 'paid', 'cancelled', 'admin'); self::fail('결제된 카드 주문은 환불이 먼저다'); }
        catch (DomainError $e) { self::assertArrayHasKey('refund', $e->details()); }
    }
}
