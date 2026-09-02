<?php

declare(strict_types=1);

namespace GnuCms\Oauth;

use GnuCms\Error\DomainError;
use League\OAuth2\Client\Provider\GenericProvider;
use Throwable;

abstract class AbstractProvider implements ProviderInterface
{
    protected GenericProvider $client;

    /** @var string[] */
    private array $scopes;

    public function __construct(array $config, array $endpoints, array $scopes, string $scopeSeparator = ' ')
    {
        $this->scopes = $scopes;
        $this->client = new GenericProvider([
            'clientId' => (string) ($config['client_id'] ?? ''),
            'clientSecret' => (string) ($config['client_secret'] ?? ''),
            'redirectUri' => (string) ($config['redirect_uri'] ?? ''),
            'urlAuthorize' => $endpoints['authorize'],
            'urlAccessToken' => $endpoints['token'],
            'urlResourceOwnerDetails' => $endpoints['profile'],
            'scopes' => $scopes,
            'scopeSeparator' => $scopeSeparator,
        ]);
    }

    public function authorizationUrl(string $state): string
    {
        $options = ['state' => $state];
        if ($this->scopes !== []) {
            $options['scope'] = $this->scopes;
        }
        $url = $this->client->getAuthorizationUrl($options);

        // GenericProvider가 호환용 approval_prompt=auto를 자동으로 붙이지만 네이버·카카오
        // 인가 명세에는 없는 값이다. 범위가 없는 네이버에는 빈 scope도 보내지 않는다.
        return $this->withoutAuthorizationParameters(
            $url,
            $this->scopes === [] ? ['approval_prompt', 'scope'] : ['approval_prompt']
        );
    }

    public function fetchProfile(string $code, string $state = ''): SocialProfile
    {
        try {
            $token = $this->client->getAccessToken('authorization_code', $this->accessTokenOptions($code, $state));
            $request = $this->client->getAuthenticatedRequest('GET', $this->profileUrl(), $token);
            $data = $this->client->getParsedResponse($request);
            if (!is_array($data)) {
                throw new \UnexpectedValueException('Invalid profile response');
            }

            return $this->mapProfile($data, $token->getToken());
        } catch (Throwable $e) {
            throw DomainError::internal('소셜 로그인 정보를 확인하지 못했습니다.');
        }
    }

    abstract protected function profileUrl(): string;

    abstract protected function mapProfile(array $data, string $accessToken): SocialProfile;

    protected function accessTokenOptions(string $code, string $state): array
    {
        return ['code' => $code];
    }

    protected function fetchJson(string $url, string $accessToken): array
    {
        $request = $this->client->getAuthenticatedRequest('GET', $url, $accessToken, [
            'headers' => ['Accept' => 'application/json', 'User-Agent' => GNUCMS],
        ]);
        $data = $this->client->getParsedResponse($request);

        return is_array($data) ? $data : [];
    }

    /** @param string[] $names */
    private function withoutAuthorizationParameters(string $url, array $names): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach ($names as $name) {
            unset($query[$name]);
        }
        $authority = (isset($parts['user']) ? $parts['user']
            . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '')
            . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return ($parts['scheme'] ?? 'https') . '://' . $authority . ($parts['path'] ?? '')
            . ($query === [] ? '' : '?' . http_build_query($query))
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
}
