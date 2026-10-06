<?php

declare(strict_types=1);

use GnuCms\Extension\Context;
use GnuCmsManual\ManualLibrary;
use GnuCmsManual\ManualController;

require_once __DIR__ . '/src/ManualLibrary.php';
require_once __DIR__ . '/src/ManualController.php';

return static function (Context $context): void {
    $library = new ManualLibrary(__DIR__ . '/content');
    $controller = new ManualController($library, $context->routePrefix, __DIR__ . '/templates');
    $context->route('GET', '/', [$controller, 'index']);
    $context->route('GET', '/search', [$controller, 'search']);
    foreach ($library->catalog()['articles'] as $article) {
        $slug = $article['slug'];
        $context->route('GET', '/' . $slug,
            static fn ($request, $response) => $controller->article($request, $response, $slug));
    }
};
