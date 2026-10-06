<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Error\DomainError;

/**
 * apis.aligo.in. 알림톡과 달리 인증은 key·user_id 이고 성공은 result_code 1 이며
 * 요청 본문·제목은 UTF-8 그대로 보낸다. SMS/LMS 길이는 MessageText에서 별도로 검사한다.
 */
final class SmsApi
{
    private const BASE = 'https://apis.aligo.in';

    private Transport $transport;
    private Settings $settings;

    public function __construct(Transport $transport, Settings $settings)
    {
        $this->transport = $transport;
        $this->settings = $settings;
    }

    public function remain(): array
    {
        return $this->call('/remain/', []);
    }

    public function sendMass(array $fields): array
    {
        $decoded = $this->call('/send_mass/', $fields);

        return [
            'mid' => (string) ($decoded['msg_id'] ?? ''),
            'scnt' => (int) ($decoded['success_cnt'] ?? 0),
            'fcnt' => (int) ($decoded['error_cnt'] ?? 0),
        ];
    }

    public function detail(string $mid): array
    {
        $list = $this->call('/sms_list/', ['mid' => $mid, 'page_size' => '500'])['list'] ?? [];
        return is_array($list) ? $list : [];
    }

    /** 예약 발송 취소. 알리고는 발송 5분 전까지만 받아 준다. */
    public function cancel(string $mid): void
    {
        $this->call('/cancel/', ['mid' => $mid]);
    }

    private function call(string $path, array $fields): array
    {
        $account = $this->settings->runtime();
        if ($account === null) {
            throw DomainError::validation(['api_key' => '알리고 계정을 먼저 저장해 주세요.']);
        }

        $response = $this->transport->post(self::BASE . $path, [
            'key' => $account['api_key'],
            'user_id' => $account['user_id'],
        ] + $fields);

        $decoded = json_decode(self::toUtf8((string) $response['body']), true);
        if (!is_array($decoded)) {
            throw DomainError::serviceUnavailable(
                '알리고 문자 응답을 읽지 못했습니다 (HTTP ' . $response['status'] . ').');
        }
        $code = (string) ($decoded['result_code'] ?? '');
        if ($code !== '1') {
            throw DomainError::serviceUnavailable(
                ResultCodes::smsReason($code, (string) ($decoded['message'] ?? '')));
        }

        return $decoded;
    }

    /**
     * 정상 요청·응답은 UTF-8이다. 이전 연동 또는 EUC-KR 응답과의 호환을 위해
     * UTF-8이 아닌 응답만 정규화한다. 그대로 json_decode() 하면 null 이 돌아와
     * "응답을 읽지 못했습니다"가 된다. 그 예외를 Dispatch 가 잡으면 이미 알리고가
     * 받아들여 전화기가 울린 묶음이 통째로 'failed' 로 기록되고, 그걸 본 관리자가
     * 다시 보내 중복 발송·이중 과금이 된다. 이미 UTF-8 이면 아무것도 하지 않는다.
     */
    private static function toUtf8(string $body): string
    {
        if ($body === '' || mb_check_encoding($body, 'UTF-8')) {
            return $body;
        }

        return (string) mb_convert_encoding($body, 'UTF-8', 'EUC-KR');
    }

}
