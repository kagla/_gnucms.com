<?php

declare(strict_types=1);

namespace GnuCms\Web\Middleware;

use GnuCms\App;
use GnuCms\Auth\Identity;
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
            session_name(GNUCMS_ID . '_session');
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
            // 글·댓글이 내 것인지 화면에서 가리려면 작성자 id 와 견줄 값이 필요하다.
            'id' => $identity->sub(),
            'display_name' => $identity->displayName(),
            'is_admin' => $identity->isAdmin(),
        ]);
        $this->twig->getEnvironment()->addGlobal('csrf_token', $_SESSION['csrf_token']);
        $this->twig->getEnvironment()->addGlobal('unread_notifications', $this->unreadCount());

        try {
            return $handler->handle($request);
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
    }

    /** 머리글의 알림 배지에 쓴다. 어떤 이유로든 세지 못하면 배지를 감춘다. */
    private function unreadCount(): int
    {
        try {
            return $this->app->notificationService()->unreadCount($this->app->guestAcl());
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
