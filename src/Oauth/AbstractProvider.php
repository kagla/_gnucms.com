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
        return $this->client->getAuthorizationUrl(['state' => $state, 'scope' => $this->scopes]);
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
}
