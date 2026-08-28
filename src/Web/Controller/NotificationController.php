<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

final class NotificationController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $page = isset($query['page']) && ctype_digit((string) $query['page']) ? (int) $query['page'] : 1;

        return Twig::fromRequest($request)->render($response, 'notifications/index.html.twig', [
            'notifications' => $this->app->notificationService()->listFor($this->app->guestAcl(), $page),
        ]);
    }

    /** 알림을 눌렀을 때. 읽음으로 바꾸고 해당 댓글 자리로 보낸다. */
    public function open(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $target = $this->app->notificationService()->open($this->app->guestAcl(), (int) $args['id']);

        $url = RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor('posts.show', ['id' => (string) $target['post_id']]);
        $fragment = $target['comment_id'] === null ? '#comments' : '#comment-' . $target['comment_id'];

        return $response->withHeader('Location', $url . $fragment)->withStatus(303);
    }

    public function readAll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $request->getParsedBody();
        $this->assertCsrf(is_array($input) ? $input : []);
        $this->app->notificationService()->markAllRead($this->app->guestAcl());

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('notifications.index');

        return $response->withHeader('Location', $url)->withStatus(303);
    }

    private function assertCsrf(array $input): void
    {
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        $given = isset($input['csrf_token']) && is_scalar($input['csrf_token']) ? (string) $input['csrf_token'] : '';
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw DomainError::forbidden('요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }
    }
}
