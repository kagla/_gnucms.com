<?php

declare(strict_types=1);

namespace ApiBoard\Web;

use ApiBoard\App;
use ApiBoard\Web\Middleware\ErrorPageMiddleware;
use ApiBoard\Web\Middleware\HtmlContentTypeMiddleware;
use ApiBoard\Web\Middleware\SessionGuard;
use ApiBoard\Error\DomainError;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Twig\TwigFilter;

/**
 * Slim 앱을 조립한다. 미들웨어는 나중에 add 한 것이 바깥이므로
 * 오류 미들웨어를 마지막에 넣어 전부를 감싸게 한다.
 */
final class Kernel
{
    public static function create(App $app, string $templateDir, string $basePath): SlimApp
    {
        $slim = AppFactory::create();
        $slim->setBasePath($basePath);

        $twig = Twig::create($templateDir, [
            // 이 애플리케이션은 템플릿 파일 캐시를 사용하지 않는다.
            'cache'            => false,
            'strict_variables' => true,
            'autoescape'       => 'html',
        ]);
        $twig->getEnvironment()->addGlobal('current_user', [
            'is_guest' => true, 'display_name' => null, 'is_admin' => false,
        ]);
        $twig->getEnvironment()->addGlobal('csrf_token', '');
        $twig->getEnvironment()->addGlobal('oauth_providers', $app->providerRegistry()->options());
        $site = $app->cmsService()->settings();
        $registrationAvailable = (bool) $site['registration_enabled'];
        $legalDocuments = [];
        try {
            $hasOwner = $app->users()->countAll() > 0;
            if ($hasOwner) {
                $legalDocuments = $app->cmsService()->legalDocuments();
            }
        } catch (DomainError $e) {
            $registrationAvailable = false;
        }
        $twig->getEnvironment()->addGlobal('site', $site);
        $twig->getEnvironment()->addGlobal('registration_available', $registrationAvailable);
        $twig->getEnvironment()->addGlobal('legal_documents', $legalDocuments);
        $twig->getEnvironment()->addGlobal('site_menu', $app->cmsService()->menu());
        $twig->getEnvironment()->addGlobal('base_path', $basePath);
        $twig->getEnvironment()->addFilter(new TwigFilter(
            'cms_html',
            [$app->htmlSanitizer(), 'clean'],
            ['is_safe' => ['html']]
        ));

        $slim->add(TwigMiddleware::create($slim, $twig));
        $slim->addRoutingMiddleware();
        $slim->add(new ErrorPageMiddleware(
            $twig,
            (bool) $app->config('debug', false),
            $app->config('log.file') === null ? null : (string) $app->config('log.file'),
            $slim->getRouteCollector()->getRouteParser(),
            $basePath
        ));
        $slim->add(new HtmlContentTypeMiddleware());
        $slim->add(new SessionGuard($app, $twig));
        $slim->addBodyParsingMiddleware();

        Routes::register($slim, $app);

        return $slim;
    }
}
