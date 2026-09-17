<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;

/**
 * 알리고 기능의 유일한 입구. 컨트롤러와 확장 모듈은 Dispatch·AlimtalkApi·SmsApi 를
 * 직접 만들지 않고 이 클래스만 통해 알리고 기능을 쓴다.
 *
 * alimtalkApi·smsApi·dispatch 를 private 으로 감춘 이유: 이 셋을 그대로 공개하면
 * 누구든 Settings::isEnabled() 검사를 거치지 않고 $service->alimtalkApi->send() 나
 * $service->smsApi->sendMass() 를 직접 불러 관리자가 채널을 꺼 둔 상태에서도 실제
 * 메시지를 내보낼 수 있다 — send() 가 지키는 문을 그대로 우회하는 길이 된다.
 * 발송은 반드시 send() 하나만 거치게 한다.
 */
final class AligoService
{
    public Settings $settings;
    public Templates $templates;
    public History $history;

    private Dispatch $dispatch;
    private AlimtalkApi $alimtalkApi;
    private SmsApi $smsApi;

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

    /** 발신프로필 목록. 읽기 전용 조회라서 채널 허용 여부와 무관하게 열어 둔다. */
    public function profiles(): array
    {
        return $this->alimtalkApi->profiles();
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
     *
     * 실패한 쪽은 개수를 0 으로 "성공한 것처럼" 채우지 않는다 — 0 은 "정상 조회했더니
     * 잔여 0 건"과 구별되지 않아 거짓 정보가 된다. 대신 ok=false 와 실패 사유를 함께
     * 돌려주어, 화면이 "알 수 없음: <사유>"를 보여줄 수 있게 한다.
     *
     * @return array{
     *   alimtalk: array{ok: bool, count: int, reason: ?string},
     *   sms: array{ok: bool, sms_count: int, lms_count: int, reason: ?string}
     * }
     */
    public function verify(): array
    {
        if ($this->settings->runtime() === null) {
            throw DomainError::validation(['api_key' => '알리고 계정을 먼저 저장해 주세요.']);
        }

        return [
            'alimtalk' => $this->verifyAlimtalk(),
            'sms' => $this->verifySms(),
        ];
    }

    private function verifyAlimtalk(): array
    {
        try {
            $info = $this->alimtalkApi->heartInfo();

            return ['ok' => true, 'count' => (int) ($info['ALT_CNT'] ?? 0), 'reason' => null];
        } catch (DomainError | TransportFailure $e) {
            // 알림톡이 아직 신청되지 않았거나 일시적으로 실패했을 수 있다. 문자 쪽
            // 조회는 이 실패와 무관하게 그대로 진행한다.
            return ['ok' => false, 'count' => 0, 'reason' => $e->getMessage()];
        }
    }

    private function verifySms(): array
    {
        try {
            $info = $this->smsApi->remain();

            return [
                'ok' => true,
                'sms_count' => (int) ($info['SMS_CNT'] ?? 0),
                'lms_count' => (int) ($info['LMS_CNT'] ?? 0),
                'reason' => null,
            ];
        } catch (DomainError | TransportFailure $e) {
            // 문자 쪽이 실패해도 알림톡 조회 결과에는 영향을 주지 않는다.
            return ['ok' => false, 'sms_count' => 0, 'lms_count' => 0, 'reason' => $e->getMessage()];
        }
    }
}
