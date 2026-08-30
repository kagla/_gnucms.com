<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/** 로그인한 사람이 자기 회원정보를 고친다. 관리자도 여기서 자기 것을 고친다. */
final class AccountController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->currentUser();
        return $this->render($request, $response, [
            'display_name' => $user['display_name'], 'email' => $user['email'],
        ], [], ($request->getQueryParams()['saved'] ?? '') === '1');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $user = $this->currentUser();
        $id = (int) $user['id'];
        try {
            $this->app->accountService()->updateProfile($id, $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->render($request, $response->withStatus(422), [
                'display_name' => $input['display_name'] ?? $user['display_name'], 'email' => $user['email'],
            ], $e->details(), false);
        }
        // 비밀번호를 바꾸면 session_epoch 가 올라가 다른 기기는 끊긴다. 방금 바꾼 이 세션은 이어 준다.
        if (isset($input['password']) && is_scalar($input['password']) && (string) $input['password'] !== '') {
            $fresh = $this->app->users()->findById($id);
            if ($fresh !== null) {
                $_SESSION['session_epoch'] = (int) $fresh['session_epoch'];
            }
        }
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('account.edit', [], ['saved' => '1']);
        return $response->withHeader('Location', $url)->withStatus(303);
    }

    private function currentUser(): array
    {
        $identity = $this->app->guestAcl()->identity();
        $user = $identity->isGuest() ? null : $this->app->users()->findById((int) $identity->sub());
        if ($user === null) {
            throw DomainError::unauthorized('로그인이 필요합니다.');
        }
        return $user;
    }

    private function render(ServerRequestInterface $request, ResponseInterface $response, array $values,
        array $errors, bool $saved): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'account/edit', [
            'values' => $values, 'errors' => $errors, 'saved' => $saved,
        ]);
    }

    private function input(ServerRequestInterface $request): array
    {
        $input = $request->getParsedBody();
        return is_array($input) ? $input : [];
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
