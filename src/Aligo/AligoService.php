<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;

/**
 * 알리고 기능의 유일한 입구. 컨트롤러와 확장 모듈은 Dispatch·SmsApi 등을 직접 만들지
 * 않고 이 클래스만 통해 알리고 기능을 쓴다.
 */
final class AligoService
{
    public Settings $settings;
    public Templates $templates;
    public Dispatch $dispatch;
    public History $history;
    public AlimtalkApi $alimtalkApi;
    public SmsApi $smsApi;

    public function __construct(Connection $db, Transport $transport, SecretCipher $cipher)
    {
        $this->settings = new Settings(new SettingsRepository($db), $cipher);
        $this->alimtalkApi = new AlimtalkApi($transport, $this->settings);
        $this->smsApi = new SmsApi($transport, $this->settings);
        $this->templates = new Templates($db, $this->alimtalkApi, $this->settings);
        $this->dispatch = new Dispatch($db, $this->alimtalkApi, $this->smsApi, $this->templates, $this->settings);
        $this->history = new History($db, $this->alimtalkApi, $this->smsApi);
    }

    /**
     * 확장이 쓰는 발송 입구. Dispatch::send() 로 그대로 넘기므로 채널별 발송 허용
     * 여부 같은 검사를 여기서 다시 약하게 만들지 않는다 — 관리자 화면과 같은 문을 쓴다.
     */
    public function send(array $request): int
    {
        return $this->dispatch->send($request);
    }

    public function status(): array
    {
        $runtime = $this->settings->runtime();

        return [
            'configured' => $runtime !== null,
            'sms_enabled' => $this->settings->isEnabled('sms'),
            'alimtalk_enabled' => $this->settings->isEnabled('at'),
            'test_mode' => $runtime !== null && $runtime['test_mode'],
            'pending' => $this->history->pendingCount(),
        ];
    }

    /**
     * 연결 확인. 계정이 저장되어 있지 않으면 값을 조회할 대상 자체가 없으므로 바로
     * 오류를 낸다. 계정은 있지만 알림톡·문자 중 한쪽만 알리고에 신청되어 있는
     * 경우가 있을 수 있으므로(예: 문자만 쓰는 계정), 두 조회는 서로 독립적으로
     * 실패를 허용한다 — 한쪽이 실패했다고 나머지 한쪽까지 "알아내지 못함"으로
     * 돌려주면, 실제로는 알아낸 정보까지 감추게 되어 더 나쁘다.
     */
    public function verify(): array
    {
        if ($this->settings->runtime() === null) {
            throw DomainError::validation(['api_key' => '알리고 계정을 먼저 저장해 주세요.']);
        }

        $result = ['ALT_CNT' => 0, 'SMS_CNT' => 0, 'LMS_CNT' => 0];

        try {
            $alimtalk = $this->alimtalkApi->heartInfo();
            $result['ALT_CNT'] = (int) ($alimtalk['ALT_CNT'] ?? 0);
        } catch (DomainError | TransportFailure $e) {
            // 알림톡이 아직 신청되지 않았거나 일시적으로 실패했을 수 있다. 문자 쪽
            // 결과는 그대로 보고하기 위해 여기서 멈추지 않는다.
        }

        try {
            $sms = $this->smsApi->remain();
            $result['SMS_CNT'] = (int) ($sms['SMS_CNT'] ?? 0);
            $result['LMS_CNT'] = (int) ($sms['LMS_CNT'] ?? 0);
        } catch (DomainError | TransportFailure $e) {
            // 문자 쪽이 실패해도 위에서 알아낸 알림톡 결과는 그대로 돌려준다.
        }

        return $result;
    }
}
