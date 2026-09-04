<?php

declare(strict_types=1);

namespace GnuCms\Spam;

use GnuCms\Error\DomainError;
use Throwable;

/** Cloudflare Turnstile 토큰을 서버에서 검증한다. 비활성 상태에서는 아무 일도 하지 않는다. */
final class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private bool $enabled;
    private string $siteKey;
    private string $secretKey;
    private string $hostname;

    /** @var callable|null 테스트에서만 외부 요청을 대신한다. */
    private $transport;

    public function __construct(array $config, ?callable $transport = null)
    {
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->siteKey = trim((string) ($config['site_key'] ?? ''));
        $this->secretKey = trim((string) ($config['secret_key'] ?? ''));
        $this->hostname = strtolower(trim((string) ($config['hostname'] ?? '')));
        $this->transport = $transport;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isConfigured(): bool
    {
        return $this->siteKey !== '' && $this->secretKey !== '';
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    public function verify(string $token, ?string $clientIp, string $action): void
    {
        if (!$this->enabled) {
            return;
        }
        if (!$this->isConfigured()) {
            throw DomainError::serviceUnavailable('자동 등록 방지 서비스 설정을 확인해 주세요.');
        }

        $token = trim($token);
        if ($token === '' || strlen($token) > 2048) {
            throw DomainError::validation(['turnstile' => '자동 등록 방지 확인을 완료해 주세요.']);
        }

        $fields = ['secret' => $this->secretKey, 'response' => $token];
        if ($clientIp !== null && $clientIp !== '') {
            $fields['remoteip'] = $clientIp;
        }

        try {
            $result = $this->transport === null
                ? $this->request($fields)
                : ($this->transport)(self::VERIFY_URL, $fields);
        } catch (DomainError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw DomainError::serviceUnavailable('자동 등록 방지 서비스를 일시적으로 사용할 수 없습니다.');
        }

        if (!is_array($result)) {
            throw DomainError::serviceUnavailable('자동 등록 방지 서비스의 응답을 확인할 수 없습니다.');
        }

        $valid = ($result['success'] ?? false) === true;
        if ($this->hostname !== '') {
            $valid = $valid && strtolower((string) ($result['hostname'] ?? '')) === $this->hostname;
        }
        if ($action !== '') {
            $valid = $valid && (string) ($result['action'] ?? '') === $action;
        }
        if (!$valid) {
            throw DomainError::validation(['turnstile' => '자동 등록 방지 확인에 실패했습니다. 다시 시도해 주세요.']);
        }
    }

    private function request(array $fields): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents(self::VERIFY_URL, false, $context);
        if (!is_string($body)) {
            throw DomainError::serviceUnavailable('자동 등록 방지 서비스에 연결할 수 없습니다.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw DomainError::serviceUnavailable('자동 등록 방지 서비스의 응답을 확인할 수 없습니다.');
        }

        return $decoded;
    }
}
