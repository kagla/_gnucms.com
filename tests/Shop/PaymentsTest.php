<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use GnuCms\Payment\InicisGateway;
use GnuCms\Shop\Commerce\Orders;
use GnuCms\Shop\Commerce\Payments;
use GnuCms\Support\Clock;
use GnuCms\Tests\Payment\FakeTransport;
use GnuCms\Tests\Payment\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;

final class PaymentsTest extends ShopTestCase
{
    private FakeTransport $http;
    private array $config;

    /** 이니시스 테스트 환경을 저장·허용하고 쇼핑몰이 그 환경을 쓰게 한다. */
    private function setupPayments(array $config, bool $manual = true): void
    {
        $this->setupShop($config);
        $this->config = Fixtures::config();
        $this->app->paymentSettings()->save('test', $this->config);
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
        return $this->shop->orders->place($cart, $input, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), $this->memberId(),
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

    /**
     * 부분 취소가 잡혀 있는 조회 응답. $rows 는 [취소 거래번호, 금액] 목록이고 합이 취소 누계다.
     * @param list<array{0:string,1:int}> $rows
     */
    private function queuePartialCancelInquiry(array $order, string $tid, array $rows): void
    {
        $cancelled = array_sum(array_column($rows, 1));
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => 'SUCCESS', 'transactionStatus' => 'PART_CANCEL', 'mid' => $this->config['merchant_id'],
            'oid' => $order['payment_id'], 'price' => (string) $order['total'], 'tid' => $tid, 'paymethod' => 'Card', 'approvedDate' => '20260920', 'approvedTime' => '120000',
            'cardInfo' => ['currencyCode' => 'WON'], 'availablePartCancelPrice' => (string) ((int) $order['total'] - $cancelled),
            'partCancelTransInfo' => array_map(static fn (array $row): array => ['tid' => $row[0], 'requestPrice' => (string) $row[1],
                'requestDate' => '20260921', 'requestTime' => '090000'], $rows)]];
    }

    #[DataProvider('connectionProvider')]
    public function testDeclinedAuthenticationKeepsApprovalReadyAndUsesANewPaymentIdOnRetry(array $config): void
    {
        $this->setupPayments($config);
        $product = $this->product(['price' => '150']);
        $cart = $this->shop->cart->add([], ['product_id' => $product['id'], 'quantity' => 1]);
        $input = $this->buyer(['payment_method' => 'card']);
        $token = bin2hex(random_bytes(32));
        $owner = bin2hex(random_bytes(32));
        $userId = $this->app->users()->create('buyer@example.test', '', '구매자');
        $fingerprint = $this->shop->cart->quote($cart, [], true)['fingerprint'];
        $payment = $this->shop->payments->forPlacing($input);
        $intent = $this->shop->checkoutIntents->stage($cart, $input, $token, $owner, $userId, $fingerprint, [], $payment, 'buy');
        $this->shop->checkoutIntents->checkout($intent, 'web', 'https://shop.example.test/shop/checkout',
            'https://shop.example.test/shop/pay/callback?provider=inicis');

        $this->shop->checkoutIntents->decline($intent, 'V901');
        self::assertSame('declined', $this->shop->checkoutIntents->find($payment['id'])['status']);
        self::assertSame('ready', $this->app->paymentGateway('inicis')->approvalState(\GnuCms\Shop\Commerce\CheckoutIntents::gatewayOrder($intent)));
        self::assertSame([], $this->http->calls);

        $retry = $this->shop->checkoutIntents->stage($cart, $input, $token, $owner, $userId, $fingerprint, [],
            $this->shop->payments->forPlacing($input), 'buy', $payment['id']);
        self::assertNotSame($payment['id'], $retry['payment']['id']);
    }

    #[DataProvider('connectionProvider')]
    public function testMethodsFollowThePaymentSettingsAndTheManualAccount(array $config): void
    {
        $this->setupPayments($config);
        self::assertSame(['card' => '신용카드', 'manual_transfer' => '무통장입금'], $this->shop->payments->methods());
        $this->savePayment(['provider' => 'inicis', 'environment' => 'test', 'methods' => ['card' => false],
            'manual' => ['enabled' => true, 'bank' => '국민은행', 'account' => '123-45', 'holder' => '상점'],
            'deadline_hours' => ['card' => 1, 'manual_transfer' => 72]]);
        self::assertSame(['manual_transfer' => '무통장입금'], $this->shop->payments->methods());
        $this->savePayment(['provider' => 'inicis', 'environment' => 'test', 'methods' => ['card' => false], 'manual' => ['enabled' => false, 'bank' => '', 'account' => '', 'holder' => ''],
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
        self::assertSame('inicis-pro', $window['kind']);
        self::assertSame((string) $order['total'], $window['fields']['P_AMT']);
        self::assertSame($order['payment_id'], $window['fields']['P_OID']);
        self::assertStringStartsWith('https://shop.example.test/shop/pay/callback?order=' . $order['payment_id'] . '&state=', $window['fields']['P_NEXT_URL']);
        self::assertSame('테스트 구매자', $window['fields']['P_UNAME']);
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
        self::assertSame('신용카드', $paid['payment']['label']);
        self::assertGreaterThan(0, (int) $paid['paid_at']);
        self::assertSame('paid', end($paid['history'])['status']);
        // 콜백이 다시 와도(재전송) 두 번 승인하지 않고 두 번 기록하지 않는다. 조회만 한 번 더 한다.
        $this->queueInquiry($order, $tid);
        $again = $this->shop->payments->complete($this->shop->orders->get((int) $order['id']), $this->authCallback($order));
        self::assertSame('paid', $again['status']);
        self::assertCount(2, $again['history']);
    }

    /**
     * 결제 대기가 아닌 주문(예: 이미 취소됨)에 결제사 승인이 도착하면 결제 완료로 적지 않되,
     * 주문에 「환불 필요」 표시와 이력을 남겨 관리자가 찾을 수 있게 한다. 예외는 그대로 올라간다.
     */
    #[DataProvider('connectionProvider')]
    public function testApprovalOnACancelledOrderIsRecordedForReviewInsteadOfPaid(array $config): void
    {
        $this->setupPayments($config);
        $order = $this->place('card');
        $this->shop->payments->checkout($order, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $this->shop->orders->transition((int) $order['id'], 'pending', 'cancelled', 'member', ['cancel_reason' => 'change_mind'], true);
        $tid = bin2hex(random_bytes(20));
        $this->queueApproval($order, $tid);
        try { $this->shop->payments->complete($order, $this->authCallback($order)); self::fail('결제 대기가 아닌 주문은 결제 완료가 되지 않는다'); }
        catch (DomainError $e) { self::assertArrayHasKey('status', $e->details()); }
        $after = $this->shop->orders->get((int) $order['id']);
        self::assertSame('cancelled', $after['status']);
        self::assertSame(0, (int) $after['paid_at']);
        self::assertSame(0, (int) $after['paid_amount']);
        self::assertTrue($after['payment']['needs_review']);
        self::assertSame($tid, $after['payment']['tid']);
        self::assertStringContainsString('환불이 필요합니다', end($after['history'])['note']);
        self::assertSame('cancelled', end($after['history'])['status']);

        // 콜백이 다시 와도 같은 승인을 두 번 적지 않는다(조회만 한 번 더 한다).
        $count = count($after['history']);
        $this->queueInquiry($after, $tid);
        try { $this->shop->payments->complete($after, $this->authCallback($order)); } catch (DomainError) {}
        self::assertCount($count, $this->shop->orders->get((int) $order['id'])['history']);
    }

    /** 관리자 결제 조회도 같은 어긋남을 찾아낸다 — 성공 안내 대신 오류로 알리고 한 번만 기록한다. */
    #[DataProvider('connectionProvider')]
    public function testSyncFlagsAnApprovalLeftOnANonPendingOrder(array $config): void
    {
        $this->setupPayments($config);
        $order = $this->place('card');
        try { $this->shop->payments->sync($order); self::fail('결제 기록이 없으면 성공 안내가 아니다'); }
        catch (DomainError $e) { self::assertStringContainsString('결제 기록이 없습니다', implode(' ', $e->details())); }
        $this->shop->payments->checkout($order, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $this->shop->orders->transition((int) $order['id'], 'pending', 'cancelled', 'member', ['cancel_reason' => 'change_mind'], true);
        // 승인 요청이 통신 실패로 끝나 원장은 pending 으로 남는다. 결제사에는 승인이 남아 있을 수 있다.
        $this->http->responses[] = new \RuntimeException('timeout');
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => '00']];
        try { $this->shop->payments->complete($order, $this->authCallback($order)); } catch (\Throwable) {}
        $cancelled = $this->shop->orders->get((int) $order['id']);

        $tid = bin2hex(random_bytes(20));
        $this->queueInquiry($cancelled, $tid);
        try { $this->shop->payments->sync($cancelled); self::fail('환불이 필요한 주문은 성공으로 끝나면 안 된다'); }
        catch (DomainError $e) { self::assertArrayHasKey('payment', $e->details()); }
        $after = $this->shop->orders->get((int) $order['id']);
        self::assertSame('cancelled', $after['status']);
        self::assertTrue($after['payment']['needs_review']);
        self::assertStringContainsString('환불이 필요합니다', end($after['history'])['note']);

        $count = count($after['history']);
        $this->queueInquiry($after, $tid);
        try { $this->shop->payments->sync($after); self::fail('두 번째 조회도 오류다'); } catch (DomainError) {}
        self::assertCount($count, $this->shop->orders->get((int) $order['id'])['history']);
    }

    /** 결제창을 여는 순간 기한이 코앞이면, 결제 도중 만료되지 않게 기한을 늘린다. */
    #[DataProvider('connectionProvider')]
    public function testCheckoutExtendsAnImminentDeadline(array $config): void
    {
        $this->setupPayments($config);
        $order = $this->place('card');
        $this->app->db()->update('yc_orders', ['pay_by' => Clock::timestamp() + 60], 'id = :id', ['id' => (int) $order['id']]);
        $order = $this->shop->orders->get((int) $order['id']);
        $this->shop->payments->checkout($order, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $after = $this->shop->orders->get((int) $order['id']);
        self::assertGreaterThanOrEqual(Clock::timestamp() + 800, (int) $after['pay_by']);
        self::assertCount(1, $after['history'], '기한 연장은 이력을 남기지 않는다');
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
        $this->savePayment(['provider' => 'inicis', 'environment' => 'test', 'methods' => ['card' => false], 'manual' => ['enabled' => false, 'bank' => '', 'account' => '', 'holder' => ''],
            'deadline_hours' => ['card' => 1, 'virtual_account' => 72, 'manual_transfer' => 72]]);
        $cart = $this->shop->cart->add([], ['product_id' => $this->product(['price' => '12000'])['id'], 'quantity' => 1]);
        $input = $this->buyer(['payment_method' => '']);
        self::assertSame([], $this->shop->payments->forPlacing($input), '접수 전용 확인');
        $receiptOnly = $this->shop->orders->place($cart, $input, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), $this->memberId(),
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

    /** 같은 환불 요청 키는 한 번만 센다 — 관리자가 폼을 두 번 보내거나 응답이 유실돼 다시 눌러도. */
    #[DataProvider('connectionProvider')]
    public function testTheSameRefundKeyIsCountedOnce(array $config): void
    {
        $this->setupPayments($config);
        $manual = $this->place('manual_transfer');
        $paid = $this->shop->orders->confirmDeposit((int) $manual['id'], 'admin');
        $key = bin2hex(random_bytes(16));
        $after = $this->shop->payments->refund($paid, 2000, '배송비 조정', $key, 'admin');
        self::assertSame(2000, (int) $after['refunded_amount']);
        $count = count($after['history']);
        $again = $this->shop->payments->refund($this->shop->orders->get((int) $manual['id']), 2000, '배송비 조정', $key, 'admin');
        self::assertSame(2000, (int) $again['refunded_amount']);
        self::assertCount($count, $again['history'], '같은 키는 이력도 한 번이다');

        $card = $this->place('card');
        $this->shop->payments->checkout($card, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $this->queueApproval($card, bin2hex(random_bytes(20)));
        $paidCard = $this->shop->payments->complete($card, $this->authCallback($card));
        $cardKey = bin2hex(random_bytes(16));
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => '00', 'prtcDate' => '20260921', 'prtcTime' => '090000', 'prtcPrice' => '2000', 'prtcRemains' => '10000', 'tid' => bin2hex(random_bytes(20)), 'prtcTid' => $paidCard['payment']['tid']]];
        self::assertSame(2000, (int) $this->shop->payments->refund($paidCard, 2000, '부분 환불', $cardKey, 'admin')['refunded_amount']);
        // 결제사 환불은 성공했는데 응답이 유실돼 관리자가 같은 요청을 다시 보낸 경우(주문 상태는 아직 그대로다).
        $calls = count($this->http->calls);
        $repeat = $this->shop->payments->refund($paidCard, 2000, '부분 환불', $cardKey, 'admin');
        self::assertSame(2000, (int) $repeat['refunded_amount']);
        self::assertCount($calls, $this->http->calls, '결제 계층이 캐시한 결과를 쓰므로 PG 를 다시 부르지 않는다');
    }

    /**
     * 환불 요청은 보냈는데 응답을 받지 못한 경우. 결제 계층은 그 요청을 보류로 잠그고 자동
     * 재전송을 하지 않으므로, 관리자가 결제사 기록과 대조하거나 미처리를 확인해 풀어 준다.
     */
    #[DataProvider('connectionProvider')]
    public function testARefundWhosePgResponseWasLostIsMatchedOrDismissed(array $config): void
    {
        $this->setupPayments($config);
        $card = $this->place('card');
        $this->shop->payments->checkout($card, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $tid = bin2hex(random_bytes(20));
        $this->queueApproval($card, $tid);
        $paid = $this->shop->payments->complete($card, $this->authCallback($card));
        self::assertSame([], $this->shop->payments->pendingRefunds($paid));
        self::assertSame([], $this->shop->payments->pendingRefunds($this->place('manual_transfer')), '결제사 주문이 아니면 대조할 것이 없다');

        $key = bin2hex(random_bytes(16));
        $this->http->responses[] = new \RuntimeException('timeout');
        try { $this->shop->payments->refund($paid, 2000, '배송비 조정', $key, 'admin'); self::fail('환불 응답을 받지 못했다'); }
        catch (\RuntimeException) {}
        self::assertSame(0, (int) $this->shop->orders->get((int) $card['id'])['refunded_amount']);
        $pending = $this->shop->payments->pendingRefunds($paid);
        self::assertArrayHasKey($key, $pending);
        self::assertSame(2000, $pending[$key]['amount']);
        self::assertSame('배송비 조정', $pending[$key]['reason']);

        // 결제사 기록에 같은 금액의 취소가 있으면 그 취소에 연결하고 주문에 한 번만 적는다.
        $reference = bin2hex(random_bytes(20));
        $this->queuePartialCancelInquiry($paid, $tid, [[$reference, 2000]]);
        $matched = $this->shop->payments->confirmRefund($paid, $key, $reference, 'admin');
        self::assertSame(2000, (int) $matched['refunded_amount']);
        self::assertSame([], $this->shop->payments->pendingRefunds($matched));
        self::assertStringContainsString('결제사 확인: ' . $reference, end($matched['history'])['note']);

        // 결제사가 아예 처리하지 않은 요청은 2시간 뒤 정리한다. 금액은 그대로다.
        $stuck = bin2hex(random_bytes(16));
        $this->http->responses[] = new \RuntimeException('timeout');
        try { $this->shop->payments->refund($matched, 1000, '추가 환불', $stuck, 'admin'); self::fail('환불 응답을 받지 못했다'); }
        catch (\RuntimeException) {}
        self::assertArrayHasKey($stuck, $this->shop->payments->pendingRefunds($matched));
        try {
            Clock::freeze(gmdate('Y-m-d H:i:s', time() + 7300));
            $this->queuePartialCancelInquiry($matched, $tid, [[$reference, 2000]]);
            $this->shop->payments->dismissRefund($matched, $stuck);
        } finally {
            Clock::unfreeze();
        }
        self::assertSame([], $this->shop->payments->pendingRefunds($matched));
        self::assertSame(2000, (int) $this->shop->orders->get((int) $card['id'])['refunded_amount']);
    }

    /** 결제사 화면에서 직접 취소한 금액도 결제 조회가 주문에 맞춰 적는다. 같은 조회를 반복해도 한 번만. */
    #[DataProvider('connectionProvider')]
    public function testSyncRecordsACancellationMadeOutsideTheShop(array $config): void
    {
        $this->setupPayments($config);
        $card = $this->place('card');
        $this->shop->payments->checkout($card, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $tid = bin2hex(random_bytes(20));
        $this->queueApproval($card, $tid);
        $paid = $this->shop->payments->complete($card, $this->authCallback($card));
        self::assertSame(0, (int) $paid['refunded_amount']);

        $reference = bin2hex(random_bytes(20));
        $this->queuePartialCancelInquiry($paid, $tid, [[$reference, 2000]]);
        $synced = $this->shop->payments->sync($paid);
        self::assertSame(2000, (int) $synced['refunded_amount']);
        self::assertSame('paid', $synced['status']);
        self::assertStringContainsString('결제사 조회로 확인한 취소', end($synced['history'])['note']);

        $count = count($synced['history']);
        $this->queuePartialCancelInquiry($synced, $tid, [[$reference, 2000]]);
        $again = $this->shop->payments->sync($synced);
        self::assertSame(2000, (int) $again['refunded_amount']);
        self::assertCount($count, $again['history']);
    }

    /**
     * 응답을 받지 못한 환불이 결제사 조회에는 이미 취소로 보일 수 있다. 그 금액은 아직 확정되지
     * 않았으므로 조회가 먼저 적어 버리면 나중의 환불 대조가 같은 돈을 두 번 적게 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testSyncLeavesAPendingRefundToTheMatchingStep(array $config): void
    {
        $this->setupPayments($config);
        $card = $this->place('card');
        $this->shop->payments->checkout($card, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $tid = bin2hex(random_bytes(20));
        $this->queueApproval($card, $tid);
        $paid = $this->shop->payments->complete($card, $this->authCallback($card));

        $key = bin2hex(random_bytes(16));
        $this->http->responses[] = new \RuntimeException('timeout');
        try { $this->shop->payments->refund($paid, 2000, '배송비 조정', $key, 'admin'); self::fail('환불 응답을 받지 못했다'); }
        catch (\RuntimeException) {}

        $reference = bin2hex(random_bytes(20));
        $this->queuePartialCancelInquiry($paid, $tid, [[$reference, 2000]]);
        try { $this->shop->payments->sync($paid); self::fail('확정되지 않은 환불이 있으면 오류로 알린다'); }
        catch (DomainError $e) { self::assertArrayHasKey('refund', $e->details()); }
        self::assertSame(0, (int) $this->shop->orders->get((int) $card['id'])['refunded_amount'], '확정 전에는 적지 않는다');

        $this->queuePartialCancelInquiry($paid, $tid, [[$reference, 2000]]);
        $matched = $this->shop->payments->confirmRefund($paid, $key, $reference, 'admin');
        self::assertSame(2000, (int) $matched['refunded_amount']);
        $count = count($matched['history']);
        $this->queuePartialCancelInquiry($matched, $tid, [[$reference, 2000]]);
        $synced = $this->shop->payments->sync($matched);
        self::assertSame(2000, (int) $synced['refunded_amount'], '대조로 적은 금액을 조회가 다시 더하지 않는다');
        self::assertCount($count, $synced['history']);
    }

    /** 응답만 유실된 환불을 같은 키로 다시 보내면 결제 계층의 캐시된 결과로 한 번만 적힌다. */
    #[DataProvider('connectionProvider')]
    public function testRetryingALostRefundWithTheSameKeyRecordsItOnce(array $config): void
    {
        $this->setupPayments($config);
        $card = $this->place('card');
        $this->shop->payments->checkout($card, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $this->queueApproval($card, bin2hex(random_bytes(20)));
        $paid = $this->shop->payments->complete($card, $this->authCallback($card));

        $key = bin2hex(random_bytes(16));
        $this->http->responses[] = new \RuntimeException('timeout');
        try { $this->shop->payments->refund($paid, 2000, '배송비 조정', $key, 'admin'); self::fail('환불 응답을 받지 못했다'); }
        catch (\RuntimeException) {}
        // 결제 계층은 응답을 받지 못한 요청을 재전송하지 않는다 — 같은 키의 재시도는 503 으로 막힌다.
        try { $this->shop->payments->refund($paid, 2000, '배송비 조정', $key, 'admin'); self::fail('자동 재전송은 없다'); }
        catch (DomainError $e) { self::assertSame(503, $e->status()); }
        self::assertSame(0, (int) $this->shop->orders->get((int) $card['id'])['refunded_amount']);

        // 결제사에서 처리된 것을 확인해 원장이 성공으로 닫히면, 같은 키의 재시도는 캐시된 결과로 한 번만 적는다.
        $reference = bin2hex(random_bytes(20));
        $this->queuePartialCancelInquiry($paid, $paid['payment']['tid'], [[$reference, 2000]]);
        $this->app->inicisGateway()->confirmRefund(Payments::gatewayOrder($paid), $key, $reference);
        $calls = count($this->http->calls);
        $after = $this->shop->payments->refund($paid, 2000, '배송비 조정', $key, 'admin');
        self::assertSame(2000, (int) $after['refunded_amount']);
        self::assertCount($calls, $this->http->calls, '캐시된 결과를 쓰므로 PG 를 다시 부르지 않는다');
        self::assertSame(2000, (int) $this->shop->payments->refund($paid, 2000, '배송비 조정', $key, 'admin')['refunded_amount']);
    }

    #[DataProvider('connectionProvider')]
    public function testOverdueUnpaidOrdersExpireAndReturnStockButApprovingOnesAreLeftAlone(array $config): void
    {
        $this->setupPayments($config);
        $manual = $this->place('manual_transfer');
        $card = $this->place('card');
        $approving = $this->place('card');
        $this->shop->payments->checkout($approving, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $db = $this->app->db();
        foreach ([$manual, $card, $approving] as $order) $db->update('yc_orders', ['pay_by' => Clock::timestamp() - 60], 'id = :id', ['id' => (int) $order['id']]);
        // 승인 요청 중인 주문: 승인 응답이 없는 채 complete() 가 던지면 원장은 pending 으로 남는다(결제 계층의 규칙).
        $this->http->responses[] = new \RuntimeException('timeout');
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => '00']];
        try { $this->shop->payments->complete($approving, $this->authCallback($approving)); } catch (\Throwable) {}
        self::assertSame('pending', $this->app->inicisGateway()->approvalState(Payments::gatewayOrder($this->shop->orders->get((int) $approving['id']))));

        $productId = (int) $manual['items'][0]['product_id'];
        $stockBefore = (int) $this->shop->products->get($productId)['stock'];
        self::assertSame(2, $this->shop->payments->expireOverdue());
        self::assertSame('cancelled', $this->shop->orders->get((int) $manual['id'])['status']);
        self::assertSame('cancelled', $this->shop->orders->get((int) $card['id'])['status']);
        self::assertSame('pending', $this->shop->orders->get((int) $approving['id'])['status']);
        self::assertSame($stockBefore + 1, (int) $this->shop->products->get($productId)['stock']);
        self::assertStringContainsString('결제 기한이 지나', end($this->shop->orders->get((int) $card['id'])['history'])['note']);
        self::assertSame(0, $this->shop->payments->expireOverdue(), '두 번째 호출은 할 일이 없다');

        // 승인 진행 여부를 확인하다 실패하면(원장 읽기 오류 등) 그 주문은 건드리지 않는다.
        $unknown = $this->place('manual_transfer');
        $db->update('yc_orders', ['pay_by' => Clock::timestamp() - 60], 'id = :id', ['id' => (int) $unknown['id']]);
        self::assertSame(0, $this->shop->orders->expire(Clock::timestamp(), static fn (array $order): bool => throw new \RuntimeException('원장을 읽지 못했습니다')));
        self::assertSame('pending', $this->shop->orders->get((int) $unknown['id'])['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testRefundGoesThroughThePgForCardAndIsOnlyRecordedForManualTransfer(array $config): void
    {
        $this->setupPayments($config);
        $card = $this->place('card');
        $this->shop->payments->checkout($card, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $tid = bin2hex(random_bytes(20));
        $this->queueApproval($card, $tid);
        $paid = $this->shop->payments->complete($card, $this->authCallback($card));
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => '00', 'prtcDate' => '20260921', 'prtcTime' => '090000', 'prtcPrice' => '2000', 'prtcRemains' => '10000', 'tid' => bin2hex(random_bytes(20)), 'prtcTid' => $paid['payment']['tid']]];
        $after = $this->shop->payments->refund($paid, 2000, '배송비 조정', bin2hex(random_bytes(16)), 'admin');
        self::assertSame(2000, (int) $after['refunded_amount']);
        self::assertSame('paid', $after['status']);
        self::assertSame('https://stginiapi.inicis.com/v2/pg/partialRefund', end($this->http->calls)['url']);
        self::assertStringContainsString('환불 2,000원: 배송비 조정', end($after['history'])['note']);

        $manual = $this->place('manual_transfer');
        $paidManual = $this->shop->orders->confirmDeposit((int) $manual['id'], 'admin');
        $calls = count($this->http->calls);
        $after = $this->shop->payments->refund($paidManual, (int) $paidManual['total'], '고객 요청', bin2hex(random_bytes(16)), 'admin');
        self::assertSame((int) $paidManual['total'], (int) $after['refunded_amount']);
        self::assertCount($calls, $this->http->calls, '무통장 환불은 PG 를 부르지 않는다');
        $cancelled = $this->shop->orders->transition((int) $after['id'], 'paid', 'cancelled', 'admin', ['note' => '환불 완료']);
        self::assertSame('cancelled', $cancelled['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testReceivedReturnRefundsCardOnceThroughTheGateway(array $config): void
    {
        $this->setupPayments($config);
        $card = $this->place('card');
        $this->shop->payments->checkout($card, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $this->queueApproval($card, bin2hex(random_bytes(20)));
        $paid = $this->shop->payments->complete($card, $this->authCallback($card));
        $id = (int) $paid['id'];
        $this->shop->orders->transition($id, 'paid', 'shipped', 'test', ['carrier' => '테스트 택배', 'tracking_number' => 'RETURN-CARD']);
        $this->shop->orders->transition($id, 'shipped', 'completed', 'test');
        $request = $this->shop->orders->requestReturn($id, 'test', ['return_reason' => 'defective'], (int) $paid['user_id']);
        $before = count($this->http->calls);
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => '00', 'cancelDate' => '20261006', 'cancelTime' => '120000']];
        $returned = $this->shop->orders->completeReturn($id, 'test', ['return_received' => '1', 'return_restock' => '1'], $this->shop->payments);
        self::assertSame('returned', $returned['status']);
        self::assertSame((int) $paid['paid_amount'], (int) $returned['refunded_amount']);
        self::assertCount($before + 1, $this->http->calls);
        self::assertSame('https://stginiapi.inicis.com/v2/pg/refund', end($this->http->calls)['url']);
        self::assertSame($request['payment']['return']['key'], $returned['payment']['refund_keys'][0]);
        $this->shop->orders->completeReturn($id, 'test', ['return_received' => '1', 'return_restock' => '1'], $this->shop->payments);
        self::assertCount($before + 1, $this->http->calls, '완료 중복 제출은 PG를 다시 호출하지 않습니다.');
        self::assertSame(5, (int) $this->shop->products->get((int) $paid['items'][0]['product_id'])['stock']);
    }

    #[DataProvider('connectionProvider')]
    public function testUncertainReturnRefundWaitsForReconciliationWithoutResendingOrDuplicateNotice(array $config): void
    {
        $this->setupPayments($config);
        $card = $this->place('card');
        $this->shop->payments->checkout($card, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $tid = bin2hex(random_bytes(20));
        $this->queueApproval($card, $tid);
        $paid = $this->shop->payments->complete($card, $this->authCallback($card));
        $events = [];
        $orders = new Orders($this->shop->store, $this->shop->cart, $this->shop->settings, 'Asia/Seoul',
            function (int $user, int $id, string $status) use (&$events): void { $events[] = $status; });
        $payments = new Payments($this->app, $this->shop->settings, $orders);
        $id = (int) $paid['id'];
        $orders->transition($id, 'paid', 'shipped', 'test', ['carrier' => '테스트', 'tracking_number' => 'RETURN-TIMEOUT']);
        $orders->transition($id, 'shipped', 'completed', 'test');
        $request = $orders->requestReturn($id, 'test', ['return_reason' => 'defective'], (int) $paid['user_id']);
        $events = [];
        $this->http->responses[] = new \RuntimeException('timeout');
        try { $orders->completeReturn($id, 'test', ['return_received' => '1', 'return_restock' => '1'], $payments); self::fail('미확정 환불'); }
        catch (\RuntimeException) {}
        $calls = count($this->http->calls);
        self::assertSame('returning', $orders->get($id)['status']);
        self::assertSame(0, (int) $orders->get($id)['refunded_amount']);
        self::assertSame(4, (int) $this->shop->products->get((int) $paid['items'][0]['product_id'])['stock']);
        try { $orders->completeReturn($id, 'test', ['return_received' => '1'], $payments); self::fail('자동 재전송 금지'); }
        catch (DomainError $e) { self::assertSame(503, $e->status()); }
        self::assertCount($calls, $this->http->calls);
        try { $orders->closeReturn($id, 'test', '요청 종료', $payments); self::fail('환불 진행 중 종료 금지'); }
        catch (DomainError $e) { self::assertSame(422, $e->status()); }
        $reference = bin2hex(random_bytes(20));
        $this->queuePartialCancelInquiry($request, $tid, [[$reference, (int) $paid['paid_amount']]]);
        $matched = $payments->confirmRefund($request, $request['payment']['return']['key'], $reference, 'test');
        self::assertSame((int) $paid['paid_amount'], (int) $matched['refunded_amount']);
        self::assertSame([], $events, '환불 대조에서는 외부 안내를 중복 발송하지 않는다.');
        $orders->completeReturn($id, 'test', ['return_received' => '1', 'return_restock' => '1'], $payments);
        self::assertSame(['returned'], $events);
        self::assertCount($calls + 1, $this->http->calls, '결제사 조회만 한 번 추가하고 환불 재전송은 없다.');
    }
}
