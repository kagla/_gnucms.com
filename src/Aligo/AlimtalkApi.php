<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Error\DomainError;

/** kakaoapi.aligo.in. 인증은 apikey·userid 이고 응답 성공은 code 0 이다. */
final class AlimtalkApi
{
    private const BASE = 'https://kakaoapi.aligo.in';

    private Transport $transport;
    private Settings $settings;

    public function __construct(Transport $transport, Settings $settings)
    {
        $this->transport = $transport;
        $this->settings = $settings;
    }

    public function heartInfo(): array
    {
        return $this->call('/akv10/heartinfo/', []);
    }

    public function profiles(): array
    {
        $list = $this->call('/akv10/profile/list/', [])['list'] ?? [];
        return is_array($list) ? $list : [];
    }

    public function templates(string $senderkey): array
    {
        $list = $this->call('/akv10/template/list/', ['senderkey' => $senderkey])['list'] ?? [];
        return is_array($list) ? $list : [];
    }

    public function send(array $fields): array
    {
        $info = $this->call('/akv10/alimtalk/send/', $fields)['info'] ?? [];

        return [
            'mid' => (string) ($info['mid'] ?? ''),
            'scnt' => (int) ($info['scnt'] ?? 0),
            'fcnt' => (int) ($info['fcnt'] ?? 0),
        ];
    }

    public function detail(string $mid): array
    {
        $list = $this->call('/akv10/history/detail/', ['mid' => $mid, 'limit' => '500'])['list'] ?? [];
        return is_array($list) ? $list : [];
    }

    private function call(string $path, array $fields): array
    {
        $account = $this->settings->runtime();
        if ($account === null) {
            throw DomainError::validation(['api_key' => '알리고 계정을 먼저 저장해 주세요.']);
        }

        $response = $this->transport->post(self::BASE . $path, [
            'apikey' => $account['alimtalk_api_key'],
            'userid' => $account['user_id'],
        ] + $fields);

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw DomainError::serviceUnavailable(
                '알리고 알림톡 응답을 읽지 못했습니다 (HTTP ' . $response['status'] . ').');
        }
        $code = (string) ($decoded['code'] ?? '');
        if ($code !== '0') {
            throw DomainError::serviceUnavailable(
                ResultCodes::alimtalkReason($code, (string) ($decoded['message'] ?? '')));
        }

        return $decoded;
    }
}
