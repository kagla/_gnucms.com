<?php
declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

final class EmailNotificationController
{
    public function __construct(private App $app) {}

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $identity = $this->app->guestAcl()->identity();
        $user = $identity->isGuest() ? null : $this->app->users()->findById((int) $identity->sub());
        if ($user === null || $user['status'] !== 'active') throw DomainError::unauthorized('로그인이 필요합니다.');
        $this->app->users()->updateEmailNotifications((int) $user['id'], ($input['email_notifications'] ?? '') === '1');
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('account.edit', [], ['saved' => '1']);
        return $response->withHeader('Location', $url . '#email-notifications')->withStatus(303);
    }

    /** GET은 확인 화면만 연다. 메일 보안 스캐너가 링크를 방문해도 설정은 바뀌지 않는다. */
    public function unsubscribeForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $raw = $request->getQueryParams()['token'] ?? '';
        $token = is_string($raw) ? $raw : '';
        $user = $this->app->mailPreferences()->userForToken($token);
        return View::fromRequest($request)->render($response->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer'), 'notifications/email_unsubscribe', [
                'token' => $token, 'unsubscribed' => (int) $user['email_notifications'] === 0,
            ]);
    }

    public function unsubscribe(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $token = is_string($input['token'] ?? null) ? $input['token'] : '';
        $user = $this->app->mailPreferences()->userForToken($token);
        $this->app->users()->updateEmailNotifications((int) $user['id'], false);
        return View::fromRequest($request)->render($response->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer'), 'notifications/email_unsubscribe', [
                'token' => '', 'unsubscribed' => true,
            ]);
    }

    private function input(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function assertCsrf(array $input): void
    {
        $expected = $_SESSION['csrf_token'] ?? '';
        $given = $input['csrf_token'] ?? '';
        if (!is_string($expected) || $expected === '' || !is_string($given) || !hash_equals($expected, $given)) {
            throw DomainError::forbidden('요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }
    }
}
