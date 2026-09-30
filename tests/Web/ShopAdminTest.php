<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Payment\InicisGateway;
use GnuCms\Payment\KcpConfig;
use GnuCms\Shop\Catalog\Categories;
use GnuCms\Shop\Service;
use GnuCms\Shop\Settings;
use GnuCms\Tests\Payment\FakeTransport;
use GnuCms\Tests\Payment\Fixtures;
use GnuCms\Tests\Shop\ImagesTest;
use GnuCms\Tests\Support\WebTestCase;
use GnuCms\Web\Kernel;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;

final class ShopAdminTest extends WebTestCase
{
    private App $app;
    private Service $shop;
    private string $root;
    private FakeTransport $http;
    private array $payConfig;

    private function setupShop(array $config): void
    {
        session_name(GNUCMS_ID . '_session');
        session_start(); $_SESSION = []; session_write_close();
        $this->root = sys_get_temp_dir() . '/gnucms-yc-admin-' . bin2hex(random_bytes(8));
        $config['prefix'] = 'ya' . bin2hex(random_bytes(4)) . '_';
        $this->app = $this->makeApp($config, ['storage' => ['dir' => $this->root], 'uploads' => ['dir' => $this->root . '/uploads'], 'editor' => ['dir' => $this->root . '/editor'],
            'app' => ['url' => 'https://shop.example.test']]);
        $this->shop = new Service($this->app);
    }

    private function signIn(bool $admin): int
    {
        $id = $this->app->users()->create(bin2hex(random_bytes(4)) . '@example.test', '', ($admin ? '운영자' : '회원') . bin2hex(random_bytes(3)), $admin);
        $this->get($this->app, '/login');
        session_start(); $_SESSION['user_id'] = $id; $_SESSION['session_epoch'] = 0; session_write_close();
        return (int) $id;
    }

    private function csrf(array $body = []): array { return $body + ['csrf_token' => $_SESSION['csrf_token']]; }

    /** 이니시스 테스트 환경을 켜고 쇼핑몰이 그 환경을 쓰게 한다. 무통장 계좌도 켠다. */
    private function enablePayments(): void
    {
        $this->payConfig = Fixtures::config();
        $this->app->paymentSettings()->save('test', $this->payConfig);
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

    private function placeManualOrder(): array { return $this->placeOrder('manual_transfer', ['depositor' => '홍길동']); }

    private function placeOrder(string $method, array $extra = []): array
    {
        $productId = $this->shop->products->save(['code' => 'PAY' . bin2hex(random_bytes(2)), 'name' => '결제 상품', 'category_id' => (string) $this->shop->categories->save(['name' => '결제', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']), 'price' => '5000', 'stock' => '5', 'active' => '1'], []);
        $cart = $this->shop->cart->add([], ['product_id' => $productId, 'quantity' => 1]);
        $input = $extra + ['buyer_name' => '입금자', 'email' => 'buyer@example.test', 'phone' => '010-0000-0000', 'recipient' => '받는 분', 'recipient_phone' => '010-0000-0000',
            'postcode' => '04524', 'address' => '주소', 'address_detail' => '', 'delivery_note' => '', 'password' => bin2hex(random_bytes(12)), 'agree' => '1',
            'payment_method' => $method];
        $member = $this->app->users()->create(bin2hex(random_bytes(8)) . '@example.test', '', '구매 회원');
        return $this->shop->orders->place($cart, $input, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), $member,
            $this->shop->cart->quote($cart, [], true)['fingerprint'], [], $this->shop->payments->forPlacing($input));
    }

    /** 카드 주문을 결제창 → 승인 → 조회까지 밀어 결제 완료로 만든다. */
    private function payCardOrder(array $order): array
    {
        $this->shop->payments->checkout($order, 'web', 'https://shop.example.test/shop/order', 'https://shop.example.test/shop/pay/callback');
        $tid = 'StdpayCARD' . bin2hex(random_bytes(6));
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => '0000', 'mid' => $this->payConfig['merchant_id'], 'MOID' => $order['payment_id'],
            'TotPrice' => (string) $order['total'], 'payMethod' => 'Card', 'tid' => $tid, 'currency' => 'WON']];
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => 'SUCCESS', 'transactionStatus' => 'APPROVAL', 'mid' => $this->payConfig['merchant_id'],
            'oid' => $order['payment_id'], 'price' => (string) $order['total'], 'tid' => $tid, 'paymethod' => 'Card', 'approvedDate' => '20260920', 'approvedTime' => '120000',
            'cardInfo' => ['currencyCode' => 'WON'], 'partCancelTransInfo' => []]];
        return $this->shop->payments->complete($order, ['resultCode' => '0000', 'mid' => $this->payConfig['merchant_id'], 'orderNumber' => $order['payment_id'],
            'idc_name' => 'stg', 'authToken' => bin2hex(random_bytes(32)), 'authUrl' => 'https://stgstdpay.inicis.com/api/payAuth',
            'netCancelUrl' => 'https://stgstdpay.inicis.com/api/netCancel']);
    }

    #[DataProvider('connectionProvider')]
    public function testKcpRestCannotBeChosenForNewOrdersButOldSettingsCanBeReplaced(array $config): void
    {
        $this->setupShop($config);
        $this->signIn(true);
        $page = $this->body($this->get($this->app, '/admin/shop/settings'));
        self::assertStringNotContainsString('<option value="kcp"', $page);
        self::assertStringContainsString('<option value="kcp_legacy"', $page);

        $rejected = $this->post($this->app, '/admin/shop/settings', $this->csrf($this->settingsForm(['payment_provider' => 'kcp'])));
        self::assertSame(422, $rejected->getStatusCode());
        self::assertSame('inicis', $this->shop->settings->all()['payment']['provider']);

        $this->app->paymentSettings('kcp')->save('test', KcpConfig::testCredentials());
        $settings = $this->shop->settings->all();
        $settings['payment']['provider'] = 'kcp';
        $settings['payment']['environment'] = 'test';
        $this->app->db()->insert('yc_settings', ['id' => 'settings', 'payload' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        self::assertTrue($this->app->paymentSettings('kcp')->available('test'));
        self::assertArrayNotHasKey('card', $this->shop->payments->methods());

        $page = $this->body($this->get($this->app, '/admin/shop/settings'));
        self::assertStringContainsString('<option value="" selected disabled>다른 결제사를 선택해 주세요</option>', $page);
        self::assertStringNotContainsString('<option value="kcp"', $page);
        $changed = $this->post($this->app, '/admin/shop/settings', $this->csrf($this->settingsForm(['payment_provider' => 'nicepay', 'payment_environment' => 'test'])));
        self::assertSame(303, $changed->getStatusCode());
        self::assertSame('nicepay', $this->shop->settings->all()['payment']['provider']);
    }

    #[DataProvider('connectionProvider')]
    public function testAdminConfirmsADepositAndSeesThePaymentPanel(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeManualOrder();
        $this->signIn(true);
        $page = $this->body($this->get($this->app, '/admin/shop/orders/detail', ['id' => $order['id']]));
        self::assertStringContainsString('무통장입금', $page);
        self::assertStringContainsString('홍길동', $page);
        self::assertStringContainsString('value="confirm-deposit"', $page);
        self::assertStringNotContainsString('<option value="paid"', $page);

        $response = $this->post($this->app, '/admin/shop/orders/detail', $this->csrf(['id' => $order['id'], 'action' => 'confirm-deposit']));
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('paid', $this->shop->orders->get((int) $order['id'])['status']);
        $page = $this->body($this->get($this->app, '/admin/shop/orders/detail', ['id' => $order['id'], 'saved' => 'confirm-deposit']));
        self::assertStringContainsString('입금을 확인했습니다', $page);
        self::assertStringContainsString('결제 완료', $page);
        self::assertStringNotContainsString('value="confirm-deposit"', $page);
    }

    #[DataProvider('connectionProvider')]
    public function testAdminRefundsAndCancelsAPaidManualOrder(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeManualOrder();
        $this->shop->orders->confirmDeposit((int) $order['id'], 'admin');
        $this->signIn(true);
        $page = $this->body($this->get($this->app, '/admin/shop/orders/detail', ['id' => $order['id']]));
        preg_match('/name="refund_key" value="([a-f0-9]{32})"/', $page, $m);
        self::assertArrayHasKey(1, $m, '환불 폼이 있어야 한다');

        $response = $this->post($this->app, '/admin/shop/orders/detail', $this->csrf(['id' => $order['id'], 'action' => 'refund', 'amount' => (string) $order['total'], 'reason' => '고객 요청', 'refund_key' => $m[1], 'cancel_order' => '1']));
        self::assertSame(303, $response->getStatusCode(), $this->body($response));
        $after = $this->shop->orders->get((int) $order['id']);
        self::assertSame((int) $order['total'], (int) $after['refunded_amount']);
        self::assertSame('cancelled', $after['status']);
        self::assertStringContainsString('환불을 처리했습니다', $this->body($this->get($this->app, '/admin/shop/orders/detail', ['id' => $order['id'], 'saved' => 'refund'])));
    }

    /** 같은 환불 폼이 두 번 도착해도(이중 제출·새로고침) 금액은 한 번만 빠진다. */
    #[DataProvider('connectionProvider')]
    public function testTheSameRefundFormPostedTwiceRefundsOnce(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeManualOrder();
        $this->shop->orders->confirmDeposit((int) $order['id'], 'admin');
        $this->signIn(true);
        $form = $this->csrf(['id' => $order['id'], 'action' => 'refund', 'amount' => '2000', 'reason' => '고객 요청', 'refund_key' => bin2hex(random_bytes(16))]);
        self::assertSame(303, $this->post($this->app, '/admin/shop/orders/detail', $form)->getStatusCode());
        self::assertSame(303, $this->post($this->app, '/admin/shop/orders/detail', $form)->getStatusCode());
        self::assertSame(2000, (int) $this->shop->orders->get((int) $order['id'])['refunded_amount']);
    }

    #[DataProvider('connectionProvider')]
    public function testRefundFormValidatesAmountAndReason(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeManualOrder();
        $this->shop->orders->confirmDeposit((int) $order['id'], 'admin');
        $this->signIn(true);
        $response = $this->post($this->app, '/admin/shop/orders/detail', $this->csrf(['id' => $order['id'], 'action' => 'refund', 'amount' => '0', 'reason' => '', 'refund_key' => bin2hex(random_bytes(16))]));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, (int) $this->shop->orders->get((int) $order['id'])['refunded_amount']);
    }

    /** 환불 응답을 받지 못한 요청은 관리자 화면에 남고, 결제사 취소 거래번호로 대조해 정리한다. */
    #[DataProvider('connectionProvider')]
    public function testAdminMatchesARefundWhosePgResponseWasLost(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $paid = $this->payCardOrder($this->placeOrder('card'));
        $key = bin2hex(random_bytes(16));
        $this->http->responses[] = new \RuntimeException('timeout');
        try { $this->shop->payments->refund($paid, 2000, '고객 요청', $key, 'admin'); self::fail('환불 응답을 받지 못했다'); }
        catch (\RuntimeException) {}
        $this->signIn(true);
        $page = $this->body($this->get($this->app, '/admin/shop/orders/detail', ['id' => $paid['id']]));
        self::assertStringContainsString('결과를 확인하지 못한 환불 요청', $page);
        self::assertStringContainsString('2,000원', $page);
        self::assertStringContainsString('고객 요청', $page);
        self::assertStringContainsString('value="refund-confirm"', $page);
        self::assertStringContainsString('value="refund-unprocessed"', $page);

        $reference = 'StdpayCANCEL' . bin2hex(random_bytes(6));
        $this->http->responses[] = ['status' => 200, 'body' => ['resultCode' => 'SUCCESS', 'transactionStatus' => 'PART_CANCEL', 'mid' => $this->payConfig['merchant_id'],
            'oid' => $paid['payment_id'], 'price' => (string) $paid['total'], 'tid' => $paid['payment']['tid'], 'paymethod' => 'Card',
            'approvedDate' => '20260920', 'approvedTime' => '120000', 'cardInfo' => ['currencyCode' => 'WON'],
            'availablePartCancelPrice' => (string) ((int) $paid['total'] - 2000),
            'partCancelTransInfo' => [['tid' => $reference, 'requestPrice' => '2000', 'requestDate' => '20260921', 'requestTime' => '090000']]]];
        $response = $this->post($this->app, '/admin/shop/orders/detail', $this->csrf(['id' => $paid['id'], 'action' => 'refund-confirm', 'refund_key' => $key, 'reference' => $reference]));
        self::assertSame(303, $response->getStatusCode(), $this->body($response));
        self::assertSame(2000, (int) $this->shop->orders->get((int) $paid['id'])['refunded_amount']);
        $page = $this->body($this->get($this->app, '/admin/shop/orders/detail', ['id' => $paid['id'], 'saved' => 'refund-confirm']));
        self::assertStringContainsString('환불을 결제사 기록과 맞췄습니다', $page);
        self::assertStringNotContainsString('결과를 확인하지 못한 환불 요청', $page);
    }

    #[DataProvider('connectionProvider')]
    public function testReceiptOnlyPaidOrderCanBeRefunded(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $productId = $this->shop->products->save(['code' => 'PAY' . bin2hex(random_bytes(2)), 'name' => '결제 상품', 'category_id' => (string) $this->shop->categories->save(['name' => '결제', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']), 'price' => '5000', 'stock' => '5', 'active' => '1'], []);
        $cart = $this->shop->cart->add([], ['product_id' => $productId, 'quantity' => 1]);
        $input = ['buyer_name' => '입금자', 'email' => 'buyer@example.test', 'phone' => '010-0000-0000', 'recipient' => '받는 분', 'recipient_phone' => '010-0000-0000',
            'postcode' => '04524', 'address' => '주소', 'address_detail' => '', 'delivery_note' => '', 'password' => bin2hex(random_bytes(12)), 'agree' => '1'];
        $member = $this->app->users()->create(bin2hex(random_bytes(8)) . '@example.test', '', '구매 회원');
        $order = $this->shop->orders->place($cart, $input, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), $member,
            $this->shop->cart->quote($cart, [], true)['fingerprint'], []);
        self::assertSame('', $order['payment_method']);
        $this->shop->orders->confirmDeposit((int) $order['id'], 'admin');
        $this->signIn(true);
        $page = $this->body($this->get($this->app, '/admin/shop/orders/detail', ['id' => $order['id']]));
        self::assertStringContainsString('name="refund_key"', $page);
    }

    /** 결제 대기가 아닌 주문에 결제사 승인이 남아 있으면 관리자 화면이 환불 필요를 알린다. */
    #[DataProvider('connectionProvider')]
    public function testOrphanApprovalWarnsTheAdminToRefund(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeManualOrder();
        $this->shop->orders->transition((int) $order['id'], 'pending', 'cancelled', 'member', ['cancel_reason' => 'change_mind'], true);
        $this->shop->orders->recordOrphanApproval((int) $order['id'], 'pg:inicis', 'StdpayCARD0001', '카드 결제');
        $this->signIn(true);
        $page = $this->body($this->get($this->app, '/admin/shop/orders/detail', ['id' => $order['id']]));
        self::assertStringContainsString('환불이 필요합니다', $page);
        self::assertStringContainsString('결제사에서 환불한 뒤 처리 메모를 남겨 주세요', $page);
        self::assertStringContainsString('StdpayCARD0001', $page);
    }

    #[DataProvider('connectionProvider')]
    public function testOrderListShowsTheMethodAndFiltersPaid(array $config): void
    {
        $this->setupShop($config); $this->enablePayments();
        $order = $this->placeManualOrder();
        $this->shop->orders->confirmDeposit((int) $order['id'], 'admin');
        $this->signIn(true);
        $list = $this->body($this->get($this->app, '/admin/shop/orders', ['status' => 'paid']));
        self::assertStringContainsString($order['number'], $list);
        self::assertStringContainsString('무통장입금', $list);
        self::assertStringNotContainsString('주문 상태는 결제 완료를 의미하지 않습니다', $list);
    }

    #[DataProvider('connectionProvider')]
    public function testGuardsSettings(array $config): void
    {
        $this->setupShop($config);
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop'), '/admin/shop');
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop/settings'), '/admin/shop/settings');
        $this->signIn(false);
        self::assertSame(403, $this->get($this->app, '/admin/shop')->getStatusCode());
        $this->signIn(true);
        self::assertStringContainsString('상품 0', $this->body($this->get($this->app, '/admin/shop')));
        $settings = $this->body($this->get($this->app, '/admin/shop/settings'));
        self::assertStringNotContainsString('main_hit_use', $settings);
        // 메인 진열: 자동 묶음 기간, 묶음마다 기준(자동·분류), 메인 분류 블록 줄을 늘리는 틀.
        self::assertStringContainsString('name="auto_new_days"', $settings);
        self::assertStringContainsString('name="auto_best_days"', $settings);
        self::assertStringContainsString('name="main_best_source"', $settings);
        self::assertStringContainsString('name="main_best_source_category_id"', $settings);
        self::assertStringContainsString('<template data-yc-main-category-row>', $settings);
        self::assertStringContainsString('data-yc-add-main-category', $settings);
        self::assertStringContainsString('name="category_columns" value="4"', $settings);
        // 토큰이 없는 POST 는 전역 관리자라도 지나지 못한다.
        self::assertSame(403, $this->post($this->app, '/admin/shop/settings', $this->settingsForm(['category_columns' => '5']))->getStatusCode());
        self::assertSame(4, $this->shop->settings->all()['category']['columns'], '토큰 없는 저장은 기본값 4를 바꾸지 못한다');
        $response = $this->post($this->app, '/admin/shop/settings', $this->csrf($this->settingsForm(['category_columns' => '13'])));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('1~12', $this->body($response));
        self::assertSame(4, $this->shop->settings->all()['category']['columns']);
        $response = $this->post($this->app, '/admin/shop/settings', $this->csrf($this->settingsForm(['category_columns' => '5', 'show_tax' => '1'])));
        self::assertSame('/admin/shop/settings?saved=1', $response->getHeaderLine('Location'));
        self::assertSame(5, $this->shop->settings->all()['category']['columns']);
        self::assertTrue($this->shop->settings->all()['show_tax']);
    }

    #[DataProvider('connectionProvider')]
    public function testShopSettingsShowsSavedLiveInicisWithoutExposingKeys(array $config): void
    {
        $this->setupShop($config);
        $this->signIn(true);
        $page = $this->body($this->get($this->app, '/admin/shop/settings'));
        self::assertStringContainsString('id="yc-inicis-live-merchant_id"', $page);
        self::assertStringContainsString('name="payment_credentials[inicis][live][merchant_id]"', $page);
        self::assertStringContainsString('id="yc-inicis-live-hash_key" type="password"', $page);
        self::assertStringContainsString('name="payment_environment" value="live"', $page);

        $credentials = Fixtures::config('inicis');
        unset($credentials['sign_key']); // 신규 PayPro 운영 상점에는 기존 웹표준 SignKey가 필요 없다.
        $saved = $this->post($this->app, '/admin/settings/payment', $this->csrf($credentials +
            ['provider' => 'inicis', 'environment' => 'live', 'return_to' => 'shop', 'action' => 'save']));
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/admin/shop/settings?payment_saved=1#settings-payment', $saved->getHeaderLine('Location'));
        self::assertTrue($this->app->paymentSettings()->available('live'));
        self::assertSame('', $this->app->paymentSettings()->current('live')['sign_key']);
        $page = $this->body($this->get($this->app, '/admin/shop/settings'));
        self::assertStringContainsString('value="' . $credentials['merchant_id'] . '"', $page);
        self::assertStringNotContainsString($credentials['hash_key'], $page);

    }

    private function settingsForm(array $overrides): array
    {
        $form = [];
        foreach (Settings::TYPES as $type) {
            $form += ['main_' . $type . '_use' => '1', 'main_' . $type . '_columns' => '4', 'main_' . $type . '_rows' => '1', 'main_' . $type . '_image_width' => '200',
                'main_' . $type . '_image_height' => '0', 'main_' . $type . '_source' => 'auto', 'main_' . $type . '_source_category_id' => ''];
        }
        foreach (['category', 'type', 'search'] as $section) {
            $form += [$section . '_columns' => '3', $section . '_rows' => '5', $section . '_image_width' => '200', $section . '_image_height' => '0'];
        }
        return $overrides + $form + ['auto_new_days' => '30', 'auto_best_days' => '30',
            'detail_image_width' => '400', 'detail_image_height' => '0', 'shipping_content' => '', 'exchange_content' => ''];
    }

    /** 메인 진열 설정: 자동 묶음 기간, 묶음의 기준(분류 선택), 메인 분류 블록을 저장하고 다시 연 화면에 되비친다. */
    #[DataProvider('connectionProvider')]
    public function testSettingsSavesCollectionSourcesAndMainCategoryBlocks(array $config): void
    {
        $this->setupShop($config);
        $this->signIn(true);
        $event = (int) $this->shop->categories->save(['name' => '기획전', 'parent_id' => '', 'active' => '1', 'menu_hidden' => '1',
            'list_columns' => '4', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']);
        $response = $this->post($this->app, '/admin/shop/settings', $this->csrf($this->settingsForm(['auto_best_days' => '60',
            'main_best_source' => 'category', 'main_best_source_category_id' => (string) $event,
            'main_categories' => [['id' => (string) $event, 'columns' => '3', 'rows' => '1']]])));
        self::assertSame(303, $response->getStatusCode());
        $all = $this->shop->settings->all();
        self::assertSame(['new_days' => 30, 'best_days' => 60], $all['auto']);
        self::assertSame(['category', $event], [$all['main']['best']['source'], $all['main']['best']['source_category_id']]);
        self::assertSame('auto', $all['main']['new']['source']);
        self::assertSame([['id' => $event, 'columns' => 3, 'rows' => 1]], $all['main']['categories']);
        $settings = $this->body($this->get($this->app, '/admin/shop/settings'));
        self::assertStringContainsString('name="auto_best_days" value="60"', $settings);
        self::assertStringContainsString('<option value="category" selected>', $settings);
        self::assertStringContainsString('name="main_categories[0][id]"', $settings);
        self::assertStringContainsString('value="' . $event . '" title="슬러그 기획전 · 번호 ' . $event . '" selected', $settings);
        self::assertStringContainsString('name="main_categories[0][columns]" value="3"', $settings);
        // 없는 분류는 422 로 돌려보내고 저장된 목록은 그대로다.
        $bad = $this->post($this->app, '/admin/shop/settings', $this->csrf($this->settingsForm(['main_categories' => [['id' => '999999', 'columns' => '3', 'rows' => '1']]])));
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('없는 분류', $this->body($bad));
        self::assertSame([['id' => $event, 'columns' => 3, 'rows' => 1]], $this->shop->settings->all()['main']['categories']);
    }

    #[DataProvider('connectionProvider')]
    public function testMainBannerEditorAndUploadGuards(array $config): void
    {
        $this->setupShop($config);
        $this->signIn(false);
        self::assertSame(403, $this->postWithFiles($this->app, '/admin/shop/settings', $this->csrf($this->settingsForm(['banner_mode' => 'upload'])), ['banner_image' => ImagesTest::png(20, 20)])->getStatusCode());
        $this->signIn(true);
        $form = $this->body($this->get($this->app, '/admin/shop/settings'));
        self::assertStringContainsString('href="#settings-banner"', $form);
        self::assertStringContainsString('enctype="multipart/form-data"', $form);
        self::assertStringContainsString('name="banner_title"', $form);
        self::assertStringContainsString('name="banner_image"', $form);
        self::assertStringContainsString('<option value="random">메인 진열 상품 랜덤 표시</option>', $form);
        $random = $this->post($this->app, '/admin/shop/settings', $this->csrf($this->settingsForm(['banner_mode' => 'random'])));
        self::assertSame(303, $random->getStatusCode());
        self::assertSame('random', $this->shop->settings->all()['banner']['mode']);
        self::assertStringContainsString('<option value="random" selected>', $this->body($this->get($this->app, '/admin/shop/settings')));
        $input = $this->settingsForm(['banner_use' => '1', 'banner_mode' => 'upload', 'banner_title' => "계절 상품\n<script>bad</script>",
            'banner_description' => '이번 주 추천', 'banner_button_label' => '보러 가기', 'banner_button_url' => '/shop/type?t=new',
            'banner_image_alt' => '가을 이미지', 'banner_image_caption' => '가을 추천', 'banner_image_url' => 'https://example.test/autumn']);
        self::assertSame(403, $this->postWithFiles($this->app, '/admin/shop/settings', $input, ['banner_image' => ImagesTest::png(20, 20)])->getStatusCode());
        self::assertSame('', $this->shop->settings->all()['banner']['image']);
        $saved = $this->postWithFiles($this->app, '/admin/shop/settings', $this->csrf($input), ['banner_image' => ImagesTest::png(100, 100)]);
        self::assertSame('/admin/shop/settings?saved=1', $saved->getHeaderLine('Location'));
        $filename = $this->shop->settings->all()['banner']['image'];
        $form = $this->body($this->get($this->app, '/admin/shop/settings'));
        self::assertStringContainsString('/shop/banner-image?f=' . $filename, $form);
        self::assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', $form);
        $home = $this->body($this->get($this->app, '/shop'));
        self::assertStringContainsString("계절 상품<br>\n&lt;script&gt;bad&lt;/script&gt;", $home);
        self::assertStringNotContainsString('<script>bad</script>', $home);
        self::assertStringContainsString('alt="가을 이미지"', $home);
        self::assertStringContainsString('href="https://example.test/autumn"', $home);
        $error = $this->postWithFiles($this->app, '/admin/shop/settings', $this->csrf(['banner_button_url' => 'javascript:alert(1)'] + $input), ['banner_image' => ImagesTest::png(80, 80)]);
        self::assertSame(422, $error->getStatusCode());
        self::assertStringContainsString('이미지를 다시 선택', $this->body($error));
        self::assertSame($filename, $this->shop->settings->all()['banner']['image']);
        self::assertSame(422, $this->post($this->app, '/admin/shop/settings', $this->csrf(['banner_title' => ['bad']] + $input))->getStatusCode());
        $hidden = $this->post($this->app, '/admin/shop/settings', $this->csrf(['banner_use' => '0'] + $input));
        self::assertSame(303, $hidden->getStatusCode());
        self::assertStringNotContainsString('class="yc-hero"', $this->body($this->get($this->app, '/shop')));
        self::assertSame(200, $this->get($this->app, '/shop/banner-image', ['f' => $filename])->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testCategoryScreens(array $config): void
    {
        $this->setupShop($config);
        $this->signIn(true);
        $form = $this->body($this->get($this->app, '/admin/shop/categories/new'));
        // 코드 칸은 없다. 이름·슬러그·상위 분류로 만든다.
        self::assertStringNotContainsString('name="code"', $form);
        self::assertStringContainsString('name="slug"', $form);
        self::assertStringContainsString('name="parent_id"', $form);
        self::assertStringContainsString('name="menu_hidden"', $form);
        // 새 분류의 편집기 사진은 임시 폴더(tmp/<키>)로 올라갔다가 저장하면서 categories/<id> 로 옮겨진다.
        self::assertSame(1, preg_match('#name="image_key" value="(tmp/[a-f0-9]{32})"#', $form, $keyMatch));
        $tmpKey = $keyMatch[1];
        self::assertStringContainsString('image_key=' . rawurlencode($tmpKey), $form);
        $png = tempnam(sys_get_temp_dir(), 'yc-png-');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $upload = $this->upload($this->app, '/admin/editor/images?csrf_token=' . rawurlencode($_SESSION['csrf_token']) . '&image_key=' . rawurlencode($tmpKey),
            ['upload' => new UploadedFile($png, 'pixel.png', 'image/png', filesize($png))]);
        self::assertSame(200, $upload->getStatusCode());
        $image = json_decode($this->body($upload), true, 512, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('#^/media/editor/tmp/[a-f0-9]{32}/[a-f0-9]{32}\.png$#', $image['url']);
        self::assertSame(200, $this->get($this->app, $image['url'])->getStatusCode());
        $response = $this->post($this->app, '/admin/shop/categories/new', $this->csrf(['parent_id' => '', 'name' => '의류', 'image_key' => $tmpKey, 'head_html' => '<p><img src="' . $image['url'] . '" alt=""></p>', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        self::assertSame(303, $response->getStatusCode());
        $top = $this->shop->categories->bySlug('의류');
        self::assertNotNull($top);
        self::assertSame(['/' . $top['id'] . '/', 1], [$top['path'], (int) $top['depth']]);
        self::assertSame('/admin/shop/categories/edit?id=' . $top['id'] . '&saved=1', $response->getHeaderLine('Location'));
        $movedUrl = '/media/editor/categories/' . $top['id'] . '/' . basename($image['url']);
        self::assertStringContainsString($movedUrl, $top['head_html']);
        self::assertSame(200, $this->get($this->app, $movedUrl)->getStatusCode());
        self::assertFileExists($this->root . '/editor/categories/' . $top['id'] . '/' . basename($image['url']));
        self::assertDirectoryDoesNotExist($this->root . '/editor/' . $tmpKey);
        // 직접 쓴 슬러그가 다른 분류와 겹치면 거절한다.
        $response = $this->post($this->app, '/admin/shop/categories/new', $this->csrf(['name' => '중복', 'slug' => '의류', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('이미 쓰는 슬러그', $this->body($response));
        self::assertStringContainsString('value="중복"', $this->body($response));
        // 목록의 "하위 추가" 는 ?parent=<id> 로 상위 분류를 미리 고른다.
        $childForm = $this->body($this->get($this->app, '/admin/shop/categories/new', ['parent' => (string) $top['id']]));
        self::assertStringContainsString('<option value="' . $top['id'] . '" title="슬러그 의류 · 번호 ' . $top['id'] . '" selected', $childForm);
        $this->post($this->app, '/admin/shop/categories/new', $this->csrf(['parent_id' => (string) $top['id'], 'name' => '셔츠', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        $child = $this->shop->categories->bySlug('셔츠');
        self::assertNotNull($child);
        self::assertSame(['/' . $top['id'] . '/' . $child['id'] . '/', 2], [$child['path'], (int) $child['depth']]);
        // 상품 목록 필터는 분류 id 를 값으로 쓰고 하위 분류의 상품까지 찾는다.
        $product = $this->shop->products->save(['code' => 'C1', 'name' => '파란 셔츠', 'category_id' => (string) $child['id'], 'price' => '300', 'stock' => '3', 'active' => '1'], []);
        $filtered = $this->get($this->app, '/admin/shop/products', ['ca' => (string) $top['id']]);
        self::assertSame(200, $filtered->getStatusCode());
        self::assertStringContainsString('파란 셔츠', $this->body($filtered));
        self::assertStringContainsString('<option value="' . $top['id'] . '" selected', $this->body($filtered));
        $this->shop->products->delete($product); // 아래 분류 삭제는 트리만 본다.
        $list = $this->body($this->get($this->app, '/admin/shop/categories'));
        self::assertStringContainsString('의류', $list);
        self::assertStringContainsString('<code>셔츠</code>', $list); // 이름 아래에 슬러그를 보인다.
        self::assertStringContainsString('products?ca=' . $top['id'], $list);
        self::assertStringContainsString('categories/new?parent=' . $top['id'], $list);
        self::assertStringContainsString('name="rows[' . $top['id'] . '][name]"', $list);
        self::assertStringContainsString('form="yc-category-delete-' . $top['id'] . '"', $list);
        self::assertStringContainsString('id="yc-category-delete-' . $top['id'] . '"', $list);
        self::assertStringNotContainsString('메뉴 숨김', $list);
        $edit = $this->body($this->get($this->app, '/admin/shop/categories/edit', ['id' => (string) $top['id']]));
        self::assertStringContainsString('value="의류"', $edit); self::assertStringContainsString('apply_children', $edit);
        // 상위 분류 선택에는 자기 자신도, 자기 하위 분류도 없다.
        self::assertStringContainsString('name="parent_id"', $edit);
        self::assertStringNotContainsString('<option value="' . $top['id'] . '"', $edit);
        self::assertStringNotContainsString('<option value="' . $child['id'] . '"', $edit);
        // 목록 위·아래 HTML 은 코어 편집기(CKEditor)로 쓰고, 저장된 분류의 사진은 제 폴더(categories/<id>)로 올린다.
        self::assertStringContainsString('id="yc-head-html" name="head_html" rows="6" data-cms-editor', $edit);
        self::assertStringContainsString('id="yc-tail-html" name="tail_html" rows="6" data-cms-editor', $edit);
        self::assertStringContainsString('/vendor/ckeditor4/ckeditor.js', $edit);
        self::assertStringContainsString('name="image_key" value="categories/' . $top['id'] . '"', $edit);
        // 도구 막대의 "쇼핑몰 보기"는 수정 중인 분류의 공개 화면으로 간다.
        self::assertStringContainsString('href="/shop/c/%EC%9D%98%EB%A5%98" target="_blank"', $edit);
        // "이 분류에 상품 등록"은 상품 등록 폼을 열며 대표 분류를 미리 고른다. 모르는 값은 무시한다.
        self::assertStringContainsString('href="/admin/shop/products/new?category=' . $top['id'] . '"', $edit);
        self::assertMatchesRegularExpression('~<option value="' . $top['id'] . '" title="슬러그 의류 · 번호 ' . $top['id'] . '"[^>]* selected~', $this->body($this->get($this->app, '/admin/shop/products/new', ['category' => (string) $top['id']])));
        self::assertStringNotContainsString('" selected>의류</option>', $this->body($this->get($this->app, '/admin/shop/products/new', ['category' => 'zz'])));
        self::assertStringContainsString('href="/shop" target="_blank"', $form);
        self::assertStringContainsString('image_key=' . rawurlencode('categories/' . $top['id']), $edit);
        self::assertStringContainsString("items:['GnucmsImages'", $edit);
        self::assertStringContainsString('data-uploaded-images', $edit);
        $response = $this->post($this->app, '/admin/shop/categories/edit', $this->csrf(['id' => (string) $top['id'], 'parent_id' => '', 'slug' => '의류', 'name' => '의류(수정)', 'active' => '0', 'menu_hidden' => '1', 'list_columns' => '4', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0', 'apply_children' => '1']));
        self::assertSame(303, $response->getStatusCode());
        self::assertSame(0, (int) $this->shop->categories->get((int) $child['id'])['active']);
        // 메뉴 숨김은 저장되고, 목록에 표시가 붙는다. "하위 분류에 적용"은 이 값을 내리지 않는다.
        self::assertSame(1, (int) $this->shop->categories->get((int) $top['id'])['menu_hidden']);
        self::assertSame(0, (int) $this->shop->categories->get((int) $child['id'])['menu_hidden']);
        self::assertStringContainsString('메뉴 숨김', $this->body($this->get($this->app, '/admin/shop/categories')));
        self::assertStringContainsString('name="menu_hidden" value="1" checked', $this->body($this->get($this->app, '/admin/shop/categories/edit', ['id' => (string) $top['id']])));
        $response = $this->post($this->app, '/admin/shop/categories', $this->csrf(['action' => 'bulk', 'rows' => [$child['id'] => ['name' => '셔츠(일괄)', 'sort_order' => '1', 'active' => '1', 'list_columns' => '2', 'list_rows' => '2', 'image_width' => '100', 'image_height' => '0']]]));
        self::assertSame('/admin/shop/categories?saved=1', $response->getHeaderLine('Location'));
        self::assertSame('셔츠(일괄)', $this->shop->categories->get((int) $child['id'])['name']);
        // 상위 분류를 바꿔 저장하면 옮겨진다.
        $this->post($this->app, '/admin/shop/categories/new', $this->csrf(['parent_id' => '', 'name' => '가전', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        $home = $this->shop->categories->bySlug('가전');
        self::assertNotNull($home);
        $response = $this->post($this->app, '/admin/shop/categories/edit', $this->csrf(['id' => (string) $child['id'], 'parent_id' => (string) $home['id'], 'slug' => '셔츠', 'name' => '셔츠(일괄)', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        self::assertSame(303, $response->getStatusCode());
        $moved = $this->shop->categories->get((int) $child['id']);
        self::assertSame(['/' . $home['id'] . '/' . $child['id'] . '/', 2], [$moved['path'], (int) $moved['depth']]);
        $response = $this->post($this->app, '/admin/shop/categories', $this->csrf(['action' => 'delete', 'id' => (string) $home['id']]));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('하위 분류가 있어', $this->body($response));
        $response = $this->post($this->app, '/admin/shop/categories', $this->csrf(['action' => 'delete', 'id' => (string) $child['id']]));
        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->shop->categories->bySlug('셔츠'));
        self::assertSame(404, $this->get($this->app, '/admin/shop/categories/edit', ['id' => '999'])->getStatusCode());
        self::assertSame(403, $this->post($this->app, '/admin/shop/categories', ['action' => 'delete', 'id' => (string) $top['id']])->getStatusCode());
    }

    /** 분류명에 홑따옴표와 스크립트가 섞여 있어도 삭제 확인 문구는 data-yc-confirm 속성의 고정 문구여야 한다(인라인 JS 금지). */
    #[DataProvider('connectionProvider')]
    public function testCategoryListDeleteConfirmIsStatic(array $config): void
    {
        $this->setupShop($config);
        $this->signIn(true);
        $this->post($this->app, '/admin/shop/categories/new', $this->csrf(['name' => "잡화'); alert(1);//", 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        $list = $this->body($this->get($this->app, '/admin/shop/categories'));
        self::assertStringContainsString('data-yc-confirm="이 분류를 삭제할까요?"', $list);
        self::assertStringNotContainsString('onsubmit=', $list);
        self::assertStringNotContainsString("confirm('", $list);
        self::assertStringContainsString('&#039;', $list);
    }

    /**
     * 29판 이전의 분류 코드는 영문·숫자 섞인 값이었다(1a, zz). 북마크나 메일에 남은 그 값이 ?ca= 로 들어와도
     * 관리자 화면이 404 로 죽지 않고 "거르지 않음" 으로 내려가야 한다(?parent= 도 같다).
     */
    #[DataProvider('connectionProvider')]
    public function testNonNumericCategoryValuesAreIgnoredInsteadOfRaising(array $config): void
    {
        $this->setupShop($config);
        $this->seedProducts();
        $this->signIn(true);
        foreach (['1a', 'zz'] as $ca) {
            $list = $this->get($this->app, '/admin/shop/products', ['ca' => $ca]);
            self::assertSame(200, $list->getStatusCode(), $ca);
            $body = $this->body($list);
            self::assertStringContainsString('파란 셔츠', $body, $ca);
            self::assertStringContainsString('가방', $body, $ca);
            // 분류 거르개는 아무것도 고르지 않은 채다(전체 분류).
            self::assertSame(1, preg_match('#name="ca">(.*?)</select>#s', $body, $select), $ca);
            self::assertStringNotContainsString(' selected', $select[1], $ca);
        }
        $form = $this->get($this->app, '/admin/shop/categories/new', ['parent' => '1a']);
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('<option value="" selected>최상위</option>', $this->body($form));
    }

    /** 10단계 분류는 그 아래에 또 만들 수 없으므로 상위 분류 선택에 나오지 않는다(9단계는 나온다). */
    #[DataProvider('connectionProvider')]
    public function testFullDepthCategoriesAreNotOfferedAsParents(array $config): void
    {
        $this->setupShop($config);
        $this->signIn(true);
        $ids = [];
        $parent = '';
        for ($depth = 1; $depth <= Categories::MAX_DEPTH; $depth++) {
            $id = $this->shop->categories->save(['parent_id' => $parent, 'name' => '단계' . $depth, 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']);
            $ids[$depth] = $id;
            $parent = (string) $id;
        }
        $form = $this->body($this->get($this->app, '/admin/shop/categories/new'));
        self::assertStringContainsString('<option value="' . $ids[Categories::MAX_DEPTH - 1] . '"', $form);
        self::assertStringNotContainsString('<option value="' . $ids[Categories::MAX_DEPTH] . '"', $form);
        // 수정 화면도 같다: 자기와 자기 하위를 뺀 목록에서 10단계 분류는 빠진다.
        $edit = $this->body($this->get($this->app, '/admin/shop/categories/edit', ['id' => (string) $ids[1]]));
        self::assertStringNotContainsString('<option value="' . $ids[Categories::MAX_DEPTH] . '"', $edit);
    }

    private function seedProducts(): array
    {
        $top = $this->shop->categories->get($this->shop->categories->save(['name' => '의류', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        $other = $this->shop->categories->get($this->shop->categories->save(['name' => '잡화', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        $a = $this->shop->products->save(['code' => 'A1', 'name' => '파란 셔츠', 'category_id' => (string) $top['id'], 'price' => '300', 'stock' => '3', 'active' => '1', 'stock_alert' => '5',
            'option_group' => [1 => '색상'], 'options' => [['value1' => '빨강', 'price' => '0', 'stock' => '1', 'stock_alert' => '2', 'active' => '1']]], []);
        $b = $this->shop->products->save(['code' => 'B1', 'name' => '가방', 'category_id' => (string) $other['id'], 'price' => '100', 'stock' => '0', 'active' => '1'], []);
        return ['top' => $top, 'other' => $other, 'a' => $a, 'b' => $b];
    }

    /**
     * 복사 폼의 상품 코드 pattern 은 브라우저가 v 플래그로 컴파일하므로 문자 클래스 안의 하이픈을
     * 이스케이프해야 한다. 안 하면 크롬이 정규식을 버려 검증이 조용히 사라진다(2026-09-08 수동 테스트).
     */
    #[DataProvider('connectionProvider')]
    public function testProductCodePatternEscapesTheHyphen(array $config): void
    {
        $this->setupShop($config);
        $this->seedProducts();
        $this->signIn(true);

        $list = $this->body($this->get($this->app, '/admin/shop/products'));
        self::assertStringContainsString('pattern="[A-Za-z0-9_\\-]{1,20}"', $list);
    }

    #[DataProvider('connectionProvider')]
    public function testProductListBulkCopyStockAndSearch(array $config): void
    {
        $this->setupShop($config);
        $seed = $this->seedProducts();
        $this->signIn(true);
        $list = $this->body($this->get($this->app, '/admin/shop/products'));
        self::assertStringContainsString('파란 셔츠', $list); self::assertStringContainsString('가방', $list);
        self::assertStringContainsString('name="rows[' . $seed['a'] . '][price]"', $list);
        self::assertStringContainsString('<a class="tab" href="/admin/shop/feedback">후기·문의</a>', $list);
        $feedbackNav = $this->body($this->get($this->app, '/admin/shop/feedback'));
        self::assertStringContainsString('<a class="tab tab-active" href="/admin/shop/feedback" aria-current="page">후기·문의</a>', $feedbackNav);
        self::assertStringNotContainsString('/products/settings-copy?source=', $list);
        self::assertStringNotContainsString('name="source_code"', $list);
        $sourceEdit = $this->body($this->get($this->app, '/admin/shop/products/edit', ['id' => (string) $seed['a']]));
        self::assertStringContainsString('/products/settings-copy?source=' . $seed['a'], $sourceEdit);
        $missingSource = $this->get($this->app, '/admin/shop/products/settings-copy');
        self::assertSame('/admin/shop/products?choose_copy_source=1', $missingSource->getHeaderLine('Location'));
        $this->shop->store->update('yc_products', $seed['b'], ['phone_inquiry' => 1]);
        $settingsCopy = $this->body($this->get($this->app, '/admin/shop/products/settings-copy', ['source' => (string) $seed['a'], 'target_q' => 'B1', 'target_field' => 'code']));
        self::assertStringNotContainsString('기준 상품 선택', $settingsCopy);
        self::assertStringContainsString('결과는 20개씩 나누어 표시합니다', $settingsCopy);
        self::assertStringContainsString('검색 결과 전체', $settingsCopy);
        self::assertStringContainsString('name="copy_fields[]"', $settingsCopy);
        self::assertStringNotContainsString('value="point"', $settingsCopy);
        $response = $this->post($this->app, '/admin/shop/products/settings-copy', $this->csrf(['action' => 'copy-settings', 'source_id' => (string) $seed['a'], 'copy_fields' => ['phone_inquiry'], 'scope' => 'search', 'target_q' => 'B1', 'target_field' => 'code', 'target_ca' => '']));
        self::assertSame('/admin/shop/products/settings-copy?source=' . $seed['a'] . '&target_q=B1&target_field=code&target_ca=&scope=search&copied=1&changed=1', $response->getHeaderLine('Location'));
        self::assertSame(0, (int) $this->shop->products->find($seed['b'])['phone_inquiry']);
        self::assertStringNotContainsString('가방', $this->body($this->get($this->app, '/admin/shop/products', ['q' => '셔츠'])));
        self::assertStringNotContainsString('파란 셔츠', $this->body($this->get($this->app, '/admin/shop/products', ['ca' => (string) $seed['other']['id']])));
        $response = $this->post($this->app, '/admin/shop/products', $this->csrf(['action' => 'bulk', 'rows' => [$seed['b'] => ['category_id' => (string) $seed['top']['id'], 'name' => '가방(일괄)', 'list_price' => '0', 'price' => '150', 'tax_free' => '1', 'shipping_type' => '1', 'phone_inquiry' => '1', 'active' => '1', 'sold_out' => '0', 'sort_order' => '1']]]));
        self::assertSame('/admin/shop/products?saved=1', $response->getHeaderLine('Location'));
        self::assertSame('가방(일괄)', $this->shop->products->find($seed['b'])['name']);
        self::assertSame(1, (int) $this->shop->products->find($seed['b'])['tax_free']);
        self::assertSame(1, (int) $this->shop->products->find($seed['b'])['shipping_type']);
        self::assertSame(1, (int) $this->shop->products->find($seed['b'])['phone_inquiry']);
        $response = $this->post($this->app, '/admin/shop/products', $this->csrf(['action' => 'bulk', 'rows' => [$seed['b'] => ['category_id' => '999', 'name' => 'x', 'price' => '1']]]));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('분류를 찾을 수 없습니다', $this->body($response));
        $draft = $this->body($this->get($this->app, '/admin/shop/products/new', ['copy' => (string) $seed['a'], 'code' => 'A2']));
        self::assertStringContainsString('아직 새 상품이 생성되지 않았습니다', $draft);
        self::assertStringContainsString('name="copy_source_id" value="' . $seed['a'] . '"', $draft);
        self::assertStringContainsString('name="code" value="A2"', $draft);
        self::assertStringContainsString('value="파란 셔츠"', $draft);
        self::assertNull($this->shop->products->byCode('A2'), '복사 내용을 불러온 시점에는 상품을 만들지 않는다');
        $response = $this->post($this->app, '/admin/shop/products/new', $this->csrf($this->productForm((int) $seed['top']['id'], ['copy_source_id' => (string) $seed['a'], 'code' => 'A2', 'name' => '파란 셔츠 복사'])));
        $copy = $this->shop->products->byCode('A2');
        self::assertNotNull($copy);
        self::assertSame('/admin/shop/products/edit?id=' . $copy['id'] . '&saved=1', $response->getHeaderLine('Location'));
        self::assertCount(1, $copy['options']['select']);
        self::assertSame(422, $this->post($this->app, '/admin/shop/products/new', $this->csrf($this->productForm((int) $seed['top']['id'], ['copy_source_id' => (string) $seed['a'], 'code' => 'A2'])))->getStatusCode());
        self::assertSame(404, $this->post($this->app, '/admin/shop/products/copy', $this->csrf(['id' => (string) $seed['a'], 'code' => 'A3']))->getStatusCode());
        // 진열 유형 화면은 없앴다 — 주소도 하위 탭도 남지 않는다.
        self::assertSame(404, $this->get($this->app, '/admin/shop/products/types')->getStatusCode());
        self::assertSame(404, $this->post($this->app, '/admin/shop/products/types', $this->csrf(['rows' => []]))->getStatusCode());
        self::assertStringNotContainsString('진열 유형', $list);
        self::assertStringNotContainsString('/products/types', $list);
        // 상품 재고 화면은 선택옵션이 없는 상품만 — 옵션 상품(a)의 재고는 조합 행에 있다.
        $stock = $this->body($this->get($this->app, '/admin/shop/products/stock'));
        self::assertStringContainsString('name="rows[' . $seed['b'] . '][stock]"', $stock);
        self::assertStringNotContainsString('name="rows[' . $seed['a'] . '][stock]"', $stock);
        $this->post($this->app, '/admin/shop/products/stock', $this->csrf(['rows' => [$seed['b'] => ['original_stock' => (string) $this->shop->products->find($seed['b'])['stock'], 'stock' => '9', 'stock_alert' => '1', 'active' => '1', 'sold_out' => '0']]]));
        self::assertSame(9, (int) $this->shop->products->find($seed['b'])['stock']);
        // 상품 목록은 재고를 수정하지 않고 면세·전화 문의를 바꾸며, 폼은 옵션 상품의 상품 재고 칸을 잠근다.
        $list = $this->body($this->get($this->app, '/admin/shop/products'));
        self::assertStringContainsString('name="rows[' . $seed['b'] . '][tax_free]"', $list);
        self::assertStringContainsString('name="rows[' . $seed['b'] . '][phone_inquiry]"', $list);
        self::assertStringNotContainsString('name="rows[' . $seed['a'] . '][stock]"', $list);
        self::assertStringNotContainsString('name="rows[' . $seed['b'] . '][stock]"', $list);
        self::assertStringNotContainsString('name="rows[' . $seed['b'] . '][stock_alert]"', $list);
        $edit = $this->body($this->get($this->app, '/admin/shop/products/edit', ['id' => (string) $seed['a']]));
        self::assertMatchesRegularExpression('/name="stock"[^>]* readonly/', $edit);
        self::assertStringContainsString('조합별 재고를 씁니다', $edit);
        self::assertStringContainsString('data-yc-option-stock-note>', $edit);
        $editPlain = $this->body($this->get($this->app, '/admin/shop/products/edit', ['id' => (string) $seed['b']]));
        self::assertDoesNotMatchRegularExpression('/name="stock"[^>]* readonly/', $editPlain);
        self::assertStringContainsString('data-yc-option-stock-note hidden>', $editPlain);
        $optionId = (int) $this->shop->products->get($seed['a'])['options']['select'][0]['id'];
        $optionStock = $this->body($this->get($this->app, '/admin/shop/products/option-stock'));
        self::assertStringContainsString('name="rows[' . $optionId . '][stock]"', $optionStock);
        self::assertStringContainsString('빨강', $optionStock);
        $this->post($this->app, '/admin/shop/products/option-stock', $this->csrf(['rows' => [$optionId => ['original_stock' => '1', 'stock' => '4', 'stock_alert' => '0', 'active' => '1']]]));
        self::assertSame(4, (int) $this->shop->products->get($seed['a'])['options']['select'][0]['stock']);
        $response = $this->post($this->app, '/admin/shop/products', $this->csrf(['action' => 'delete', 'ids' => [(string) $copy['id']]]));
        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->shop->products->find((int) $copy['id']));
        session_start(); $_SESSION = []; session_write_close();
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop/products'), '/admin/shop/products');
    }

    /** 상품 목록에서 선택한 상품을 분류에 넣고 뺀다. 안내문에 바뀐 수와 건너뛴 수가 나온다. */
    #[DataProvider('connectionProvider')]
    public function testListCategorizeAndUncategorize(array $config): void
    {
        $this->setupShop($config);
        $seed = $this->seedProducts();
        $this->signIn(true);
        $event = $this->shop->categories->save(['name' => '봄 세일', 'parent_id' => '', 'active' => '1', 'list_columns' => '4', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']);
        $a = (int) $seed['a']; $b = (int) $seed['b'];
        $list = $this->body($this->get($this->app, '/admin/shop/products'));
        self::assertStringContainsString('name="category" form="yc-product-selection"', $list);
        self::assertStringContainsString('value="categorize"', $list); self::assertStringContainsString('value="uncategorize"', $list);
        self::assertStringNotContainsString('onsubmit=', $list);
        $response = $this->post($this->app, '/admin/shop/products', $this->csrf(['action' => 'categorize', 'ids' => [(string) $a, (string) $b], 'category' => (string) $event]));
        self::assertSame('/admin/shop/products?op=add&changed=2&skipped=0', $response->getHeaderLine('Location'));
        self::assertStringContainsString('2개 상품을 분류에 넣었습니다', $this->body($this->get($this->app, '/admin/shop/products', ['op' => 'add', 'changed' => '2', 'skipped' => '0'])));
        self::assertSame((int) $event, (int) $this->shop->products->get($a)['categories'][2]['id']);
        $response = $this->post($this->app, '/admin/shop/products', $this->csrf(['action' => 'uncategorize', 'ids' => [(string) $a], 'category' => (string) $event]));
        self::assertSame('/admin/shop/products?op=remove&changed=1&skipped=0', $response->getHeaderLine('Location'));
        self::assertArrayNotHasKey(2, $this->shop->products->get($a)['categories']);
        self::assertSame(422, $this->post($this->app, '/admin/shop/products', $this->csrf(['action' => 'categorize', 'ids' => [(string) $a], 'category' => '']))->getStatusCode());
    }

    private function productForm(int $category, array $overrides = []): array
    {
        return $overrides + ['action' => 'save', 'code' => 'F1', 'name' => '폼 상품', 'category_id' => (string) $category, 'price' => '12000', 'list_price' => '0',
            'stock' => '4', 'stock_alert' => '0', 'buy_min' => '0', 'buy_max' => '0', 'active' => '1', 'shipping_type' => '0', 'shipping_method' => '0', 'shipping_fee' => '0', 'shipping_free_minimum' => '0', 'shipping_per_qty' => '0',
            'summary' => '요약', 'description' => '<p>본문</p>', 'info_group' => '', 'memo' => '', 'sort_order' => '0',
            'option_group' => [1 => '색상', 2 => '', 3 => ''], 'option_values' => [1 => '', 2 => '', 3 => ''],
            'options' => [['value1' => '빨강', 'value2' => '', 'value3' => '', 'price' => '0', 'stock' => '2', 'stock_alert' => '1', 'active' => '1']], 'extras' => []];
    }

    #[DataProvider('connectionProvider')]
    public function testProductFormCombineSaveEditImagesAndConflicts(array $config): void
    {
        $this->setupShop($config);
        $seed = $this->seedProducts();
        $this->signIn(true);
        $form = $this->body($this->get($this->app, '/admin/shop/products/new'));
        self::assertStringContainsString('상품 정보와 판매 조건을 입력해 새 상품을 등록하세요.', $form);
        self::assertMatchesRegularExpression('/name="code" value="[0-9]{10}"/', $form);
        self::assertStringContainsString('의류', $form); self::assertStringContainsString('data-yc-info-groups', $form); self::assertStringContainsString('data-cms-editor', $form);
        self::assertStringNotContainsString('name="seller_email"', $form);
        self::assertStringNotContainsString('id="section-html"', $form);
        foreach (['point_type', 'point', 'supply_point', 'maker', 'origin', 'brand', 'model'] as $field) self::assertStringNotContainsString('name="' . $field . '"', $form);
        // 추가 분류는 고정 칸이 아니라 "분류 추가" 로 늘리는 줄이다. 빈 폼에는 줄이 없고 틀만 있다.
        self::assertStringContainsString('data-yc-add-category', $form);
        self::assertStringNotContainsString('name="category2_id"', $form);
        self::assertStringContainsString('<template data-yc-category-row>', $form);
        self::assertStringContainsString('name="extras[0][value1]"', $form);
        self::assertStringNotContainsString('name="extras[1][value1]"', $form);
        self::assertSame(1, preg_match('/<div class="yc-save-bar">(.*?)<\/form>/s', $form, $saveBar));
        self::assertStringNotContainsString('target="_blank"', $saveBar[1]);
        // 선택옵션 세 줄의 예시는 서로 다르다(색상 → 사이즈 → 소재).
        foreach (['그룹 이름 (예: 색상)', '값 (예: 빨강,파랑)', '그룹 이름 (예: 사이즈)', '값 (예: S,M,L)', '그룹 이름 (예: 소재)', '값 (예: 면,린넨)'] as $placeholder) self::assertStringContainsString('placeholder="' . $placeholder . '"', $form);
        // 진열 유형 깃발은 없앴다 — 체크도, "유형 다른 상품에도 적용" 칩도 없다.
        self::assertStringNotContainsString('name="is_hit"', $form);
        self::assertStringNotContainsString('value="types"', $form);
        // 폼의 첫 submit 단추는 이름 없는 숨은 단추여야 한다. 그래야 입력칸에서 Enter 를 눌러도
        // "조합 생성"(첫 눈에 보이는 submit) 이 아니라 기본 action=save 로 암시적 제출된다.
        $hiddenSubmitPos = strpos($form, '<button type="submit" hidden aria-hidden="true" tabindex="-1"></button>');
        $combineButtonPos = strpos($form, 'value="combine"');
        self::assertNotFalse($hiddenSubmitPos); self::assertNotFalse($combineButtonPos);
        self::assertLessThan($combineButtonPos, $hiddenSubmitPos);
        self::assertStringNotContainsString('<button type="submit" hidden aria-hidden="true" tabindex="-1" name=', $form);
        $combine = $this->post($this->app, '/admin/shop/products/new', $this->csrf($this->productForm((int) $seed['top']['id'], ['action' => 'combine', 'option_values' => [1 => '빨강,파랑', 2 => 'S,M', 3 => ''], 'option_group' => [1 => '색상', 2 => '크기', 3 => ''], 'options' => []])));
        self::assertSame(200, $combine->getStatusCode());
        $body = $this->body($combine);
        self::assertStringContainsString('name="options[3][value2]" value="M"', $body);
        self::assertStringContainsString('value="폼 상품"', $body);
        self::assertSame(0, $this->shop->products->stats()['products'] - 2);
        $extras = [
            ['value1' => '포장', 'value2' => '선물 포장', 'price' => '700', 'stock' => '9', 'stock_alert' => '1', 'active' => '1'],
            ['value1' => '포장', 'value2' => '기본 포장', 'price' => '0', 'stock' => '5', 'stock_alert' => '1', 'active' => '0'],
        ];
        $response = $this->postWithFiles($this->app, '/admin/shop/products/new', $this->csrf($this->productForm((int) $seed['top']['id'], ['extra_category_ids' => [(string) $seed['other']['id']], 'extras' => $extras])), ['images' => [ImagesTest::png(120, 120)]]);
        self::assertSame(303, $response->getStatusCode(), $this->body($response));
        $product = $this->shop->products->byCode('F1');
        self::assertSame('/admin/shop/products/edit?id=' . $product['id'] . '&saved=1', $response->getHeaderLine('Location'));
        self::assertStringContainsString('yc_last_category=', implode(';', $response->getHeader('Set-Cookie')));
        self::assertCount(1, $product['images']); self::assertSame(['색상'], $product['options']['select_groups']);
        $response = $this->post($this->app, '/admin/shop/products/new', $this->csrf($this->productForm((int) $seed['top']['id'], ['code' => 'F1'])));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('이미 사용 중인 상품 코드', $this->body($response));
        self::assertStringContainsString('name="options[0][value1]" value="빨강"', $this->body($response));
        self::assertStringNotContainsString('name="apply_scope"', $this->body($response));
        self::assertStringNotContainsString('name="apply_fields[]"', $this->body($response));
        $edit = $this->body($this->get($this->app, '/admin/shop/products/edit', ['id' => (string) $product['id']]));
        self::assertStringContainsString('폼 상품 · F1', $edit);
        self::assertStringContainsString('class="btn btn-sm btn-outline" href="/admin/shop/products"', $edit);
        self::assertStringContainsString('href="/shop/item?id=' . rawurlencode($product['code']) . '" target="_blank"', $edit); // 도구 막대의 "쇼핑몰 보기"
        self::assertSame(1, preg_match('/<div class="yc-save-bar">(.*?)<\/form>/s', $edit, $saveBar));
        self::assertStringContainsString('href="/shop/item?id=' . rawurlencode($product['code']) . '" target="_blank" rel="noopener"', $saveBar[1]);
        self::assertStringContainsString('상품 보기', $saveBar[1]);
        self::assertStringContainsString('value="F1"', $edit); self::assertStringContainsString('name="version" value="0"', $edit);
        self::assertCount(2, $product['options']['extra']);
        self::assertStringContainsString('name="extras[0][value2]" value="선물 포장"', $edit);
        self::assertStringContainsString('name="extras[1][value2]" value="기본 포장"', $edit);
        self::assertStringNotContainsString('name="extras[2][value1]"', $edit);
        self::assertStringContainsString('image_delete[]', $edit); self::assertStringContainsString($product['images'][0]['filename'], $edit);
        self::assertSame((int) $seed['other']['id'], (int) $this->shop->products->get((int) $product['id'])['categories'][2]['id']);
        self::assertMatchesRegularExpression('/name="extra_category_ids\[\]".*?<option value="' . (int) $seed['other']['id'] . '"[^>]* selected>/s', $edit);
        $response = $this->post($this->app, '/admin/shop/products/edit', $this->csrf($this->productForm((int) $seed['top']['id'], ['id' => (string) $product['id'], 'version' => '0', 'name' => '수정됨', 'image_delete' => [(string) $product['images'][0]['id']]])));
        self::assertSame(303, $response->getStatusCode(), $this->body($response));
        $product = $this->shop->products->get((int) $product['id']);
        self::assertSame('수정됨', $product['name']); self::assertSame([], $product['images']);
        self::assertSame([], $product['options']['extra'], '폼에서 제거한 추가옵션은 상품 저장 시 삭제된다.');
        $response = $this->post($this->app, '/admin/shop/products/edit', $this->csrf($this->productForm((int) $seed['top']['id'], ['id' => (string) $product['id'], 'version' => '0', 'name' => '충돌'])));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('다른 관리자가 먼저 저장', $this->body($response));
        self::assertSame('수정됨', $this->shop->products->find((int) $product['id'])['name']);
        self::assertSame(404, $this->get($this->app, '/admin/shop/products/edit', ['id' => '999'])->getStatusCode());
        self::assertSame(403, $this->post($this->app, '/admin/shop/products/edit', $this->productForm((int) $seed['top']['id'], ['id' => (string) $product['id'], 'version' => '1']))->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testSavedOptionValuesReappearInEditFormWithoutLosingDraftInput(array $config): void
    {
        $this->setupShop($config);
        $seed = $this->seedProducts();
        $this->signIn(true);
        $input = $this->productForm((int) $seed['top']['id'], [
            'code' => 'CSV1', 'option_group' => [1 => '색상', 2 => '사이즈', 3 => '재질'],
            'option_values' => [1 => '파랑,빨강', 2 => '0,XL', 3 => '면,실크'],
        ]);
        $input['options'] = \GnuCms\Shop\Catalog\Options::draft($input, [])['rows'];
        $input['options'][0]['stock'] = 0;
        $input['options'][0]['price'] = 500;
        foreach ($input['options'] as &$row) if ($row['value1'] === '빨강') $row['active'] = 0;
        unset($row);
        self::assertSame(303, $this->post($this->app, '/admin/shop/products/new', $this->csrf($input))->getStatusCode());
        $product = $this->shop->products->byCode('CSV1');
        $id = (int) $product['id'];
        $body = $this->body($this->get($this->app, '/admin/shop/products/edit', ['id' => (string) $id]));
        foreach ($input['option_values'] as $group => $csv) {
            self::assertStringContainsString('name="option_values[' . $group . ']" value="' . $csv . '"', $body);
        }
        self::assertCount(8, $product['options']['select']);
        $edit = $input + ['id' => (string) $id, 'version' => (string) $product['version']];
        unset($edit['code']);
        $edit['action'] = 'combine';
        $response = $this->post($this->app, '/admin/shop/products/edit', $this->csrf($edit));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="options[0][price]" value="500"', $this->body($response));
        self::assertStringContainsString('name="options[0][stock]" value="0"', $this->body($response));
        self::assertSame($product['options'], $this->shop->products->get($id)['options']);
        // 잘못된 입력으로 저장에 실패했을 때는 방금 입력한 CSV를 보존한다.
        $edit['action'] = 'save';
        $edit['price'] = '-1';
        $edit['option_values'][1] = '파랑,초록';
        $response = $this->post($this->app, '/admin/shop/products/edit', $this->csrf($edit));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('name="option_values[1]" value="파랑,초록"', $this->body($response));
        self::assertSame($product['options'], $this->shop->products->get($id)['options']);
    }

    /** 이미 조합이 저장돼 있는 상품에서 "조합 생성"을 다시 누르면, 화면에 아직 저장하지 않은
     *  방금 고친 값이 DB에 저장돼 있던 값에 덮이면 안 된다. */
    #[DataProvider('connectionProvider')]
    public function testProductFormCombinePrefersSubmittedOptionValuesOverStoredOnes(array $config): void
    {
        $this->setupShop($config);
        $seed = $this->seedProducts();
        $this->signIn(true);
        $product = $this->shop->products->get($seed['a']);
        self::assertSame(1, (int) $product['options']['select'][0]['stock']);
        $input = $this->productForm((int) $seed['top']['id'], [
            'id' => (string) $seed['a'], 'version' => (string) $product['version'], 'action' => 'combine',
            'option_group' => [1 => '색상', 2 => '', 3 => ''], 'option_values' => [1 => '빨강,파랑', 2 => '', 3 => ''],
            'options' => [['value1' => '빨강', 'value2' => '', 'value3' => '', 'price' => '500', 'stock' => '7', 'stock_alert' => '1', 'active' => '1']],
        ]);
        unset($input['code']); // 수정 화면의 읽기 전용 상품 코드는 POST에 포함되지 않는다.
        $response = $this->post($this->app, '/admin/shop/products/edit', $this->csrf($input));
        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);
        self::assertStringContainsString('name="options[0][value1]" value="빨강"', $body);
        self::assertStringContainsString('href="/shop/item?id=A1"', $body);
        self::assertStringContainsString('value="A1" readonly', $body);
        self::assertStringContainsString('name="options[0][price]" value="500"', $body);
        self::assertStringContainsString('name="options[0][stock]" value="7"', $body);
        self::assertStringContainsString('name="options[1][value1]" value="파랑"', $body);
        self::assertSame(1, (int) $this->shop->products->get($seed['a'])['options']['select'][0]['stock']);
    }

    #[DataProvider('connectionProvider')]
    public function testExtraOptionNamesAndReorderedRowsAreSavedWithoutChangingTheirIds(array $config): void
    {
        $this->setupShop($config);
        $seed = $this->seedProducts();
        $this->signIn(true);
        $extras = [
            ['value1' => '기존 그룹', 'value2' => '기존 포장', 'price' => '100', 'stock' => '5', 'active' => '1'],
            ['value2' => '리본', 'price' => '200', 'stock' => '7', 'active' => '1'],
            ['value2' => '카드', 'price' => '300', 'stock' => '9', 'active' => '0'],
        ];
        $input = $this->productForm((int) $seed['top']['id'], ['extras' => $extras]);
        $response = $this->post($this->app, '/admin/shop/products/new', $this->csrf($input));
        self::assertSame(303, $response->getStatusCode());
        $product = $this->shop->products->byCode('F1');
        $ids = array_column($product['options']['extra'], 'id', 'value2');
        $input['id'] = (string) $product['id'];
        $input['version'] = (string) $product['version'];
        $input['extras'] = [$extras[2], $extras[0], $extras[1]];
        $response = $this->post($this->app, '/admin/shop/products/edit', $this->csrf($input));
        self::assertSame(303, $response->getStatusCode());
        $after = $this->shop->products->get((int) $product['id']);
        self::assertSame(['카드', '기존 포장', '리본'], array_column($after['options']['extra'], 'value2'));
        foreach ($after['options']['extra'] as $row) self::assertSame($ids[$row['value2']], $row['id']);
        self::assertSame('기존 그룹', $after['options']['extra'][1]['value1']);
        self::assertSame('', $after['options']['extra'][2]['value1']);
        $edit = $this->body($this->get($this->app, '/admin/shop/products/edit', ['id' => (string) $product['id']]));
        self::assertStringNotContainsString('<th>그룹명</th>', $edit);
        self::assertStringContainsString('type="hidden" name="extras[1][value1]" value="기존 그룹"', $edit);
        self::assertStringContainsString('name="extras[0][value2]" value="카드"', $edit);
        self::assertStringContainsString('name="extras[2][value2]" value="리본"', $edit);
        $public = $this->body($this->get($this->app, '/shop/item', ['id' => 'F1']));
        self::assertStringContainsString('>리본<small>', $public);
        self::assertStringNotContainsString(' / 리본', $public);
        self::assertStringContainsString('기존 그룹 / 기존 포장', $public);
    }

    private function combineAjax(string $page, array $input): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/shop/products/' . $page)
            ->withHeader('Accept', 'application/json')->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode(['action' => 'combine'] + $input, JSON_THROW_ON_ERROR));
        return Kernel::create($this->app, dirname(__DIR__, 2) . '/templates', '')->handle($request);
    }

    #[DataProvider('connectionProvider')]
    public function testAjaxCombinationsReturnOnlyTheTableAndPreserveDraftValuesWithoutSaving(array $config): void
    {
        $this->setupShop($config);
        $seed = $this->seedProducts();
        $this->signIn(true);
        $input = ['option_group' => [1 => '색상', 2 => '크기'], 'option_values' => [1 => '빨강,파랑', 2 => 'S,0']];
        $response = $this->combineAjax('new', $this->csrf($input));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $data = json_decode($this->body($response), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('4개 조합', $data['message']);
        self::assertStringContainsString('name="options[3][value2]" value="0"', $data['html']);
        self::assertStringNotContainsString('<form', $data['html']);
        self::assertStringNotContainsString('<script', $data['html']);
        self::assertStringNotContainsString('<html', $data['html']);
        self::assertSame(2, $this->shop->products->stats()['products']);

        $product = $this->shop->products->get($seed['a']);
        $input = ['id' => (string) $seed['a'], 'option_group' => [1 => '색상'], 'option_values' => [1 => '빨강,파랑'],
            'options' => [['value1' => '빨강', 'value2' => '', 'value3' => '', 'price' => '-500', 'stock' => '7', 'stock_alert' => '2', 'active' => '0']]];
        $response = $this->combineAjax('edit', $this->csrf($input));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($this->body($response), true, 512, JSON_THROW_ON_ERROR);
        foreach (['price' => '-500', 'stock' => '7', 'stock_alert' => '2'] as $field => $value) {
            self::assertStringContainsString('name="options[0][' . $field . ']" value="' . $value . '"', $data['html']);
        }
        self::assertStringNotContainsString('name="options[0][active]" value="1" checked', $data['html']);
        self::assertStringContainsString('name="options[1][stock]" value="9999"', $data['html']);
        self::assertSame($product, $this->shop->products->get($seed['a']));

        // 빈 그룹으로 표를 비울 수 있고, 입력값은 HTML로 실행되지 않게 이스케이프한다.
        $response = $this->combineAjax('new', $this->csrf([]));
        self::assertSame('', trim(json_decode($this->body($response), true, 512, JSON_THROW_ON_ERROR)['html']));
        $response = $this->combineAjax('new', $this->csrf(['option_group' => [1 => '색상'], 'option_values' => [1 => 'A&B']]));
        $html = json_decode($this->body($response), true, 512, JSON_THROW_ON_ERROR)['html'];
        self::assertStringContainsString('A&amp;B', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testAjaxCombinationsValidateInputAndRequireAdminAndCsrf(array $config): void
    {
        $this->setupShop($config);
        $this->signIn(true);
        $cases = [
            ['option_group' => [1 => '색상']],
            ['option_group' => [1 => '색상'], 'option_values' => [1 => '<img src=x onerror=alert(1)>']],
            ['option_group' => [2 => '크기'], 'option_values' => [2 => 'S']],
            ['option_group' => [1 => '색상'], 'option_values' => [1 => implode(',', range(1, 21))]],
            ['option_group' => [1 => '색상', 2 => '크기', 3 => '소재'], 'option_values' => array_fill(1, 3, implode(',', range(1, 11)))],
        ];
        foreach ($cases as $input) {
            $response = $this->combineAjax('new', $this->csrf($input));
            self::assertSame(422, $response->getStatusCode());
            $data = json_decode($this->body($response), true, 512, JSON_THROW_ON_ERROR);
            self::assertNotEmpty($data['error']['message']);
            self::assertArrayNotHasKey('html', $data);
        }
        self::assertSame(403, $this->combineAjax('new', [])->getStatusCode());
        self::assertSame(404, $this->combineAjax('edit', $this->csrf(['id' => '999']))->getStatusCode());
        $this->signIn(false);
        self::assertSame(403, $this->combineAjax('new', $this->csrf([]))->getStatusCode());
        session_start(); $_SESSION = []; session_write_close();
        $response = $this->combineAjax('new', []);
        self::assertSame(401, $response->getStatusCode());
        self::assertArrayHasKey('error', json_decode($this->body($response), true, 512, JSON_THROW_ON_ERROR));
    }
}
