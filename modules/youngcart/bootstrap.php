<?php

declare(strict_types=1);

use GnuCms\Extension\Context;
use GnuCms\Modules\YoungCart\Admin\AdminController;
use GnuCms\Modules\YoungCart\Admin\CategoryController;
use GnuCms\Modules\YoungCart\Admin\ProductController;
use GnuCms\Modules\YoungCart\Admin\ProductFormController;
use GnuCms\Modules\YoungCart\Service;
use GnuCms\Modules\YoungCart\Web\ShopController;

require_once __DIR__ . '/autoload.php';

return static function (Context $context): void {
    $service = new Service($context->app);
    $shop = new ShopController($service, $context->routePrefix, $context->adminRoutePrefix);
    $context->route('GET', '/', static fn ($request, $response) => $shop->handle('index', $request, $response));
    foreach (['list', 'type', 'search', 'item', 'image'] as $page) {
        $context->route('GET', '/' . $page, static fn ($request, $response) => $shop->handle($page, $request, $response));
    }
    $commerce = new \GnuCms\Modules\YoungCart\Web\CommerceController($service, $context->routePrefix, $context->adminRoutePrefix);
    foreach (['cart', 'checkout', 'orders'] as $page) foreach (['GET', 'POST'] as $method) {
        $context->route($method, '/' . $page, static fn ($request, $response) => $commerce->handle($page, $request, $response));
    }
    $context->route('GET', '/order', static fn ($request, $response) => $commerce->handle('order', $request, $response));
    foreach (['cart/add', 'order/cancel'] as $page) {
        $context->route('POST', '/' . $page, static fn ($request, $response) => $commerce->handle($page, $request, $response));
    }
    $orders = new \GnuCms\Modules\YoungCart\Admin\OrderController($service, $context->routePrefix, $context->adminRoutePrefix);
    $context->route('GET', '/orders', static fn ($request, $response) => $orders->handle('orders', $request, $response), admin: true);
    foreach (['GET', 'POST'] as $method) $context->route($method, '/orders/detail', static fn ($request, $response) => $orders->handle('orders/detail', $request, $response), admin: true);
    $admin = new AdminController($service, $context->routePrefix, $context->adminRoutePrefix);
    $context->route('GET', '/', static fn ($request, $response) => $admin->handle('dashboard', $request, $response), admin: true);
    $context->route('POST', '/', static fn ($request, $response) => $admin->handle('dashboard', $request, $response), admin: true);
    $context->route('GET', '/settings', static fn ($request, $response) => $admin->handle('settings', $request, $response), admin: true);
    $context->route('POST', '/settings', static fn ($request, $response) => $admin->handle('settings', $request, $response), admin: true);
    $category = new CategoryController($service, $context->routePrefix, $context->adminRoutePrefix);
    foreach (['categories', 'categories/new', 'categories/edit'] as $page) {
        foreach (['GET', 'POST'] as $method) {
            $context->route($method, '/' . $page, static fn ($request, $response) => $category->handle($page, $request, $response), admin: true);
        }
    }
    $product = new ProductController($service, $context->routePrefix, $context->adminRoutePrefix);
    foreach (['products', 'products/types', 'products/stock', 'products/option-stock'] as $page) {
        foreach (['GET', 'POST'] as $method) {
            $context->route($method, '/' . $page, static fn ($request, $response) => $product->handle($page, $request, $response), admin: true);
        }
    }
    $context->route('POST', '/products/copy', static fn ($request, $response) => $product->handle('products/copy', $request, $response), admin: true);
    $context->route('GET', '/products/search', static fn ($request, $response) => $product->handle('products/search', $request, $response), admin: true);
    $productForm = new ProductFormController($service, $context->routePrefix, $context->adminRoutePrefix);
    foreach (['products/new', 'products/edit'] as $page) {
        foreach (['GET', 'POST'] as $method) {
            $context->route($method, '/' . $page, static fn ($request, $response) => $productForm->handle($page, $request, $response), admin: true);
        }
    }
};
