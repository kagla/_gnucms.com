<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Payment\InicisGateway;
use GnuCms\Shop\Service;
use GnuCms\Tests\Payment\FakeTransport;
use GnuCms\Tests\Payment\Fixtures;
use GnuCms\Tests\Support\WebTestCase;
use GnuCms\Web\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ShopCommerceTest extends WebTestCase
{
    private App $app;
    private Service $shop;
    private array $product;
    private string $root;
    private FakeTransport $http;
    private array $payConfig;

    private function setupShop(array $config): void
    {
        session_name(GNUCMS_ID . '_session'); session_start(); $_SESSION = []; session_write_close();
        $this->root = sys_get_temp_dir() . '/gnucms-yc-commerce-' . bin2hex(random_bytes(8));
        $config['prefix'] = 'ycweb' . bin2hex(random_bytes(4)) . '_';
        $this->app = $this->makeApp($config, ['storage' => ['dir' => $this->root], 'uploads' => ['dir' => $this->root . '/uploads'], 'app' => ['url' => 'https://shop.example.test']]);
        $this->shop = new Service($this->app);
        $category = $this->shop->categories->save(['name' => '생활용품', 'active' => '1', 'list_columns' => '4', 'list_rows' => '5', 'image_width' => '300', 'image_height' => '0']);
        $id = $this->shop->products->save(['code' => 'DEMO', 'name' => '테스트 상품', 'category_id' => $category, 'price' => '12000', 'stock' => '10', 'active' => '1'], []);
        $this->product = $this->shop->products->get($id);
    }

    private function form(array $data): array { return $data + ['csrf_token' => $_SESSION['csrf_token'] ?? '']; }
    private function add(int $qty = 1): void
    {
        $this->get($this->app, '/shop/item', ['id' => 'DEMO']);
        self::assertSame(303, $this->post($this->app, '/shop/cart/add', $this->form(['product_id' => $this->product['id'], 'quantity' => $qty]))->getStatusCode());
    }
    private function cartAjax(array $input): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/shop/cart/add')
            ->withHeader('Accept', 'application/json')->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($input);
        return Kernel::create($this->app, dirname(__DIR__, 2) . '/templates', '')->handle($request);
    }

    #[DataProvider('connectionProvider')]
    public function testAjaxCartStockChangesPreserveSessionsAndReturnCurrentOptionAvailability(array $config): void
    {
        $this->setupShop($config);
        $id = (int) $this->product['id'];
        $extra = $this->shop->store->insert('yc_options', ['product_id' => $id, 'kind' => 'extra', 'value1' => '', 'value2' => '선물 포장',
            'value3' => '', 'price' => 1000, 'stock' => 8, 'stock_alert' => 0, 'active' => 1, 'sort_order' => 0]);
        $this->get($this->app, '/shop/item', ['id' => 'DEMO']);
        $input = $this->form(['product_id' => $id, 'quantity' => 1, 'extras' => [$extra => 2]]);
        $added = $this->cartAjax($input);
        self::assertSame(200, $added->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $added->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $added->getHeaderLine('Cache-Control'));
        self::assertSame('/shop/cart?added=1', json_decode($this->body($added), true)['redirect']);
        $cart = $_SESSION['yc_cart'];
        $this->shop->store->update('yc_options', $extra, ['stock' => 3]);
        $failed = $this->cartAjax($input);
        self::assertSame(422, $failed->getStatusCode());
        $data = json_decode($this->body($failed), true);
        self::assertStringContainsString('선물 포장', $data['error']['details'][$id . ':' . $extra]);
        self::assertSame(['stock' => 3, 'in_cart' => 2], $data['availability']['items'][$extra]);
        self::assertSame(['stock' => 10, 'in_cart' => 1], $data['availability']['items'][0]);
        self::assertSame($cart, $_SESSION['yc_cart'], '재고 오류가 있으면 어느 행도 부분 저장하지 않는다.');
        self::assertSame(3, (int) $this->shop->store->get('yc_options', $extra)['stock']);
        $buy = $this->cartAjax($input + ['action' => 'buy']);
        self::assertSame(200, $buy->getStatusCode(), $this->body($buy));
        self::assertSame('/shop/checkout?flow=buy', json_decode($this->body($buy), true)['redirect']);
        self::assertSame($cart, $_SESSION['yc_cart']);
        $buyCart = $_SESSION['yc_buy'];
        $this->shop->store->update('yc_options', $extra, ['stock' => 0]);
        $failedBuy = $this->cartAjax($input + ['action' => 'buy']);
        self::assertSame(422, $failedBuy->getStatusCode());
        self::assertSame(['stock' => 0, 'in_cart' => 0], json_decode($this->body($failedBuy), true)['availability']['items'][$extra]);
        self::assertSame($buyCart, $_SESSION['yc_buy']);
        self::assertSame($cart, $_SESSION['yc_cart']);
        $this->shop->store->update('yc_options', $extra, ['stock' => 5, 'active' => 0]);
        self::assertSame(0, json_decode($this->body($this->cartAjax($input)), true)['availability']['items'][$extra]['stock']);
        $this->shop->store->delete('yc_options', 'id = ?', [$extra]);
        $deleted = $this->cartAjax($input);
        self::assertSame(422, $deleted->getStatusCode());
        self::assertArrayNotHasKey($extra, json_decode($this->body($deleted), true)['availability']['items']);
        self::assertSame($cart, $_SESSION['yc_cart']);
        self::assertSame(403, $this->cartAjax(['product_id' => $id, 'quantity' => 1])->getStatusCode());
        self::assertSame(404, $this->cartAjax($this->form(['product_id' => []]))->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testExtraComponentsStayInsideTheirProductAcrossCartCheckoutAndReceipt(array $config): void
    {
        $this->setupShop($config);
        $id = (int) $this->product['id'];
        $extra = $this->shop->store->insert('yc_options', ['product_id' => $id, 'kind' => 'extra', 'value2' => '선물 포장', 'price' => 1000, 'stock' => 8]);
        $this->get($this->app, '/shop/item', ['id' => 'DEMO']);
        $added = $this->post($this->app, '/shop/cart/add', $this->form(['product_id' => $id, 'quantity' => 2, 'extras' => [$extra => 2]]));
        self::assertSame(303, $added->getStatusCode());
        $cart = $this->body($this->get($this->app, '/shop/cart'));
        self::assertStringContainsString('담은 상품 1종 <span class="muted">· 선택 구성 1개</span>', $cart);
        self::assertStringContainsString('aria-label="담은 수량 2개"', $cart);
        self::assertSame(1, substr_count($cart, 'class="yc-cart-image"'));
        self::assertSame(1, substr_count($cart, '<h2>테스트 상품</h2>'));
        self::assertStringContainsString('data-yc-cart-product="' . $id . '"', $cart);
        self::assertStringContainsString('data-yc-cart-extras', $cart);
        self::assertStringContainsString('<h3>선물 포장</h3>', $cart);
        self::assertStringContainsString('name="quantities[' . $id . ':' . $extra . ']" value="2" min="1"', $cart);
        self::assertSame(26000, $this->shop->cart->quote($_SESSION['yc_cart'])['subtotal']);
        $public = $this->body($this->get($this->app, '/shop/item', ['id' => 'DEMO']));
        self::assertStringContainsString('aria-label="담은 수량 2개"', $public);
        // 판매를 중지한 추가 구성도 본상품으로 잘못 세거나 따로 표시하지 않는다.
        $this->shop->store->update('yc_options', $extra, ['active' => 0]);
        $inactive = $this->body($this->get($this->app, '/shop/cart'));
        self::assertStringContainsString('담은 상품 1종 <span class="muted">· 선택 구성 1개</span>', $inactive);
        self::assertStringContainsString('<h3>선물 포장</h3>', $inactive);
        self::assertStringContainsString('선택한 옵션은 더 이상 판매하지 않습니다.', $inactive);
        self::assertStringContainsString('aria-label="담은 수량 2개"', $inactive);
        $this->shop->store->update('yc_options', $extra, ['active' => 1]);
        $checkout = $this->body($this->get($this->app, '/shop/checkout'));
        self::assertStringContainsString('주문 상품 <small>1개 항목</small>', $checkout);
        self::assertStringContainsString('data-yc-order-product="' . $id . '"', $checkout);
        self::assertStringContainsString('data-yc-order-extras', $checkout);
        self::assertStringContainsString('<strong>선물 포장</strong>', $checkout);
        $placed = $this->post($this->app, '/shop/checkout', $this->checkout());
        self::assertSame(303, $placed->getStatusCode(), $this->body($placed));
        $receipt = $this->body($this->get($this->app, '/shop/order', ['number' => $this->numberFrom($placed)]));
        self::assertStringContainsString('주문 상품 <small>1개 항목</small>', $receipt);
        self::assertStringContainsString('data-yc-order-extras', $receipt);
        self::assertStringContainsString('<strong>선물 포장</strong>', $receipt);
        self::assertSame(6, (int) $this->shop->store->get('yc_options', $extra)['stock']);
        self::assertSame(8, (int) $this->shop->store->get('yc_products', $id)['stock']);
    }

    private function checkout(array $overrides = []): array
    {
        $response = $this->get($this->app, '/shop/checkout');
        self::assertSame(200, $response->getStatusCode(), substr($this->body($response), 0, 200));
        preg_match('/name="checkout_token" value="([a-f0-9]{64})"/', $this->body($response), $m);
        self::assertArrayHasKey(1, $m);
        return $this->form($overrides + ['checkout_token' => $m[1], 'flow' => 'cart', 'action' => 'place', 'buyer_name' => '테스트 주문자', 'email' => 'order@example.test',
            'phone' => '010-0000-0000', 'recipient' => '테스트 수령인', 'recipient_phone' => '010-0000-0000', 'postcode' => '04524',
            'address' => '테스트 배송 주소', 'address_detail' => '101호', 'delivery_note' => '<script>alert(1)</script>', 'password' => bin2hex(random_bytes(12)), 'agree' => '1']);
    }

    /** 이니시스 테스트 환경을 켜고 쇼핑몰이 그 환경을 쓰게 한다. 무통장 계좌도 켠다. */
    private function enablePayments(): void
    {
        $this->payConfig = Fixtures::config();
        $this->app->paymentSettings()->save('test', $this->payConfig);
        $this->app->paymentSettings()->enable('test', true);
        $this->http = new FakeTransport();
        $this->app->setInicisGateway(new InicisGateway($this->app->paymentSettings(), $this->http));
        $settings = $this->shop->settings->all();
        $settings['payment'] = ['environment' => 'test', 'manual' => ['enabled' => true, 'bank' => '국민은행', 'account' => '123-45', 'holder' => '상점'],
            'deadline_hours' => ['card' => 1, 'virtual_account' => 72, 'manual_transfer' => 72]];
        $db = $this->app->db();
        $payload = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($db->selectOne('SELECT id FROM ' . $db->table('yc_settings') . " WHERE id = 'settings'") === null) {
            $db->insert('yc_settings', ['id' => 'settings', 'payload' => $payload]);
        } else {
            $db->update('yc_settings', ['payload' => $payload], 'id = :id', ['id' => 'settings']);
        }
    }

    /** 303 Location 의 number= 값. */
    private function numberFrom(\Psr\Http\Message\ResponseInterface $response): string
    {
        $location = $response->getHeaderLine('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        return (string) ($query['number'] ?? '');
    }

    private function placeCardOrder(): array
    {
        $this->add();
        $response = $this->post($this->app, '/shop/checkout', $this->checkout(['payment_method' => 'card']));
        $number = $this->numberFrom($response);
        return $this->shop->orders->get((int) $this->app->db()->selectOne('SELECT id FROM ' . $this->app->db()->table('yc_orders') . ' WHERE number = ?', [$number])['id']);
    }

    private function callbackFor(array $order): array
    {
        return ['resultCode' => '0000', 'mid' => $this->payConfig['merchant_id'], 'orderNumber' => $order['payment_id'], 'idc_name' => 'stg',
            'authToken' => bin2hex(random_bytes(32)), 'authUrl' => 'https://stgstdpay.inicis.com/api/payAuth', 'netCancelUrl' => 'https://stgstdpay.inicis.com/api/netCancel'];
    }

    private function queueApproval(array $order, string $tid): void
    {
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => '0000', 'mid' => $this->payConfig['merchant_id'], 'MOID' => $order['payment_id'],
            'TotPrice' => (string) $order['total'], 'payMethod' => 'Card', 'tid' => $tid, 'currency' => 'WON']];
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => 'SUCCESS', 'transactionStatus' => 'APPROVAL', 'mid' => $this->payConfig['merchant_id'],
            'oid' => $order['payment_id'], 'price' => (string) $order['total'], 'tid' => $tid, 'paymethod' => 'Card', 'approvedDate' => '20260920', 'approvedTime' => '120000',
            'cardInfo' => ['currencyCode' => 'WON'], 'partCancelTransInfo' => []]];
    }

    /** 세션 없는 외부 요청. 폼 본문과 쿼리를 그대로 싣는다. */
    private function externalPost(string $path, array $query, array $body): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path . '?' . http_build_query($query))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(http_build_query($body)));
        return Kernel::create($this->app, dirname(__DIR__, 2) . '/templates', '')->handle($request);
    }

    #[DataProvider('connectionProvider')]
    public function testPayPageOpensTheInicisWindowForTheOrderOwnerOnly(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeCardOrder();
        $html = $this->body($this->get($this->app, '/shop/pay', ['number' => $order['number']]));
        self::assertStringContainsString('INIPayPro_v2.js', $html);
        self::assertStringContainsString('테스트 결제', $html);
        self::assertStringContainsString('name="P_OID" value="' . $order['payment_id'] . '"', $html);
        self::assertStringContainsString('name="P_AMT" value="' . $order['total'] . '"', $html);
        self::assertStringContainsString('name="P_NEXT_URL" value="https://shop.example.test/shop/pay/callback?order=' . $order['payment_id'], $html);
        self::assertStringNotContainsString('name="P_CLOSE_URL"', $html);

        session_start(); $_SESSION['yc_guest_orders'] = []; session_write_close();
        self::assertSame(404, $this->get($this->app, '/shop/pay', ['number' => $order['number']])->getStatusCode());
    }

    /** 모바일 브라우저도 PayPro 스크립트를 사용하되 기기 유형을 MOBILE로 전달한다. */
    #[DataProvider('connectionProvider')]
    public function testPayPageRendersTheInicisMobileFormForAPhone(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeCardOrder();
        $html = $this->body($this->get($this->app, '/shop/pay', ['number' => $order['number']],
            ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148']));
        self::assertStringContainsString('name="P_PAY_TYPE" value="CARD"', $html);
        self::assertStringContainsString('name="P_DEVICE_TYPE" value="MOBILE"', $html);
        self::assertStringContainsString('accept-charset="UTF-8"', $html);
        self::assertStringContainsString('id="yc-pay-form"', $html);
        self::assertStringContainsString('id="yc-pay-button" type="button"', $html);
        self::assertStringContainsString('INIPayPro_v2.js', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testCallbackNeedsTheOrderStateAndMarksTheOrderPaid(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeCardOrder();
        $this->get($this->app, '/shop/pay', ['number' => $order['number']]);
        $state = \GnuCms\Payment\CallbackToken::create($this->app, \GnuCms\Shop\Commerce\Payments::gatewayOrder($order));

        self::assertSame(403, $this->externalPost('/shop/pay/callback', ['order' => $order['payment_id'], 'state' => str_repeat('0', 64)], $this->callbackFor($order))->getStatusCode());
        self::assertSame(403, $this->externalPost('/shop/pay/callback', ['order' => str_repeat('a', 32), 'state' => $state], $this->callbackFor($order))->getStatusCode());
        self::assertSame('pending', $this->shop->orders->get((int) $order['id'])['status']);

        $tid = bin2hex(random_bytes(20));
        $this->queueApproval($order, $tid);
        $response = $this->externalPost('/shop/pay/callback', ['order' => $order['payment_id'], 'state' => $state], $this->callbackFor($order));
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://shop.example.test/shop/order?number=' . rawurlencode($order['number']), $response->getHeaderLine('Location'));
        $paid = $this->shop->orders->get((int) $order['id']);
        self::assertSame('paid', $paid['status']);
        self::assertSame($tid, $paid['payment']['tid']);

        $page = $this->body($this->get($this->app, '/shop/order', ['number' => $order['number']]));
        self::assertStringContainsString('결제 완료', $page);
        self::assertStringContainsString('테스트 결제', $page);
        self::assertStringNotContainsString('주문 취소', $page);
        // 원장은 승인 완료(confirmed)로 남지만, 끝난 주문에 "확인 중"을 보여 주면 안 된다.
        self::assertStringNotContainsString('결제 결과를 확인하는 중', $page);
    }

    #[DataProvider('connectionProvider')]
    public function testFailedApprovalSendsTheCustomerBackWithAFailureNote(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeCardOrder();
        $this->get($this->app, '/shop/pay', ['number' => $order['number']]);
        $state = \GnuCms\Payment\CallbackToken::create($this->app, \GnuCms\Shop\Commerce\Payments::gatewayOrder($order));
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => '9999', 'resultMsg' => '거절']];
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => '00']]; // 망취소 응답
        $response = $this->externalPost('/shop/pay/callback', ['order' => $order['payment_id'], 'state' => $state], $this->callbackFor($order));
        self::assertSame(303, $response->getStatusCode());
        self::assertStringEndsWith('&pay=failed', $response->getHeaderLine('Location'));
        self::assertSame('pending', $this->shop->orders->get((int) $order['id'])['status']);
    }

    /**
     * 결제창만 연 주문(원장 ready)은 아직 승인이 진행 중이 아니므로 고객이 취소할 수 있다.
     * 승인 요청이 끝나지 않은 주문(원장 pending)은 돈이 움직였을 수 있어 취소를 막는다.
     */
    #[DataProvider('connectionProvider')]
    public function testApprovalInFlightBlocksTheCustomerCancelAndThePayLink(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $ready = $this->placeCardOrder();
        $this->get($this->app, '/shop/pay', ['number' => $ready['number']]);
        $cancel = $this->post($this->app, '/shop/order/cancel', $this->form(['number' => $ready['number']]));
        self::assertSame(303, $cancel->getStatusCode(), $this->body($cancel));
        self::assertSame('cancelled', $this->shop->orders->get((int) $ready['id'])['status']);

        $order = $this->placeCardOrder();
        $this->get($this->app, '/shop/pay', ['number' => $order['number']]);
        $state = \GnuCms\Payment\CallbackToken::create($this->app, \GnuCms\Shop\Commerce\Payments::gatewayOrder($order));
        $this->http->responses[] = new \RuntimeException('timeout');
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => '00']]; // 망취소 응답
        $this->externalPost('/shop/pay/callback', ['order' => $order['payment_id'], 'state' => $state], $this->callbackFor($order));

        $blocked = $this->post($this->app, '/shop/order/cancel', $this->form(['number' => $order['number']]));
        self::assertSame(422, $blocked->getStatusCode());
        self::assertStringContainsString('결제 결과를 확인하는 중', $this->body($blocked));
        self::assertSame('pending', $this->shop->orders->get((int) $order['id'])['status']);
        $page = $this->body($this->get($this->app, '/shop/order', ['number' => $order['number']]));
        self::assertStringContainsString('결제 결과를 확인하는 중', $page);
        self::assertStringNotContainsString('결제하기', $page);
        self::assertStringNotContainsString('전체 주문 취소하기', $page);
    }

    /** 결제 원장을 읽지 못해도(표 없음·키 교체) 주문 상세는 열려야 한다 — 결제 진행 표시만 포기한다. */
    #[DataProvider('connectionProvider')]
    public function testOrderPageStaysUpWhenThePaymentJournalCannotBeRead(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeCardOrder();
        $db = $this->app->db();
        $db->execute('DROP TABLE ' . $db->table('pay_transactions'));
        $response = $this->get($this->app, '/shop/order', ['number' => $order['number']]);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('결제하기', $this->body($response));
    }

    /** 기한이 지난 미결제 결제사 주문은 결제창 링크 대신 다시 접수하라고 안내한다. */
    #[DataProvider('connectionProvider')]
    public function testExpiredCardOrderShowsTheDeadlineNoticeInsteadOfThePayLink(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeCardOrder();
        $this->app->db()->update('yc_orders', ['pay_by' => time() - 60], 'id = :id', ['id' => (int) $order['id']]);
        $page = $this->body($this->get($this->app, '/shop/order', ['number' => $order['number']]));
        self::assertStringContainsString('결제 기한이 지났습니다', $page);
        self::assertStringNotContainsString('결제하기', $page);
    }

    #[DataProvider('connectionProvider')]
    public function testCheckoutStaysReceiptOnlyWhenNoMethodIsEnabled(array $config): void
    {
        $this->setupShop($config);
        $this->add();
        $html = $this->body($this->get($this->app, '/shop/checkout'));
        self::assertStringNotContainsString('name="payment_method"', $html);
        self::assertStringContainsString('주문 접수 안내', $html);
        $response = $this->post($this->app, '/shop/checkout', $this->checkout());
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('/shop/order?number=', $response->getHeaderLine('Location'));
        $page = $this->body($this->get($this->app, $response->getHeaderLine('Location')));
        self::assertStringContainsString('온라인 결제 내역이 없는 주문입니다', $page);
    }

    /** 쇼핑몰 공개를 끈다. */
    private function hideShop(): void
    {
        $settings = $this->shop->settings->all();
        $settings['visible'] = false;
        $db = $this->app->db();
        $payload = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($db->selectOne('SELECT id FROM ' . $db->table('yc_settings') . " WHERE id = 'settings'") === null) {
            $db->insert('yc_settings', ['id' => 'settings', 'payload' => $payload]);
        } else {
            $db->update('yc_settings', ['payload' => $payload], 'id = :id', ['id' => 'settings']);
        }
    }

    /** 공개를 꺼도 이미 받은 주문의 영수증은 열린다. 결제 콜백도 준비 중 안내로 떨어지지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testHiddenShopKeepsTheOrderReceiptAndTheCallbackReachable(array $config): void
    {
        $this->setupShop($config);
        $this->add();
        $response = $this->post($this->app, '/shop/checkout', $this->checkout());
        self::assertSame(303, $response->getStatusCode());
        $number = $this->numberFrom($response);
        self::assertNotSame('', $number);

        $this->hideShop();
        $receipt = $this->get($this->app, '/shop/order', ['number' => $number]);
        self::assertSame(200, $receipt->getStatusCode());
        self::assertStringContainsString($number, $this->body($receipt));
        self::assertStringNotContainsString('쇼핑몰을 준비 중입니다', $this->body($receipt));
        // 주문 조회 폼처럼 새로 시작하는 화면은 그대로 닫힌다.
        self::assertStringContainsString('쇼핑몰을 준비 중입니다', $this->body($this->get($this->app, '/shop/orders')));

        // 콜백은 ExternalRequests 가 본문 파싱 전에 인증한다. 닫힌 안내가 아니라 인증 실패다.
        $callback = $this->externalPost('/shop/pay/callback', [], []);
        self::assertSame(403, $callback->getStatusCode());
        self::assertStringNotContainsString('쇼핑몰을 준비 중입니다', $this->body($callback));
    }

    #[DataProvider('connectionProvider')]
    public function testCheckoutOffersMethodsAndSendsCardOrdersToThePayPage(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $this->add();
        $html = $this->body($this->get($this->app, '/shop/checkout'));
        self::assertStringContainsString('name="payment_method" value="card"', $html);
        self::assertStringContainsString('테스트 결제', $html);
        self::assertStringContainsString('name="payment_method" value="manual_transfer"', $html);
        self::assertStringContainsString('국민은행 123-45', $html);
        self::assertStringNotContainsString('이 화면에서는 결제되지 않습니다', $html);

        $response = $this->post($this->app, '/shop/checkout', $this->checkout(['payment_method' => 'card']));
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('/shop/pay?number=', $response->getHeaderLine('Location'));
        $number = $this->numberFrom($response);
        $order = $this->shop->orders->get((int) $this->app->db()->selectOne('SELECT id FROM ' . $this->app->db()->table('yc_orders') . ' WHERE number = ?', [$number])['id']);
        self::assertSame('card', $order['payment_method']);
        self::assertSame('pending', $order['status']);

        $page = $this->body($this->get($this->app, '/shop/order', ['number' => $number, 'pay' => 'closed']));
        self::assertStringContainsString('결제창이 닫혔습니다', $page);
        self::assertStringContainsString('/shop/pay?number=' . rawurlencode($number), $page);
    }

    #[DataProvider('connectionProvider')]
    public function testCheckoutRejectsAMissingMethodAndKeepsTheForm(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $this->add();
        $response = $this->post($this->app, '/shop/checkout', $this->checkout(['payment_method' => '']));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('결제 수단을 선택해 주세요', $this->body($response));
    }

    #[DataProvider('connectionProvider')]
    public function testManualTransferOrderShowsTheAccountAndDeadline(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $this->add();
        $response = $this->post($this->app, '/shop/checkout', $this->checkout(['payment_method' => 'manual_transfer', 'depositor' => '홍길동']));
        self::assertStringContainsString('/shop/order?number=', $response->getHeaderLine('Location'));
        $page = $this->body($this->get($this->app, '/shop/order', ['number' => $this->numberFrom($response)]));
        self::assertStringContainsString('입금 안내', $page);
        self::assertStringContainsString('국민은행 123-45 (예금주 상점)', $page);
        self::assertStringContainsString('홍길동', $page);
        self::assertStringNotContainsString('결제하기', $page);
    }

    #[DataProvider('connectionProvider')]
    public function testConfirmedManualTransferOrderShowsPaymentCompleteAndHidesOtherNotices(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $this->add();
        $response = $this->post($this->app, '/shop/checkout', $this->checkout(['payment_method' => 'manual_transfer', 'depositor' => '홍길동']));
        $number = $this->numberFrom($response);
        $id = (int) $this->app->db()->selectOne('SELECT id FROM ' . $this->app->db()->table('yc_orders') . ' WHERE number = ?', [$number])['id'];
        $this->shop->orders->confirmDeposit($id, 'admin');
        $page = $this->body($this->get($this->app, '/shop/order', ['number' => $number]));
        self::assertStringContainsString('결제 완료', $page);
        self::assertStringContainsString('무통장입금', $page);
        self::assertStringNotContainsString('온라인 결제 내역이 없는 주문입니다', $page);
        self::assertStringNotContainsString('입금 안내', $page);
    }

    #[DataProvider('connectionProvider')]
    public function testCascadingOptionsSubmitCanonicalIdAndRecheckAvailability(array $config): void
    {
        $this->setupShop($config);
        $id = $this->shop->products->save(['code' => 'STEPS', 'name' => '단계별 옵션 상품',
            'category_id' => $this->product['category_id'], 'price' => '10000', 'active' => '1',
            'option_group' => [1 => '색상', 2 => '사이즈', 3 => '재질'], 'options' => [
                ['value1' => '0', 'value2' => 'S', 'value3' => '면', 'price' => '500', 'stock' => '3', 'active' => '1'],
                ['value1' => '0', 'value2' => 'S', 'value3' => '실크', 'price' => '1000', 'stock' => '0', 'active' => '1'],
                ['value1' => '0', 'value2' => 'M', 'value3' => '면', 'price' => '0', 'stock' => '5', 'active' => '0'],
            ]], []);
        $rows = $this->shop->products->get($id)['options']['select'];
        $body = $this->body($this->get($this->app, '/shop/item', ['id' => 'STEPS']));
        foreach (['색상', '사이즈', '재질'] as $label) {
            self::assertStringContainsString('aria-label="' . $label . '"', $body);
        }
        self::assertStringNotContainsString('단계 ', $body);
        self::assertStringNotContainsString('합산 재고', $body);
        self::assertSame(3, substr_count($body, 'required disabled'));
        self::assertStringContainsString('name="option_id" required data-yc-option', $body);
        self::assertStringContainsString('0 / S / 면 (+500원) · 재고 3개', $body);
        self::assertStringContainsString('data-stock="0" disabled>0 / M / 면 · 품절', $body);
        preg_match('/data-yc-options="([^"]+)"/', $body, $match);
        $json = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['색상', '사이즈', '재질'], $json['select']['groups']);
        self::assertSame(['id' => (int) $rows[0]['id'], 'v' => ['0', 'S', '면'], 'price' => 500, 'stock' => 3], $json['select']['items'][0]);
        self::assertSame(0, $json['select']['items'][2]['stock'], 'Inactive stock must not count toward parent availability');

        $input = ['product_id' => $id, 'quantity' => 1, 'option_step' => [1 => '0', 2 => 'S', 3 => '면']];
        foreach ([0, (int) $rows[1]['id'], (int) $rows[2]['id'], PHP_INT_MAX] as $unavailable) {
            self::assertSame(422, $this->post($this->app, '/shop/cart/add', $this->form($input + ['option_id' => $unavailable]))->getStatusCode());
            self::assertSame([], $_SESSION['yc_cart'] ?? []);
        }
        $input['option_id'] = (int) $rows[0]['id'];
        // Stock can change after the page was loaded; displayed step values cannot authorize a purchase.
        $this->shop->store->update('yc_options', $input['option_id'], ['stock' => 0]);
        self::assertSame(422, $this->post($this->app, '/shop/cart/add', $this->form($input))->getStatusCode());
        $this->shop->store->update('yc_options', $input['option_id'], ['stock' => 3]);
        self::assertSame(303, $this->post($this->app, '/shop/cart/add', $this->form($input + ['price' => 1]))->getStatusCode());
        self::assertSame(['product_id' => $id, 'option_id' => $input['option_id'], 'quantity' => 1], array_values($_SESSION['yc_cart'])[0]);
        $cart = $this->body($this->get($this->app, '/shop/cart'));
        self::assertStringContainsString('0 / S / 면', $cart);
        self::assertStringContainsString('10,500원', $cart);
        $order = $this->post($this->app, '/shop/checkout', $this->checkout());
        self::assertSame(303, $order->getStatusCode());
        self::assertStringContainsString('0 / S / 면', $this->body($this->get($this->app, $order->getHeaderLine('Location'))));
        self::assertSame(2, (int) $this->shop->store->get('yc_options', $input['option_id'])['stock']);
    }

    #[DataProvider('connectionProvider')]
    public function testMultipleSelectionsReachCartAndBuyCheckoutWithoutPartialUpdates(array $config): void
    {
        $this->setupShop($config); $this->add();
        $baseCart = $_SESSION['yc_cart'];
        $id = $this->shop->products->save(['code' => 'SHIRT', 'name' => '옵션 셔츠', 'category_id' => $this->product['category_id'],
            'price' => '10000', 'active' => '1', 'option_group' => [1 => '색상', 2 => '사이즈'], 'options' => [
                ['value1' => '화이트', 'value2' => 'S', 'price' => '0', 'stock' => '5'],
                ['value1' => '화이트', 'value2' => 'M', 'price' => '1000', 'stock' => '5'],
            ]], []);
        [$s, $m] = array_map('intval', array_column($this->shop->products->get($id)['options']['select'], 'id'));
        $input = $this->form(['product_id' => $id, 'selections' => [$s => '1', $m => '2']]);
        $response = $this->post($this->app, '/shop/cart/add', $input);
        self::assertSame('/shop/cart?added=1', $response->getHeaderLine('Location'));
        $cart = $_SESSION['yc_cart'];
        self::assertSame(1, $cart[$id . ':' . $s]['quantity']);
        self::assertSame(2, $cart[$id . ':' . $m]['quantity']);
        $cartPage = $this->body($this->get($this->app, '/shop/cart'));
        self::assertStringContainsString('화이트 / S', $cartPage);
        self::assertStringContainsString('화이트 / M', $cartPage);
        $buy = $this->post($this->app, '/shop/cart/add', $input + ['action' => 'buy']);
        self::assertSame('/shop/checkout?flow=buy', $buy->getHeaderLine('Location'));
        self::assertSame($cart, $_SESSION['yc_cart']);
        self::assertCount(2, $_SESSION['yc_buy']);
        $buyCart = $_SESSION['yc_buy'];
        $this->shop->store->update('yc_options', $m, ['stock' => 0]);
        foreach (['cart', 'buy'] as $flow) {
            $invalid = $this->post($this->app, '/shop/cart/add', $input + ['action' => $flow]);
            self::assertSame(422, $invalid->getStatusCode());
            self::assertSame($cart, $_SESSION['yc_cart']);
            self::assertSame($buyCart, $_SESSION['yc_buy']);
        }
        self::assertSame(5, (int) $this->shop->store->get('yc_options', $s)['stock']);
        $this->shop->store->update('yc_options', $m, ['stock' => 5]);
        $page = $this->body($this->get($this->app, '/shop/checkout', ['flow' => 'buy']));
        self::assertStringContainsString('화이트 / S', $page);
        self::assertStringContainsString('화이트 / M', $page);
        self::assertStringContainsString('32,000원', $page);
        preg_match('/name="checkout_token" value="([a-f0-9]{64})"/', $page, $match);
        $buyer = $this->checkout(['flow' => 'buy', 'checkout_token' => $match[1]]);
        $order = $this->post($this->app, '/shop/checkout', $buyer);
        self::assertSame(303, $order->getStatusCode());
        $detail = $this->body($this->get($this->app, $order->getHeaderLine('Location')));
        self::assertStringContainsString('화이트 / S', $detail);
        self::assertStringContainsString('화이트 / M', $detail);
        self::assertSame(4, (int) $this->shop->store->get('yc_options', $s)['stock']);
        self::assertSame(3, (int) $this->shop->store->get('yc_options', $m)['stock']);
        self::assertSame($cart, $_SESSION['yc_cart']);
        self::assertSame($baseCart[array_key_first($baseCart)], $cart[array_key_first($baseCart)]);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCartCheckoutDuplicateCancelAndLookup(array $config): void
    {
        $this->setupShop($config); $this->add(2);
        $cart = $this->body($this->get($this->app, '/shop/cart'));
        self::assertStringContainsString('24,000원', $cart); self::assertStringContainsString('주문서 작성', $cart);
        $input = $this->checkout();
        $response = $this->post($this->app, '/shop/checkout', $input);
        self::assertSame(303, $response->getStatusCode(), substr($this->body($response), 0, 500));
        $location = $response->getHeaderLine('Location');
        $page = $this->get($this->app, $location);
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        $body = $this->body($page);
        self::assertStringContainsString('주문이 접수되었어요', $body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString($input['password'], $body);
        self::assertSame([], $_SESSION['yc_cart']);
        self::assertSame($location, $this->post($this->app, '/shop/checkout', $input)->getHeaderLine('Location'));
        self::assertSame(1, $this->shop->orders->listing(null, '', 1, true)['total']);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        session_start(); $_SESSION['yc_guest_orders'] = []; session_write_close();
        self::assertSame(404, $this->get($this->app, $location)->getStatusCode());
        $lookup = $this->post($this->app, '/shop/orders', $this->form(['number' => $query['number'], 'email' => $input['email'], 'password' => $input['password']]));
        self::assertSame(303, $lookup->getStatusCode());
        $cancel = $this->post($this->app, '/shop/order/cancel', $this->form(['number' => $query['number']]));
        self::assertSame(303, $cancel->getStatusCode());
        self::assertStringContainsString('주문 취소', $this->body($this->get($this->app, $location)));
        self::assertSame(10, (int) $this->shop->products->get((int) $this->product['id'])['stock']);
    }

    #[DataProvider('connectionProvider')]
    public function testCsrfQuantityAndBuyerErrorsPreserveCartAndForm(array $config): void
    {
        $this->setupShop($config);
        $this->get($this->app, '/shop');
        self::assertSame(403, $this->post($this->app, '/shop/cart/add', ['product_id' => $this->product['id'], 'quantity' => 1])->getStatusCode());
        self::assertSame(405, $this->get($this->app, '/shop/cart/add')->getStatusCode());
        $this->add();
        $response = $this->post($this->app, '/shop/cart', $this->form(['quantities' => [$this->product['id'] . ':0' => '-3']]));
        self::assertSame(422, $response->getStatusCode()); self::assertSame(1, array_values($_SESSION['yc_cart'])[0]['quantity']);
        $input = $this->checkout(['email' => 'bad', 'agree' => '0']);
        $response = $this->post($this->app, '/shop/checkout', $input);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('value="테스트 수령인"', $this->body($response));
        self::assertStringNotContainsString('name="buyer_name"', $this->body($response));
        self::assertStringNotContainsString($input['password'], $this->body($response));
        self::assertSame(0, $this->shop->orders->listing(null, '', 1, true)['total']);
        self::assertSame(1, count($_SESSION['yc_cart']));
        $response = $this->post($this->app, '/shop/checkout', $this->form(['checkout_token' => str_repeat('0', 64)]));
        self::assertSame(403, $response->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testCartQuantitySaveRecalculatesTotalsAndChecksStock(array $config): void
    {
        $this->setupShop($config); $this->add();
        $key = $this->product['id'] . ':0';
        $response = $this->post($this->app, '/shop/cart', $this->form(['quantities' => [$key => '3']]));
        self::assertSame('/shop/cart?updated=1', $response->getHeaderLine('Location'));
        self::assertSame(3, $_SESSION['yc_cart'][$key]['quantity']);
        $body = $this->body($this->get($this->app, '/shop/cart'));
        self::assertStringContainsString('36,000원', $body);
        self::assertStringContainsString('data-yc-cart-minus', $body);
        self::assertStringContainsString('data-yc-cart-plus', $body);
        self::assertStringContainsString('주문서 작성', $body);

        $overStock = $this->post($this->app, '/shop/cart', $this->form(['quantities' => [$key => '11']]));
        self::assertSame(422, $overStock->getStatusCode());
        self::assertStringContainsString('구매 가능 수량: 10개', $this->body($overStock));
        self::assertSame(3, $_SESSION['yc_cart'][$key]['quantity']);
        $body = $this->body($this->get($this->app, '/shop/cart'));
        self::assertStringContainsString('36,000원', $body);
        self::assertSame(10, (int) $this->shop->products->get((int) $this->product['id'])['stock']);
        self::assertSame(303, $this->post($this->app, '/shop/cart', $this->form(['remove' => $key]))->getStatusCode());
        self::assertSame([], $_SESSION['yc_cart']);
    }

    #[DataProvider('connectionProvider')]
    public function testChangedPriceRequiresReviewAndBuyNowKeepsCart(array $config): void
    {
        $this->setupShop($config); $this->add();
        $input = $this->checkout();
        $this->shop->store->update('yc_products', (int) $this->product['id'], ['price' => 15000]);
        $response = $this->post($this->app, '/shop/checkout', $input);
        self::assertSame(422, $response->getStatusCode()); self::assertStringContainsString('변경되었습니다', $this->body($response));
        self::assertStringContainsString('15,000', $this->body($response));
        self::assertSame(10, (int) $this->shop->products->get((int) $this->product['id'])['stock']);
        $buy = $this->post($this->app, '/shop/cart/add', $this->form(['product_id' => $this->product['id'], 'quantity' => 2, 'action' => 'buy']));
        self::assertSame('/shop/checkout?flow=buy', $buy->getHeaderLine('Location'));
        $page = $this->body($this->get($this->app, '/shop/checkout', ['flow' => 'buy']));
        preg_match('/name="checkout_token" value="([a-f0-9]{64})"/', $page, $m);
        $input['checkout_token'] = $m[1]; $input['flow'] = 'buy';
        self::assertSame(303, $this->post($this->app, '/shop/checkout', $input)->getStatusCode());
        self::assertSame(1, array_values($_SESSION['yc_cart'])[0]['quantity']); self::assertSame([], $_SESSION['yc_buy']);
    }

    #[DataProvider('connectionProvider')]
    public function testCheckoutPrefillsOnlyCurrentMemberForCartAndBuy(array $config): void
    {
        $this->setupShop($config); $this->add();
        self::assertSame(303, $this->post($this->app, '/shop/cart/add', $this->form(['product_id' => $this->product['id'], 'quantity' => 1, 'action' => 'buy']))->getStatusCode());
        $first = $this->app->users()->create('first@example.test', '', '첫번째회원');
        $second = $this->app->users()->create('second@example.test', '', '두번째회원');
        $this->app->users()->updatePhone($first, '01011112222');
        $this->app->users()->updatePhone($second, '01033334444');
        foreach ([null, $first, $second] as $userId) {
            session_start();
            if ($userId === null) unset($_SESSION['user_id'], $_SESSION['session_epoch']);
            else { $_SESSION['user_id'] = $userId; $_SESSION['session_epoch'] = 0; }
            session_write_close();
            $name = $userId === null ? '' : ($userId === $first ? '첫번째회원' : '두번째회원');
            $email = $userId === null ? '' : ($userId === $first ? 'first@example.test' : 'second@example.test');
            foreach (['cart', 'buy'] as $flow) {
                $response = $this->get($this->app, '/shop/checkout', ['flow' => $flow, 'user_id' => (string) $first]);
                self::assertSame(200, $response->getStatusCode());
                self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
                $body = $this->body($response);
                if ($userId === null) {
                    self::assertStringNotContainsString('name="buyer_name"', $body);
                    self::assertStringNotContainsString('name="phone"', $body);
                    $this->assertCheckoutValues($body, ['email' => '', 'recipient' => '', 'recipient_phone' => '', 'postcode' => '', 'address' => '']);
                } else {
                    $phone = $userId === $first ? '01011112222' : '01033334444';
                    $this->assertCheckoutValues($body, ['buyer_name' => $name, 'email' => $email, 'recipient' => $name,
                        'phone' => $phone, 'recipient_phone' => $phone, 'postcode' => '', 'address' => '']);
                }
            }
        }
        foreach (['social@oauth.local', 'social@users.gnucms.charmgen.com'] as $index => $email) {
            $userId = $this->app->users()->create($email, '', '소셜회원' . $index);
            session_start(); $_SESSION['user_id'] = $userId; $_SESSION['session_epoch'] = 0; session_write_close();
            $this->assertCheckoutValues($this->body($this->get($this->app, '/shop/checkout')), [
                'buyer_name' => '소셜회원' . $index, 'recipient' => '소셜회원' . $index, 'email' => '',
            ]);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testCheckoutPostcodeSearchKeepsServerValidationAndEnteredAddress(array $config): void
    {
        $this->setupShop($config); $this->add();
        $body = $this->body($this->get($this->app, '/shop/checkout'));
        self::assertStringNotContainsString('name="buyer_name"', $body);
        self::assertStringNotContainsString('name="phone"', $body);
        self::assertStringContainsString('data-yc-postcode-search', $body);
        self::assertStringContainsString('youngcart-postcode.js', $body);
        self::assertStringNotContainsString('t1.kakaocdn.net', $body, 'The SDK is loaded on demand');
        $input = $this->checkout(['postcode' => '1234', 'address' => '테스트길 10 (건물 & 별관)', 'address_detail' => '202호']);
        $response = $this->post($this->app, '/shop/checkout', $input);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->body($response);
        $this->assertCheckoutValues($body, ['postcode' => '1234', 'address' => $input['address'], 'address_detail' => '202호']);
        self::assertStringContainsString('aria-describedby="yc-error-postcode"', $body);
        self::assertStringContainsString('우편번호 5자리', $body);
        $input['postcode'] = '04524';
        $response = $this->post($this->app, '/shop/checkout', $input);
        self::assertSame(303, $response->getStatusCode());
        $order = $this->body($this->get($this->app, $response->getHeaderLine('Location')));
        self::assertStringContainsString('04524', $order);
        self::assertStringContainsString('테스트길 10 (건물 &amp; 별관)', $order);
        self::assertStringContainsString('202호', $order);
        $placed = $this->shop->orders->listing(null, '', 1, true)['items'][0];
        self::assertSame($input['recipient'], $placed['buyer_name']);
        self::assertSame($input['recipient_phone'], $placed['phone']);
    }

    #[DataProvider('connectionProvider')]
    public function testCheckoutLocksMemberIdentityAndKeepsDeliveryEditsThroughRefreshAndOrder(array $config): void
    {
        $this->setupShop($config); $this->add();
        $userId = $this->app->users()->create('member@example.test', '', '가입한이름');
        $this->app->users()->updatePhone($userId, '01012345678');
        session_start(); $_SESSION['user_id'] = $userId; $_SESSION['session_epoch'] = 0; session_write_close();
        $input = $this->checkout(['buyer_name' => '다른 주문자', 'email' => 'delivery@example.test', 'recipient' => '', 'agree' => '0']);
        unset($input['password']);
        $invalid = $this->post($this->app, '/shop/checkout', $input);
        self::assertSame(422, $invalid->getStatusCode());
        $this->assertCheckoutValues($this->body($invalid), ['buyer_name' => '가입한이름', 'email' => 'member@example.test', 'phone' => '01012345678', 'recipient' => '']);
        $refresh = $this->post($this->app, '/shop/checkout', array_replace($input, ['action' => 'refresh', 'buyer_name' => '', 'email' => '']));
        self::assertSame(200, $refresh->getStatusCode());
        $this->assertCheckoutValues($this->body($refresh), ['buyer_name' => '가입한이름', 'email' => 'member@example.test', 'recipient' => '', 'phone' => '01012345678', 'address' => $input['address']]);
        $order = $this->post($this->app, '/shop/checkout', array_replace($input, ['recipient' => '선물 수령인', 'agree' => '1']));
        self::assertSame(303, $order->getStatusCode());
        $body = $this->body($this->get($this->app, $order->getHeaderLine('Location')));
        self::assertStringContainsString('가입한이름', $body);
        self::assertStringContainsString('member@example.test', $body);
        self::assertStringNotContainsString('delivery@example.test', $body);
        self::assertStringContainsString('선물 수령인', $body);
        $user = $this->app->users()->findById($userId);
        self::assertSame('가입한이름', $user['display_name']);
        self::assertSame('member@example.test', $user['email']);
    }

    #[DataProvider('connectionProvider')]
    public function testOrderLinksUseOpaqueReferencesAndStillCheckTheMember(array $config): void
    {
        $this->setupShop($config); $this->add();
        $owner = $this->app->users()->create('link-owner@example.test', '', '구매회원');
        $other = $this->app->users()->create('link-other@example.test', '', '다른회원');
        session_start(); $_SESSION['user_id'] = $owner; $_SESSION['session_epoch'] = 0; session_write_close();
        $input = $this->checkout();
        unset($input['password']);
        $placed = $this->post($this->app, '/shop/checkout', $input);
        self::assertSame(303, $placed->getStatusCode());
        parse_str((string) parse_url($placed->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $reference = (string) ($query['ref'] ?? '');
        $order = $this->shop->orders->listing($owner)['items'][0];
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $reference);
        self::assertSame($order['checkout_key'], $reference);
        self::assertStringNotContainsString($order['number'], $reference);
        self::assertArrayNotHasKey('number', $query);
        $detail = $this->get($this->app, '/shop/order', ['ref' => $reference]);
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame('no-referrer', $detail->getHeaderLine('Referrer-Policy'));
        self::assertStringContainsString('/shop/order?ref=' . $reference, $this->body($this->get($this->app, '/shop/orders')));
        $invalid = substr($reference, 0, -1) . (str_ends_with($reference, 'a') ? 'b' : 'a');
        self::assertSame(404, $this->get($this->app, '/shop/order', ['ref' => $invalid])->getStatusCode());
        self::assertSame('/shop/order?ref=' . $reference,
            $this->get($this->app, '/shop/order', ['number' => $order['number']])->getHeaderLine('Location'));
        $legacyReference = $order['number'] . '-' . substr(hash_hmac('sha256', 'shop-order-url:' . $order['number'], $order['checkout_key']), 0, 32);
        $legacy = $this->get($this->app, '/shop/order', ['ref' => $legacyReference]);
        self::assertSame(303, $legacy->getStatusCode());
        self::assertSame('/shop/order?ref=' . $reference, $legacy->getHeaderLine('Location'));
        session_start(); $_SESSION['user_id'] = $other; $_SESSION['session_epoch'] = 0; session_write_close();
        self::assertSame(404, $this->get($this->app, '/shop/order', ['ref' => $reference])->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testCheckoutPrefillsAddressFromOrdersAndPreservesDefaultForOneOffDelivery(array $config): void
    {
        $this->setupShop($config); $this->add();
        $userId = $this->app->users()->create('buyer@example.test', '', '구매회원');
        $this->app->users()->updatePhone($userId, '01011112222');
        session_start(); $_SESSION['user_id'] = $userId; $_SESSION['session_epoch'] = 0; session_write_close();
        $first = $this->checkout(['recipient' => '기본 수령인', 'recipient_phone' => '01022223333', 'postcode' => '04524',
            'address' => '기본길 1', 'address_detail' => '101호', 'save_default_address' => '1']);
        unset($first['password']);
        self::assertSame(303, $this->post($this->app, '/shop/checkout', $first)->getStatusCode());
        $this->add();
        $page = $this->body($this->get($this->app, '/shop/checkout'));
        $this->assertCheckoutValues($page, ['buyer_name' => '구매회원', 'phone' => '01011112222', 'email' => 'buyer@example.test',
            'recipient' => '기본 수령인', 'recipient_phone' => '01022223333', 'postcode' => '04524', 'address' => '기본길 1', 'address_detail' => '101호']);
        self::assertStringContainsString('yc-buyer-readonly', $page);
        $input = $this->checkout(['recipient' => '임시 수령인', 'recipient_phone' => '01099998888', 'postcode' => '06236', 'address' => '임시길 2']);
        unset($input['password']);
        self::assertSame(303, $this->post($this->app, '/shop/checkout', $input)->getStatusCode());
        self::assertSame('기본 수령인', $this->shop->orders->defaultAddressFor($userId)['recipient']);
        $this->add();
        $input = $this->checkout(['recipient' => '새 기본 수령인', 'save_default_address' => '1']);
        unset($input['password']);
        self::assertSame(303, $this->post($this->app, '/shop/checkout', $input)->getStatusCode());
        self::assertSame('새 기본 수령인', $this->shop->orders->defaultAddressFor($userId)['recipient']);
        self::assertSame(1, (int) $this->shop->store->selectOne('SELECT COUNT(*) AS c FROM ' . $this->shop->store->table('yc_orders') . ' WHERE user_id = ? AND default_address = 1', [$userId])['c']);
        $firstOrder = $this->shop->store->selectOne('SELECT recipient, address, default_address FROM ' . $this->shop->store->table('yc_orders') . ' WHERE user_id = ? ORDER BY id ASC LIMIT 1', [$userId]);
        self::assertSame('기본 수령인', $firstOrder['recipient']);
        self::assertSame('기본길 1', $firstOrder['address']);
        self::assertSame(0, (int) $firstOrder['default_address']);
    }

    private function assertCheckoutValues(string $body, array $expected): void
    {
        foreach ($expected as $name => $value) {
            self::assertMatchesRegularExpression('/name="' . preg_quote($name, '/') . '"[^>]*value="'
                . preg_quote(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/') . '"/', $body);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testMemberAndAdminRoutesStatusGuardsAndSubdirectory(array $config): void
    {
        $this->setupShop($config);
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop/orders'), '/admin/shop/orders');
        $user = $this->app->users()->create('member@example.test', '', '회원');
        $admin = $this->app->users()->create('admin@example.test', '', '관리자', true);
        $this->get($this->app, '/login'); session_start(); $_SESSION['user_id'] = $user; $_SESSION['session_epoch'] = 0; session_write_close();
        $this->add(); $input = $this->checkout(); unset($input['password']);
        $response = $this->post($this->app, '/shop/checkout', $input);
        self::assertSame(303, $response->getStatusCode());
        $orders = $this->shop->orders->listing((int) $user); $order = $orders['items'][0];
        self::assertStringContainsString($order['number'], $this->body($this->get($this->app, '/shop/orders')));
        self::assertSame(403, $this->get($this->app, '/admin/shop/orders')->getStatusCode());
        session_start(); $_SESSION['user_id'] = $admin; session_write_close();
        self::assertSame(404, $this->get($this->app, $response->getHeaderLine('Location'))->getStatusCode(), '관리자도 공개 주소에서 다른 회원 주문을 열 수 없음');
        $list = $this->get($this->app, '/admin/shop/orders');
        self::assertSame(200, $list->getStatusCode()); self::assertStringContainsString('youngcart.css', $this->body($list));
        $dashboard = $this->get($this->app, '/admin/shop');
        self::assertSame('no-store', $dashboard->getHeaderLine('Cache-Control'));
        self::assertStringContainsString($order['number'], $this->body($dashboard));
        self::assertStringContainsString('/orders/detail?id=' . $order['id'], $this->body($dashboard));
        self::assertStringNotContainsString($order['number'], $this->body($this->get($this->app, '/admin/shop/orders', ['status' => 'shipped'])));
        self::assertSame(200, $this->get($this->app, '/admin/shop/orders/detail', ['id' => $order['id']])->getStatusCode());
        self::assertSame(403, $this->post($this->app, '/admin/shop/orders/detail', ['id' => $order['id'], 'from' => 'pending', 'status' => 'confirmed'])->getStatusCode());
        // Validation must retain the selected action instead of silently reverting to the first option.
        $invalid = $this->post($this->app, '/admin/shop/orders/detail', $this->form(['id' => $order['id'], 'from' => 'pending', 'status' => 'cancelled', 'note' => str_repeat('x', 501)]));
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('<option value="cancelled" selected>', $this->body($invalid));
        self::assertSame('pending', $this->shop->orders->get((int) $order['id'])['status']);
        $this->shop->orders->confirmDeposit((int) $order['id'], 'admin');
        $status = $this->form(['id' => $order['id'], 'from' => 'paid', 'status' => 'confirmed']);
        self::assertSame(303, $this->post($this->app, '/admin/shop/orders/detail', $status)->getStatusCode());
        self::assertSame(422, $this->post($this->app, '/admin/shop/orders/detail', $status)->getStatusCode());
        $sub = Kernel::create($this->app, dirname(__DIR__, 2) . '/templates', '/cms')->handle((new ServerRequestFactory())->createServerRequest('GET', '/cms/shop/cart'));
        self::assertStringContainsString('href="/cms/shop/orders"', $this->body($sub));
        self::assertStringContainsString('action="/cms/shop/search"', $this->body($sub));
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($this->root);
        }
    }
}
