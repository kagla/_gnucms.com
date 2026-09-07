<?php

declare(strict_types=1);

use GnuCms\Extension\Context;
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
    $context->route('GET', '/', static fn ($request, $response) => $response, admin: true);
};
