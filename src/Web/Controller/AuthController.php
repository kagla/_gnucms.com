<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Account\ConsentTrace;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

final class AuthController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function loginForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Twig::fromRequest($request)->render($response, 'auth/login.html.twig', ['errors' => [], 'values' => []]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        try {
            $user = $this->app->accountService()->authenticate($input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return Twig::fromRequest($request)->render(
                $response->withStatus(422),
                'auth/login.html.twig',
                ['errors' => $e->details(), 'values' => ['email' => $input['email'] ?? '']]
            );
        }
        $this->storeSession($user);

        return $this->homeRedirect($request, $response);
    }

    public function registerForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertRegistrationEnabled();
        return Twig::fromRequest($request)->render($response, 'auth/register.html.twig', [
            'errors' => [], 'values' => [], 'legal' => $this->registrationLegal(),
        ]);
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertRegistrationEnabled();
        $input = $this->input($request);
        $this->assertCsrf($input);
        try {
            $user = $this->app->accountService()->register($input, $this->consentTrace($request));
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            $values = ['email' => $input['email'] ?? ''];
            foreach ($this->app->cmsService()->consentDocuments('signup') as $doc) {
                $values['agree_' . $doc['id']] = isset($input['agree_' . $doc['id']]);
            }
            return Twig::fromRequest($request)->render(
                $response->withStatus(422),
                'auth/register.html.twig',
                ['errors' => $e->details(), 'values' => $values, 'legal' => $this->registrationLegal()]
            );
        }
        if ($user['newly_created'] && $user['is_admin'] && $user['email_verified']) {
            $this->storeSession($user);
            return $this->redirectTo($request, $response, 'admin.index');
        }
        return Twig::fromRequest($request)->render($response, 'auth/check_email.html.twig');
    }

    private function assertRegistrationEnabled(): void
    {
        if (!$this->app->cmsService()->settings()['registration_enabled']) {
            throw DomainError::forbidden('현재 새 회원가입을 받지 않습니다.');
        }
    }

    private function registrationLegal(): ?array
    {
        return $this->app->users()->countAll() === 0 ? null : $this->app->cmsService()->legalDocuments();
    }

    public function verifyEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $request->getQueryParams()['token'] ?? '';
        $this->app->accountService()->verifyEmail(is_scalar($token) ? (string) $token : '');
        return Twig::fromRequest($request)->render($response, 'auth/verified.html.twig');
    }

    public function forgotForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Twig::fromRequest($request)->render($response, 'auth/forgot.html.twig');
    }

    public function forgot(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $email = isset($input['email']) && is_scalar($input['email']) ? (string) $input['email'] : '';
        $this->app->accountService()->requestPasswordReset($email);
        return Twig::fromRequest($request)->render($response, 'auth/reset_sent.html.twig');
    }

    public function resetForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $request->getQueryParams()['token'] ?? '';
        return Twig::fromRequest($request)->render($response, 'auth/reset.html.twig', [
            'token' => is_scalar($token) ? (string) $token : '',
            'errors' => [],
        ]);
    }

    public function reset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        try {
            $this->app->accountService()->resetPassword($input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return Twig::fromRequest($request)->render($response->withStatus(422), 'auth/reset.html.twig', [
                'token' => isset($input['token']) && is_scalar($input['token']) ? (string) $input['token'] : '',
                'errors' => $e->details(),
            ]);
        }
        unset($_SESSION['user_id'], $_SESSION['session_epoch']);
        return Twig::fromRequest($request)->render($response, 'auth/reset_done.html.twig');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        unset($_SESSION['user_id'], $_SESSION['session_epoch']);
        session_regenerate_id(true);

        return $this->homeRedirect($request, $response);
    }

    private function input(ServerRequestInterface $request): array
    {
        $input = $request->getParsedBody();
        return is_array($input) ? $input : [];
    }

    /** 동의 증적. 프록시를 신뢰하지 않으므로 REMOTE_ADDR 만 쓴다. */
    private function consentTrace(ServerRequestInterface $request): ConsentTrace
    {
        $server = $request->getServerParams();
        $ip = isset($server['REMOTE_ADDR']) && is_scalar($server['REMOTE_ADDR'])
            ? (string) $server['REMOTE_ADDR'] : null;
        $ua = $request->getHeaderLine('User-Agent');

        return new ConsentTrace($ip, $ua === '' ? null : $ua);
    }

    private function assertCsrf(array $input): void
    {
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        $given = isset($input['csrf_token']) && is_scalar($input['csrf_token']) ? (string) $input['csrf_token'] : '';
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw DomainError::forbidden('요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }
    }

    private function storeSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['session_epoch'] = $user['session_epoch'];
    }

    private function homeRedirect(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->redirectTo($request, $response, 'boards.index');
    }

    private function redirectTo(ServerRequestInterface $request, ResponseInterface $response, string $route): ResponseInterface
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($route);
        return $response->withHeader('Location', $url)->withStatus(303);
    }
}
