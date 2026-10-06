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

    /**
     * 잔여 건수(SMS_CNT·LMS_CNT·MMS_CNT·ALT_CNT …). 알리고 문서의 응답 표는 건수를 최상위
     * 필드처럼 적지만, 예시와 실제 응답은 모두 list 안에 넣는다(2026-09-20 라이브 계정으로
     * 확인). 표만 보고 최상위에서 읽은 첫 구현은 연결 확인에서 알림톡을 항상 0건으로 보여줬다.
     */
    public function heartInfo(): array
    {
        $list = $this->call('/akv10/heartinfo/', [])['list'] ?? [];
        return is_array($list) ? $list : [];
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

        $result = [
            'mid' => (string) ($info['mid'] ?? ''),
            'scnt' => (int) ($info['scnt'] ?? 0),
            'fcnt' => (int) ($info['fcnt'] ?? 0),
        ];
        $cost = ProviderCost::fromInfo(is_array($info) ? $info : []);
        if ($cost !== null) $result['cost'] = $cost;

        return $result;
    }

    public function detail(string $mid): array
    {
        $list = $this->call('/akv10/history/detail/', ['mid' => $mid, 'limit' => '500'])['list'] ?? [];
        return is_array($list) ? $list : [];
    }

    /** 예약 발송 취소. 알리고는 발송 5분 전까지만 받아 준다. */
    public function cancel(string $mid): void
    {
        $this->call('/akv10/cancel/', ['mid' => $mid]);
    }

    private function call(string $path, array $fields): array
    {
        $account = $this->settings->runtime();
        if ($account === null) {
            throw DomainError::validation(['api_key' => '알리고 계정을 먼저 저장해 주세요.']);
        }

        $response = $this->transport->post(self::BASE . $path, [
            'apikey' => $account['api_key'],
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
