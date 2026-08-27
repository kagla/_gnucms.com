<?php

declare(strict_types=1);

namespace ApiBoard\Web;

use ApiBoard\App;
use ApiBoard\Web\Middleware\ErrorPageMiddleware;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

/**
 * Slim 앱을 조립한다. 미들웨어는 나중에 add 한 것이 바깥이므로
 * 오류 미들웨어를 마지막에 넣어 전부를 감싸게 한다.
 */
final class Kernel
{
    public static function create(App $app, string $templateDir, ?string $cacheDir, string $basePath): SlimApp
    {
        $slim = AppFactory::create();
        $slim->setBasePath($basePath);

        $twig = Twig::create($templateDir, [
            'cache'            => $cacheDir === null ? false : $cacheDir,
            'strict_variables' => true,
            'autoescape'       => 'html',
        ]);

        $slim->add(TwigMiddleware::create($slim, $twig));
        $slim->addRoutingMiddleware();
        $slim->add(new ErrorPageMiddleware(
            $twig,
            (bool) $app->config('debug', false),
            $app->config('log.file') === null ? null : (string) $app->config('log.file'),
            $slim->getRouteCollector()->getRouteParser(),
            $basePath
        ));

        Routes::register($slim, $app);

        return $slim;
    }
}
