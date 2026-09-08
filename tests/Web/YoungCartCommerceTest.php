<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Extension\Catalog;
use GnuCms\Extension\Manager;
use GnuCms\Extension\StateStore;
use GnuCms\Modules\YoungCart\Service;
use GnuCms\Tests\Support\WebTestCase;
use GnuCms\Web\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;

require_once dirname(__DIR__, 2) . '/modules/youngcart/autoload.php';

final class YoungCartCommerceTest extends WebTestCase
{
    private App $app;
    private Service $shop;
    private array $product;
    private string $root;

    private function setupShop(array $config): void
    {
        session_name(GNUCMS_ID . '_session'); session_start(); $_SESSION = []; session_write_close();
        $this->root = sys_get_temp_dir() . '/gnucms-yc-commerce-' . bin2hex(random_bytes(8));
        $config['prefix'] = 'ycweb' . bin2hex(random_bytes(4)) . '_';
        $this->app = $this->makeApp($config, ['storage' => ['dir' => $this->root], 'uploads' => ['dir' => $this->root . '/uploads'], 'app' => ['url' => 'https://shop.example.test']]);
        (new Manager(new Catalog(dirname(__DIR__, 2)), new StateStore($this->root . '/extensions')))->setEnabledMany(['modules/youngcart' => true]);
        $this->shop = new Service($this->app); $this->shop->install();
        $category = $this->shop->categories->save(['code' => '10', 'name' => '생활용품', 'active' => '1', 'list_columns' => '4', 'list_rows' => '5', 'image_width' => '300', 'image_height' => '0']);
        $id = $this->shop->products->save(['code' => 'DEMO', 'name' => '테스트 상품', 'category_id' => $category, 'price' => '12000', 'stock' => '10', 'active' => '1', 'is_hit' => '1'], []);
        $this->product = $this->shop->products->get($id);
    }

    private function form(array $data): array { return $data + ['csrf_token' => $_SESSION['csrf_token'] ?? '']; }
    private function add(int $qty = 1): void
    {
        $this->get($this->app, '/shop/item', ['id' => 'DEMO']);
        self::assertSame(303, $this->post($this->app, '/shop/cart/add', $this->form(['product_id' => $this->product['id'], 'quantity' => $qty]))->getStatusCode());
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
        self::assertStringContainsString('value="테스트 주문자"', $this->body($response));
        self::assertStringNotContainsString($input['password'], $this->body($response));
        self::assertSame(0, $this->shop->orders->listing(null, '', 1, true)['total']);
        self::assertSame(1, count($_SESSION['yc_cart']));
        $response = $this->post($this->app, '/shop/checkout', $this->form(['checkout_token' => str_repeat('0', 64)]));
        self::assertSame(403, $response->getStatusCode());
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
        $status = $this->form(['id' => $order['id'], 'from' => 'pending', 'status' => 'confirmed']);
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
