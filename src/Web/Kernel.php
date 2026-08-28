<?php

declare(strict_types=1);

namespace GnuCms\Web;

use GnuCms\App;
use GnuCms\Db\Schema;
use GnuCms\Web\Middleware\ErrorPageMiddleware;
use GnuCms\Web\Middleware\HtmlContentTypeMiddleware;
use GnuCms\Web\Middleware\SessionGuard;
use GnuCms\Error\DomainError;
use GnuCms\Theme\ThemeManager;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Twig\TwigFilter;
use Twig\TwigFunction;

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

        // 배포 뒤 마이그레이션을 잊어도 화면이 통째로 멈추지 않도록 스스로 맞춘다.
        (new Schema($app->db()))->ensureCurrent();

        $site = $app->cmsService()->settings();
        $themes = new ThemeManager(
            $templateDir,
            dirname($templateDir) . '/public/themes',
            (string) $site['theme']
        );
        $site['theme'] = $themes->name();

        $twig = Twig::create($themes->templatePaths(), [
            // 이 애플리케이션은 템플릿 파일 캐시를 사용하지 않는다.
            'cache'            => false,
            'strict_variables' => true,
            'autoescape'       => 'html',
        ]);
        $twig->getEnvironment()->addGlobal('current_user', [
            'is_guest' => true, 'id' => null, 'display_name' => null, 'is_admin' => false,
        ]);
        $twig->getEnvironment()->addGlobal('csrf_token', '');
        $twig->getEnvironment()->addGlobal('unread_notifications', 0);
        $twig->getEnvironment()->addGlobal('oauth_providers', $app->providerRegistry()->options());
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
        $twig->getEnvironment()->addGlobal('active_theme', $themes->name());
        $twig->getEnvironment()->addGlobal('available_themes', $themes->availableThemes());
        $twig->getEnvironment()->addFunction(new TwigFunction(
            'theme_asset',
            static fn (string $path): string => $themes->assetUrl($path, $basePath)
        ));
        // 정화한 뒤 본문 사진을 축소본 + 원본 링크로 바꿔 내보낸다.
        $twig->getEnvironment()->addFilter(new TwigFilter(
            'cms_html',
            [$app->contentRenderer(), 'render'],
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
