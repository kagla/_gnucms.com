<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use GnuCms\View\View;

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

        return View::fromRequest($request)->render($response, 'notifications/index', [
            'notifications' => $this->app->notificationService()->listFor($this->app->guestAcl(), $page),
        ]);
    }

    /** 알림을 눌렀을 때. 읽음으로 바꾸고 해당 댓글 자리로 보낸다. */
    public function open(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $target = $this->app->notificationService()->open($this->app->guestAcl(), (int) $args['id']);

        if (isset($target['account'])) {
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('account.edit');
            return $response->withHeader('Location', $url)->withStatus(303);
        }
        if (isset($target['inquiry'])) {
            return $response->withHeader('Location', $this->inquiryUrl($request, $target['inquiry']))->withStatus(303);
        }
        if (isset($target['order_number'])) {
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('shop.order')
                . '?number=' . rawurlencode($target['order_number']);
            return $response->withHeader('Location', $url)->withStatus(303);
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor('posts.show', ['id' => (string) $target['post_id']]);
        $fragment = $target['comment_id'] === null ? '#comments' : '#comment-' . $target['comment_id'];

        return $response->withHeader('Location', $url . $fragment)->withStatus(303);
    }

    /** 문자 링크로 열어도 문의 소유권을 확인한 뒤 상품 화면으로 보낸다. */
    public function inquiry(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $identity = $this->app->guestAcl()->identity();
        if ($identity->isGuest()) throw DomainError::unauthorized('로그인이 필요합니다.');
        $target = (new \GnuCms\Repository\NotificationRepository($this->app->db()))->ownedInquiry((int) $args['id'], $identity->sub());
        if ($target === null) throw DomainError::notFound('문의를 찾을 수 없습니다.');
        return $response->withHeader('Location', $this->inquiryUrl($request, $target))->withStatus(303);
    }

    private function inquiryUrl(ServerRequestInterface $request, array $target): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor('shop.index')
            . '/item?id=' . rawurlencode($target['code']) . '&inquiry_page=' . (int) $target['page'] . '#yc-inquiry-' . (int) $target['id'];
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
