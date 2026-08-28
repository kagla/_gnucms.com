<?php

declare(strict_types=1);

namespace ApiBoard\Web\Middleware;

use ApiBoard\App;
use ApiBoard\Auth\Identity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

final class SessionGuard implements MiddlewareInterface
{
    private App $app;
    private Twig $twig;

    public function __construct(App $app, Twig $twig)
    {
        $this->app = $app;
        $this->twig = $twig;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('aboard_session');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $request->getUri()->getScheme() === 'https',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        $identity = Identity::guest();
        if (isset($_SESSION['user_id'], $_SESSION['session_epoch'])) {
            $identity = $this->app->accountService()->identityForSession(
                (int) $_SESSION['user_id'],
                (int) $_SESSION['session_epoch']
            );
            if ($identity->isGuest()) {
                unset($_SESSION['user_id'], $_SESSION['session_epoch']);
            }
        }
        $this->app->setIdentity($identity);

        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $this->twig->getEnvironment()->addGlobal('current_user', [
            'is_guest' => $identity->isGuest(),
            'display_name' => $identity->displayName(),
            'is_admin' => $identity->isAdmin(),
        ]);
        $this->twig->getEnvironment()->addGlobal('csrf_token', $_SESSION['csrf_token']);

        try {
            return $handler->handle($request);
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
    }
}
