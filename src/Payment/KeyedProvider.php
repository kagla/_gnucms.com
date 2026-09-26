<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

/** 클라이언트 키와 서버 비밀키를 사용하는 결제사 공통 설정. */
abstract class KeyedProvider implements Provider
{
    public function fields(): array
    {
        return [
            'mode' => ['label' => '결제 방식', 'secret' => false, 'multiline' => false],
            'client_key' => ['label' => '클라이언트 키', 'secret' => false, 'multiline' => false],
            'secret_key' => ['label' => '시크릿 키', 'secret' => true, 'multiline' => false],
        ];
    }

    public function validate(array $input, array $before, string $environment): array
    {
        Settings::environment($environment);
        $mode = PaymentMode::validate($input['mode'] ?? $before['mode'] ?? 'general');
        $clientInput = $input['client_key'] ?? $before['client_key'] ?? '';
        if (!is_string($clientInput)) throw DomainError::validation(['client_key' => '발급받은 클라이언트 키를 확인해 주세요.']);
        $client = trim($clientInput);
        if ($client === '' || strlen($client) > 200 || !preg_match('/^[A-Za-z0-9_-]+$/D', $client)) {
            throw DomainError::validation(['client_key' => '발급받은 클라이언트 키를 확인해 주세요.']);
        }
        $secretInput = $input['secret_key'] ?? '';
        if (!is_string($secretInput)) throw DomainError::validation(['secret_key' => '시크릿 키를 확인해 주세요.']);
        $secret = $secretInput;
        if ($secret === '' && $client === ($before['client_key'] ?? '')) {
            $secret = (string) ($before['secret_key'] ?? '');
        }
        if ($secret === '' || strlen($secret) > 512 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
            throw DomainError::validation(['secret_key' => '해당 상점과 결제 방식의 시크릿 키를 확인해 주세요.']);
        }
        return ['mode' => $mode, 'client_key' => $client, 'secret_key' => $secret];
    }

    public function credentials(array $revision, ?array $current): array
    {
        if ($current !== null && ($current['client_key'] ?? '') === ($revision['client_key'] ?? '')
            && ($current['secret_key'] ?? '') !== '') {
            $revision['secret_key'] = $current['secret_key'];
        }
        return $revision;
    }

    public function methods(): array { return ['card', 'bank_transfer', 'mobile']; }
    public function supportsPartialRefund(): bool { return true; }
}
