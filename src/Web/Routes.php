<?php

declare(strict_types=1);

namespace ApiBoard\Web;

use ApiBoard\App;
use ApiBoard\Web\Controller\BoardController;
use ApiBoard\Web\Controller\AuthController;
use ApiBoard\Web\Controller\OauthController;
use ApiBoard\Web\Controller\AdminController;
use ApiBoard\Web\Controller\FileController;
use ApiBoard\Web\Controller\PostController;
use ApiBoard\Web\Controller\PageController;
use ApiBoard\Web\Controller\AdminCmsController;
use ApiBoard\Web\Controller\CmsImageController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App as SlimApp;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

final class Routes
{
    public static function register(SlimApp $slim, App $app): void
    {
        $auth = new AuthController($app);
        $slim->get('/login', [$auth, 'loginForm'])->setName('auth.login');
        $slim->post('/login', [$auth, 'login']);
        $slim->get('/register', [$auth, 'registerForm'])->setName('auth.register');
        $slim->post('/register', [$auth, 'register']);
        $slim->get('/verify-email', [$auth, 'verifyEmail'])->setName('auth.verify');
        $slim->get('/forgot-password', [$auth, 'forgotForm'])->setName('auth.forgot');
        $slim->post('/forgot-password', [$auth, 'forgot']);
        $slim->get('/reset-password', [$auth, 'resetForm'])->setName('auth.reset');
        $slim->post('/reset-password', [$auth, 'reset']);
        $slim->post('/logout', [$auth, 'logout'])->setName('auth.logout');

        $oauth = new OauthController($app);
        $slim->post('/auth/email', [$oauth, 'email'])->setName('oauth.email');
        $slim->get('/auth/complete', [$oauth, 'complete'])->setName('oauth.complete');
        $slim->get('/auth/{provider:[a-z]+}', [$oauth, 'start'])->setName('oauth.start');
        $slim->get('/auth/{provider:[a-z]+}/callback', [$oauth, 'callback'])->setName('oauth.callback');

        $admin = new AdminController($app);
        $slim->get('/admin', [$admin, 'index'])->setName('admin.index');
        $slim->get('/admin/password', [$admin, 'passwordForm'])->setName('admin.password');
        $slim->post('/admin/password', [$admin, 'password']);
        $slim->get('/admin/boards', [$admin, 'boards'])->setName('admin.boards');
        $slim->get('/admin/boards/new', [$admin, 'createForm'])->setName('admin.boards.create');
        $slim->post('/admin/boards/new', [$admin, 'create']);
        $slim->get('/admin/boards/{key}/edit', [$admin, 'editForm'])->setName('admin.boards.edit');
        $slim->post('/admin/boards/{key}/edit', [$admin, 'update']);
        $slim->post('/admin/boards/{key}/delete', [$admin, 'delete'])->setName('admin.boards.delete');
        $slim->get('/admin/members', [$admin, 'members'])->setName('admin.members');
        $slim->get('/admin/members/{id:[0-9]+}/edit', [$admin, 'memberEditForm'])->setName('admin.members.edit');
        $slim->post('/admin/members/{id:[0-9]+}/edit', [$admin, 'memberUpdate']);
        $slim->post('/admin/members/{id:[0-9]+}/status', [$admin, 'toggleStatus'])->setName('admin.members.status');

        $cms = new AdminCmsController($app);
        $cmsImages = new CmsImageController($app);
        $slim->get('/admin/settings', [$cms, 'settingsForm'])->setName('admin.settings');
        $slim->post('/admin/settings', [$cms, 'settings']);
        $slim->get('/admin/mail', [$cms, 'mailForm'])->setName('admin.mail');
        $slim->post('/admin/mail', [$cms, 'mail']);
        $slim->post('/admin/mail/test', [$cms, 'mailTest'])->setName('admin.mail.test');
        $slim->get('/admin/content', [$cms, 'pages'])->setName('admin.content');
        $slim->get('/admin/content/trash', [$cms, 'trash'])->setName('admin.content.trash');
        $slim->post('/admin/content/trash/{id:[0-9]+}/restore', [$cms, 'restore'])->setName('admin.content.restore');
        $slim->post('/admin/content/trash/{id:[0-9]+}/delete', [$cms, 'permanentlyDelete'])
            ->setName('admin.content.permanent_delete');
        $slim->get('/admin/terms', [$cms, 'legal'])->setName('admin.terms');
        $slim->get('/admin/terms/{type:service|privacy}', [$cms, 'legalEditForm'])->setName('admin.terms.edit');
        $slim->post('/admin/terms/{type:service|privacy}', [$cms, 'legalUpdate']);
        $slim->get('/admin/terms/{type:service|privacy}/preview', [$cms, 'legalPreview'])->setName('admin.terms.preview');
        $slim->get('/admin/content/new', [$cms, 'createForm'])->setName('admin.content.create');
        $slim->post('/admin/content/new', [$cms, 'create']);
        $slim->post('/admin/terms/setup', [$cms, 'legalSetup'])->setName('admin.terms.setup');
        $slim->get('/admin/content/{id:[0-9]+}/edit', [$cms, 'editForm'])->setName('admin.content.edit');
        $slim->post('/admin/content/{id:[0-9]+}/edit', [$cms, 'update']);
        $slim->get('/admin/content/{id:[0-9]+}/preview', [$cms, 'preview'])->setName('admin.content.preview');
        $slim->post('/admin/content/{id:[0-9]+}/delete', [$cms, 'delete'])->setName('admin.content.delete');
        $slim->post('/admin/editor/images', [$cmsImages, 'upload'])->setName('admin.editor.images');
        $slim->post('/admin/editor/images/discard', [$cmsImages, 'discard'])
            ->setName('admin.editor.images.discard');
        $legacyContentRedirect = static function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ): ResponseInterface {
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content');
            return $response->withHeader('Location', $url)->withStatus(301);
        };
        $slim->get('/admin/pages', $legacyContentRedirect);
        $slim->get('/admin/documents', $legacyContentRedirect);
        $slim->get('/admin/legal', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.terms');
            return $response->withHeader('Location', $url)->withStatus(301);
        });
        foreach (['terms' => 'service', 'privacy' => 'privacy'] as $oldType => $newType) {
            $slim->get('/admin/legal/' . $oldType, static function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($newType): ResponseInterface {
                $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.terms.edit', ['type' => $newType]);
                return $response->withHeader('Location', $url)->withStatus(301);
            });
        }

        $slim->get('/health', static function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($app): ResponseInterface {
            return Twig::fromRequest($request)->render($response, 'health.html.twig', [
                'dialect' => $app->db()->dialect()->name(),
            ]);
        });

        $slim->get('/', [new BoardController($app), 'index'])->setName('boards.index');
        $pageController = new PageController($app);
        $slim->get('/terms/{type:service|privacy}', [$pageController, 'legal'])->setName('terms.show');
        foreach (['terms' => 'service', 'privacy' => 'privacy'] as $legacyLegalSlug => $newType) {
            $legacyLegalRedirect = static function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($newType): ResponseInterface {
                $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('terms.show', [
                    'type' => $newType,
                ]);
                return $response->withHeader('Location', $url)->withStatus(301);
            };
            $slim->get('/' . $legacyLegalSlug, $legacyLegalRedirect);
            $slim->get('/content/' . $legacyLegalSlug, $legacyLegalRedirect);
        }
        $slim->get('/content/{slug:[a-z0-9][a-z0-9_-]*}', [$pageController, 'show'])->setName('content.show');
        $slim->get('/media/editor/{key:[a-f0-9]{32}}/{file:[a-f0-9]+\.(?:jpg|png|gif|webp)}',
            [$cmsImages, 'showOwned'])->setName('editor.owned_image');
        $slim->get('/media/editor/{year:[0-9]+}/{month:[0-9]+}/{file:[a-f0-9]+\.(?:jpg|png|gif|webp)}',
            [$cmsImages, 'show'])->setName('editor.image');
        $slim->get('/page/{slug:[a-z0-9][a-z0-9_-]*}', static function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ): ResponseInterface {
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('content.show', [
                'slug' => (string) $args['slug'],
            ]);
            return $response->withHeader('Location', $url)->withStatus(301);
        });
        $posts = new PostController($app);
        $slim->get('/boards/{key}', [$posts, 'index'])->setName('posts.index');
        $slim->get('/boards/{key}/write', [$posts, 'createForm'])->setName('posts.create');
        $slim->post('/boards/{key}/write', [$posts, 'create']);
        $slim->get('/b/{key}', static function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ): ResponseInterface {
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('posts.index', [
                'key' => (string) $args['key'],
            ]);
            $query = $request->getUri()->getQuery();
            return $response->withHeader('Location', $url . ($query === '' ? '' : '?' . $query))->withStatus(301);
        });
        $slim->get('/b/{key}/write', static function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ): ResponseInterface {
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('posts.create', [
                'key' => (string) $args['key'],
            ]);
            return $response->withHeader('Location', $url)->withStatus(301);
        });
        $slim->post('/b/{key}/write', [$posts, 'create']);
        $files = new FileController($app);
        $slim->get('/posts/{id:[0-9]+}', [$posts, 'show'])->setName('posts.show');
        $slim->get('/posts/{id:[0-9]+}/files/{index:[0-9]+}', [$files, 'download'])
            ->setName('files.download');
        $slim->get('/p/{id:[0-9]+}', static function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ): ResponseInterface {
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('posts.show', [
                'id' => (string) $args['id'],
            ]);
            $query = $request->getUri()->getQuery();
            return $response->withHeader('Location', $url . ($query === '' ? '' : '?' . $query))->withStatus(301);
        });
        $slim->get('/p/{id:[0-9]+}/files/{index:[0-9]+}', static function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ): ResponseInterface {
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('files.download', [
                'id' => (string) $args['id'],
                'index' => (string) $args['index'],
            ]);
            return $response->withHeader('Location', $url)->withStatus(301);
        });
    }
}
