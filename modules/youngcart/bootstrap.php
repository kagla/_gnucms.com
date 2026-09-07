<?php

declare(strict_types=1);

use GnuCms\Extension\Context;
use GnuCms\Modules\YoungCart\Service;
use GnuCms\View\View;
use Slim\Routing\RouteContext;

require_once __DIR__ . '/autoload.php';

return static function (Context $context): void {
    $service = new Service($context->app);
    $notReady = static function ($request, $response) use ($context, $service) {
        $base = RouteContext::fromRequest($request)->getBasePath();
        $view = View::forExtension($request, 'youngcart', __DIR__ . '/templates');
        return $view->render($response, 'notready', [
            'admin' => $service->app->guestAcl()->identity()->isAdmin(),
            'admin_url' => $base . ($context->adminRoutePrefix ?? '/admin/shop'),
        ]);
    };
    foreach (['/', '/list', '/type', '/search', '/item', '/image'] as $path) {
        $context->route('GET', $path, $notReady);
    }
    $context->route('GET', '/', static fn ($request, $response) => $response, admin: true);
};
