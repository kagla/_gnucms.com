<?php

declare(strict_types=1);

namespace GnuCms\Shop;

use GnuCms\App;
use GnuCms\Extension\ExternalRequests;
use GnuCms\Shop\Admin\AdminController;
use GnuCms\Shop\Admin\CategoryController;
use GnuCms\Shop\Admin\OrderController;
use GnuCms\Shop\Admin\ProductController;
use GnuCms\Shop\Admin\ProductFormController;
use GnuCms\Shop\Web\CommerceController;
use GnuCms\Shop\Web\PayController;
use GnuCms\Shop\Web\ShopController;
use GnuCms\Web\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App as SlimApp;

/** 쇼핑몰 라우트. 모듈 시절 bootstrap.php 가 하던 등록을 코어가 한다. */
final class Routes
{
    public static function register(SlimApp $slim, App $app): void
    {
        $service = $app->shop();
        $shop = new ShopController($service, Service::PUBLIC_PREFIX, Service::ADMIN_PREFIX);
        $commerce = new CommerceController($service, Service::PUBLIC_PREFIX, Service::ADMIN_PREFIX);
        $pay = new PayController($service, Service::PUBLIC_PREFIX, Service::ADMIN_PREFIX);
        $orders = new OrderController($service, Service::PUBLIC_PREFIX, Service::ADMIN_PREFIX);
        $admin = new AdminController($service, Service::PUBLIC_PREFIX, Service::ADMIN_PREFIX);
        $category = new CategoryController($service, Service::PUBLIC_PREFIX, Service::ADMIN_PREFIX);
        $product = new ProductController($service, Service::PUBLIC_PREFIX, Service::ADMIN_PREFIX);
        $productForm = new ProductFormController($service, Service::PUBLIC_PREFIX, Service::ADMIN_PREFIX);

        // 관리자 라우트는 전역 관리자만, POST 는 CSRF 를 지난다 — 모듈 컨텍스트가 하던 래퍼 그대로.
        $map = static function (string $method, string $path, callable $handler, bool $admin = false) use ($slim, $app): \Slim\Interfaces\RouteInterface {
            $wrapped = static function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($handler, $admin, $method, $app): ResponseInterface {
                if ($admin) $app->guestAcl()->assertGlobalAdmin();
                if ($method === 'POST') Csrf::assert($request);
                return $handler($request, $response, $args);
            };
            $prefix = $admin ? Service::ADMIN_PREFIX : Service::PUBLIC_PREFIX;
            return $slim->map([$method], $prefix . ($path === '/' ? '' : $path), $wrapped);
        };

        $map('GET', '/', static fn ($request, $response) => $shop->handle('index', $request, $response))->setName('shop.index');
        foreach (['list', 'type', 'search', 'item', 'image', 'banner-image'] as $page) {
            $map('GET', '/' . $page, static fn ($request, $response) => $shop->handle($page, $request, $response));
        }
        foreach (['cart', 'checkout', 'orders'] as $page) foreach (['GET', 'POST'] as $method) {
            $map($method, '/' . $page, static fn ($request, $response) => $commerce->handle($page, $request, $response));
        }
        $map('GET', '/order', static fn ($request, $response) => $commerce->handle('order', $request, $response));
        foreach (['cart/add', 'order/cancel'] as $page) {
            $map('POST', '/' . $page, static fn ($request, $response) => $commerce->handle($page, $request, $response));
        }
        $map('GET', '/pay', static fn ($request, $response) => $pay->show($request, $response));

        $map('GET', '/', static fn ($request, $response) => $admin->handle('dashboard', $request, $response), true)->setName('admin.shop');
        // 모듈 시절에는 끝에 빗금이 붙은 주소도 같은 화면이었다. 남은 즐겨찾기를 한 주소로 모은다.
        foreach ([Service::PUBLIC_PREFIX, Service::ADMIN_PREFIX] as $prefix) {
            $slim->map(['GET'], $prefix . '/', static fn ($request, $response) => $response->withStatus(301)->withHeader('Location', $slim->getBasePath() . $prefix));
        }
        foreach (['GET', 'POST'] as $method) {
            $map($method, '/settings', static fn ($request, $response) => $admin->handle('settings', $request, $response), true);
            $map($method, '/orders/detail', static fn ($request, $response) => $orders->handle('orders/detail', $request, $response), true);
            foreach (['categories', 'categories/new', 'categories/edit'] as $page) {
                $map($method, '/' . $page, static fn ($request, $response) => $category->handle($page, $request, $response), true);
            }
            foreach (['products', 'products/types', 'products/stock', 'products/option-stock'] as $page) {
                $map($method, '/' . $page, static fn ($request, $response) => $product->handle($page, $request, $response), true);
            }
            foreach (['products/new', 'products/edit'] as $page) {
                $map($method, '/' . $page, static fn ($request, $response) => $productForm->handle($page, $request, $response), true);
            }
        }
        $map('GET', '/orders', static fn ($request, $response) => $orders->handle('orders', $request, $response), true);
        $map('POST', '/products/copy', static fn ($request, $response) => $product->handle('products/copy', $request, $response), true);
        $map('GET', '/products/search', static fn ($request, $response) => $product->handle('products/search', $request, $response), true);

        // 이니시스 인증 결과 콜백(폼). 세션 없이 쿼리의 HMAC 으로만 인증한다.
        $slim->add(new ExternalRequests([
            Service::PUBLIC_PREFIX . '/pay/callback' => [[$pay, 'callbackAuthenticate'], [$pay, 'callback'], 65536, 'application/x-www-form-urlencoded'],
        ], $slim->getBasePath()));
    }
}
