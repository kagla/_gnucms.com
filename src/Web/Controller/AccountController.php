<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use GnuCms\Support\IpAddress;
use Psr\Http\Message\UploadedFileInterface;

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
            'id' => $user['id'], 'display_name' => $user['display_name'], 'email' => $user['email'],
            'avatar_file' => $user['avatar_file'] ?? null,
        ], [], ($request->getQueryParams()['saved'] ?? '') === '1',
            ($request->getQueryParams()['mail'] ?? '') === 'failed', $user['password_hash'] !== null);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $user = $this->currentUser();
        $id = (int) $user['id'];
        $newAvatar = null;
        try {
            $upload = $request->getUploadedFiles()['profile_image'] ?? null;
            if ($upload instanceof UploadedFileInterface && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
                $newAvatar = $this->app->avatars()->storeUpload($upload);
            }
            $this->app->accountService()->updateProfile($id, $input);
        } catch (DomainError $e) {
            $this->app->avatars()->delete($newAvatar);
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->render($request, $response->withStatus(422), [
                'id' => $user['id'], 'display_name' => $input['display_name'] ?? $user['display_name'],
                'email' => $user['email'], 'avatar_file' => $user['avatar_file'] ?? null,
            ], $e->details(), false, false, $user['password_hash'] !== null);
        } catch (\Throwable $e) {
            $this->app->avatars()->delete($newAvatar);
            throw $e;
        }
        $removeAvatar = !empty($input['remove_profile_image']);
        if ($newAvatar !== null || $removeAvatar) {
            try {
                $this->app->users()->updateAvatar($id, $newAvatar, $newAvatar === null ? null : 'upload');
            } catch (\Throwable $e) {
                $this->app->avatars()->delete($newAvatar);
                throw $e;
            }
            $this->app->avatars()->delete(isset($user['avatar_file']) ? (string) $user['avatar_file'] : null);
        }
        $query = ['saved' => '1'];
        // 비밀번호를 바꾸면 session_epoch 가 올라가 다른 기기는 끊긴다. 방금 바꾼 이 세션은 이어 준다.
        if (isset($input['password']) && is_scalar($input['password']) && (string) $input['password'] !== '') {
            $fresh = $this->app->users()->findById($id);
            if ($fresh !== null) {
                $_SESSION['session_epoch'] = (int) $fresh['session_epoch'];
            }
            if (!$this->app->accountService()->notifyPasswordChanged($id)) {
                $query['mail'] = 'failed';
            }
        }
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('account.edit', [], $query);
        return $response->withHeader('Location', $url)->withStatus(303);
    }

    public function withdraw(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $user = $this->currentUser();
        $id = (int) $user['id'];
        $reauth = $this->socialReauthenticated($id);
        try {
            $this->app->accountService()->withdraw(
                $id, $input, IpAddress::fromServer($request->getServerParams()), $reauth
            );
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->render($request, $response->withStatus(422), [
                'id' => $id, 'display_name' => $user['display_name'], 'email' => $user['email'],
                'avatar_file' => $user['avatar_file'] ?? null,
            ], $e->details(), false, false, $user['password_hash'] !== null);
        }

        $this->app->avatars()->delete(isset($user['avatar_file']) ? (string) $user['avatar_file'] : null);

        unset($_SESSION['user_id'], $_SESSION['session_epoch'], $_SESSION['withdraw_reauth']);
        session_regenerate_id(true);
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('account.withdrawn');
        return $response->withHeader('Location', $url)->withStatus(303);
    }

    public function withdrawn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'account/withdrawn');
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
        array $errors, bool $saved, bool $mailFailed, bool $hasPassword): ResponseInterface
    {
        $labels = ['google' => 'Google', 'naver' => '네이버', 'kakao' => '카카오'];
        $identities = $this->app->identities()->listForUser((int) $values['id']);
        foreach ($identities as &$identity) {
            $key = (string) $identity['provider'];
            $identity['label'] = $labels[$key] ?? ucfirst($key);
        }
        unset($identity);
        return View::fromRequest($request)->render($response, 'account/edit', [
            'values' => $values, 'errors' => $errors, 'saved' => $saved, 'mail_failed' => $mailFailed,
            'has_password' => $hasPassword,
            'social_identities' => $identities,
            'withdraw_reauthenticated' => $this->socialReauthenticated((int) $values['id']),
        ]);
    }

    private function socialReauthenticated(int $userId): bool
    {
        $reauth = $_SESSION['withdraw_reauth'] ?? null;
        if (!is_array($reauth) || (int) ($reauth['user_id'] ?? 0) !== $userId
            || (int) ($reauth['expires_at'] ?? 0) < time()) {
            unset($_SESSION['withdraw_reauth']);
            return false;
        }
        return true;
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
