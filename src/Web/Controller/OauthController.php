<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Oauth\SocialProfile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

final class OauthController
{
    private const TTL = 1800;

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function start(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $key = (string) ($args['provider'] ?? '');
        $provider = $this->app->providerRegistry()->get($key);
        $state = bin2hex(random_bytes(32));
        $_SESSION['oauth_states'][$key] = [
            'hash' => hash('sha256', $state),
            'expires_at' => time() + self::TTL,
        ];

        return $response->withHeader('Location', $provider->authorizationUrl($state))->withStatus(302);
    }

    public function callback(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $key = (string) ($args['provider'] ?? '');
        $query = $request->getQueryParams();
        $state = isset($query['state']) && is_scalar($query['state']) ? (string) $query['state'] : '';
        $stored = $_SESSION['oauth_states'][$key] ?? null;
        unset($_SESSION['oauth_states'][$key]);
        if (!is_array($stored) || (int) ($stored['expires_at'] ?? 0) < time() || $state === ''
            || !hash_equals((string) ($stored['hash'] ?? ''), hash('sha256', $state))) {
            throw DomainError::forbidden('소셜 로그인 요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }
        if (isset($query['error'])) {
            throw DomainError::validation(['oauth' => '소셜 로그인이 취소되었습니다.']);
        }
        $code = isset($query['code']) && is_scalar($query['code']) ? (string) $query['code'] : '';
        $profile = $this->app->socialAuthService()->profile($key, $code, $state);
        $user = $this->app->socialAuthService()->resolve($profile);
        if ($user !== null) {
            $this->storeSession($user);
            return $this->homeRedirect($request, $response);
        }

        $_SESSION['oauth_pending'] = [
            'profile' => $profile->toArray(),
            'expires_at' => time() + self::TTL,
        ];
        return Twig::fromRequest($request)->render($response, 'auth/social_email.html.twig', [
            'provider_label' => $this->app->providerRegistry()->get($key)->label(),
            'errors' => [],
            'values' => ['email' => $profile->email ?? ''],
        ]);
    }

    public function email(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $this->assertCsrf($input);
        $pending = $this->pending();
        $profile = SocialProfile::fromArray((array) $pending['profile']);
        $email = isset($input['email']) && is_scalar($input['email']) ? (string) $input['email'] : '';
        $token = bin2hex(random_bytes(32));
        try {
            $email = $this->app->socialAuthService()->sendPendingEmail($profile, $email, $token);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return Twig::fromRequest($request)->render($response->withStatus(422), 'auth/social_email.html.twig', [
                'provider_label' => $this->app->providerRegistry()->get($profile->provider)->label(),
                'errors' => $e->details(),
                'values' => ['email' => $email],
            ]);
        }
        $_SESSION['oauth_pending']['email'] = $email;
        $_SESSION['oauth_pending']['token_hash'] = hash('sha256', $token);

        return Twig::fromRequest($request)->render($response, 'auth/social_email_sent.html.twig');
    }

    public function complete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pending = $this->pending();
        $token = $request->getQueryParams()['token'] ?? '';
        $token = is_scalar($token) ? (string) $token : '';
        if ($token === '' || empty($pending['token_hash'])
            || !hash_equals((string) $pending['token_hash'], hash('sha256', $token))) {
            throw DomainError::validation(['token' => '확인 링크가 올바르지 않거나 이미 사용되었습니다.']);
        }
        $user = $this->app->socialAuthService()->complete(
            SocialProfile::fromArray((array) $pending['profile']),
            (string) ($pending['email'] ?? '')
        );
        unset($_SESSION['oauth_pending']);
        $this->storeSession($user);

        return $this->homeRedirect($request, $response);
    }

    private function pending(): array
    {
        $pending = $_SESSION['oauth_pending'] ?? null;
        if (!is_array($pending) || (int) ($pending['expires_at'] ?? 0) < time()) {
            unset($_SESSION['oauth_pending']);
            throw DomainError::validation(['oauth' => '소셜 로그인 요청이 만료되었습니다. 다시 시도해 주세요.']);
        }
        return $pending;
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
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('boards.index');
        return $response->withHeader('Location', $url)->withStatus(303);
    }
}
