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
use GnuCms\View\PhpView;
use GnuCms\View\TwigView;
use GnuCms\Web\Middleware\ViewMiddleware;
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

        // 컨트롤러는 이 View 만 안다. Twig 를 아는 곳은 TwigView 와 이 조립 코드뿐이다.
        $routeParser = $slim->getRouteCollector()->getRouteParser();
        if ($themes->engine() === 'php') {
            $view = new PhpView(
                $themes->phpTemplatePaths(),
                $routeParser,
                $basePath,
                static fn (string $path): string => $themes->assetUrl($path, $basePath),
                [$app->contentRenderer(), 'render']
            );
        } else {
            $twig = Twig::create($themes->templatePaths(), [
                // 이 애플리케이션은 템플릿 파일 캐시를 사용하지 않는다.
                'cache'            => false,
                'strict_variables' => true,
                'autoescape'       => 'html',
            ]);
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
            $view = new TwigView($twig, $routeParser, $basePath);
        }

        // 아래 addGlobal 들은 두 엔진에 공통이다.
        $view->addGlobal('current_user', [
            'is_guest' => true, 'id' => null, 'display_name' => null, 'is_admin' => false,
        ]);
        $view->addGlobal('csrf_token', '');
        $view->addGlobal('unread_notifications', 0);
        $view->addGlobal('oauth_providers', $app->providerRegistry()->options());
        $registrationAvailable = (bool) $site['registration_enabled'];
        $legalDocuments = [];
        // 가입 화면에 붙는 동의 항목 전부. 개수 제한이 없고, 없으면 빈 배열이다.
        $consentDocuments = [];
        // 사이트 하단에 늘어놓을 공개 약관 전부. 사용처와 무관하다.
        $legalPages = [];
        try {
            $legalPages = $app->cmsService()->publishedConsentPages();
            // 동의 항목을 먼저 읽는다. 씨앗 약관이 아직 없어 legalDocuments() 가
            // 튕겨도, 이미 붙여 둔 항목까지 함께 사라지지는 않는다.
            $consentDocuments = $app->cmsService()->consentDocuments('signup');
            if ($app->users()->countAll() > 0) {
                $legalDocuments = $app->cmsService()->legalDocuments();
            }
        } catch (DomainError $e) {
            // 표가 아직 없는 반쯤 적용된 DB 에서 모든 화면이 죽으면 안 된다.
            $registrationAvailable = false;
        }
        $view->addGlobal('site', $site);
        $view->addGlobal('registration_available', $registrationAvailable);
        $view->addGlobal('legal_documents', $legalDocuments);
        $view->addGlobal('consent_documents', $consentDocuments);
        $view->addGlobal('legal_pages', $legalPages);
        $view->addGlobal('site_menu', $app->cmsService()->menu());
        $view->addGlobal('base_path', $basePath);
        $view->addGlobal('active_theme', $themes->name());
        $view->addGlobal('available_themes', $themes->availableThemes());
        // 사람이 보는 이름은 site.site_name 이 앞서고, GNUCMS 는 그 기본값이다.
        $view->addGlobal('GNUCMS', GNUCMS);
        $view->addGlobal('GNUCMS_ID', GNUCMS_ID);

        if ($view instanceof TwigView) {
            $slim->add(TwigMiddleware::create($slim, $view->twig()));
        }
        $slim->add(new ViewMiddleware($view));
        $slim->addRoutingMiddleware();
        $slim->add(new ErrorPageMiddleware(
            $view,
            (bool) $app->config('debug', false),
            $app->config('log.file') === null ? null : (string) $app->config('log.file')
        ));
        $slim->add(new HtmlContentTypeMiddleware());
        $slim->add(new SessionGuard($app, $view));
        $slim->addBodyParsingMiddleware();

        Routes::register($slim, $app);

        return $slim;
    }
}
