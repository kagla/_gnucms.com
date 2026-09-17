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

    private Connection $db;
    private Dispatch $dispatch;
    private AlimtalkApi $alimtalkApi;
    private SmsApi $smsApi;

    public function __construct(Connection $db, Transport $transport, SecretCipher $cipher)
    {
        $this->db = $db;
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

    /** 예약된 작업 취소. Dispatch::cancel() 로 그대로 넘긴다 — 같은 문을 쓴다. */
    public function cancel(int $jobId): array
    {
        return $this->dispatch->cancel($jobId);
    }

    /**
     * 채널 발송 허용 스위치를 바꾼다. 관리자 화면에서 채널을 끄는 유일한 통로
     * (AdminAligoController::toggle())가 이 메서드를 거치므로, 여기서 취소를 조율하지
     * 않으면 스위치를 꺼도 이미 걸린 예약은 그대로 나간다.
     *
     * Settings 는 Dispatch 를 모른다(거꾸로 Dispatch 가 Settings 를 안다) — 순환을
     * 만들지 않기 위해 Settings::setEnabled() 는 스위치만 바꾸고, 이미 모든 조각을
     * 쥐고 있는 이 클래스가 그 뒤에 취소를 조율한다.
     *
     * 스위치는 취소 결과와 무관하게 반드시 반영된다 — 먼저 스위치를 바꾸고 나서 취소를
     * 시도하므로, 취소가 일부·전부 실패해도 스위치가 되돌아가지 않는다. "스위치를
     * 껐는데 예약이 살아 있다"는 상태가 가장 위험하지만, 그 사실을 반환값의
     * failed·reasons 로 정직하게 드러내는 편이 스위치를 되돌려 "끄기 자체가 실패했다"고
     * 감추는 것보다 낫다 — 관리자는 후자를 보면 다시 끄기를 시도하지 않는다.
     *
     * 켜는 경우는 취소할 것이 없으므로 스위치만 바꾼다.
     *
     * @return array{cancelled:int,failed:int,reasons:list<string>}
     */
    public function setChannelEnabled(string $channel, bool $on): array
    {
        $this->settings->setEnabled($channel, $on);
        if ($on) {
            return ['cancelled' => 0, 'failed' => 0, 'reasons' => []];
        }

        return $this->cancelJobs($this->scheduledJobIdsForChannel($channel));
    }

    /**
     * 알리고에서 템플릿을 다시 가져온다. 승인·정상을 잃거나 목록에서 사라져 자동으로
     * 꺼진 사본이 있으면, 그 템플릿으로 걸린 예약도 함께 취소 요청한다 — 더는 승인
     * 상태를 확인할 수 없는 템플릿으로 나갈 예약을 그대로 둘 수는 없다.
     *
     * Templates 는 Dispatch 를 모른다 — Templates::fetch() 는 무엇이 꺼졌는지(tpl_code
     * 목록)만 돌려주고, 취소는 모든 조각을 쥔 이 클래스가 그 뒤에서 조율한다.
     *
     * @return array{imported:int,updated:int,disabled:int,disabled_tpl_codes:list<string>,
     *     cancelled:int,failed:int,reasons:list<string>}
     */
    public function importTemplates(): array
    {
        $counts = $this->templates->fetch();
        $cancellation = $this->cancelJobs($this->scheduledJobIdsForTemplates($counts['disabled_tpl_codes']));

        return $counts + $cancellation;
    }

    /** @return list<int> 그 채널(sms 는 lms 포함)에 예약된 채로 남아 있는 작업 id */
    private function scheduledJobIdsForChannel(string $channel): array
    {
        $channels = $channel === 'at' ? ['at'] : ['sms', 'lms'];

        return $this->scheduledJobIdsWhere('channel IN (' . implode(',', array_fill(0, count($channels), '?')) . ')', $channels);
    }

    /** @param list<string> $tplCodes @return list<int> 그 템플릿 코드들로 예약된 채로 남아 있는 작업 id */
    private function scheduledJobIdsForTemplates(array $tplCodes): array
    {
        if ($tplCodes === []) {
            return [];
        }

        return $this->scheduledJobIdsWhere('tpl_code IN (' . implode(',', array_fill(0, count($tplCodes), '?')) . ')', $tplCodes);
    }

    /** @param list<string> $params @return list<int> */
    private function scheduledJobIdsWhere(string $condition, array $params): array
    {
        $rows = $this->db->select('SELECT id FROM ' . $this->db->table('message_jobs')
            . ' WHERE status = ? AND ' . $condition, array_merge(['scheduled'], $params));

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * 주어진 작업들을 하나씩 Dispatch::cancel() 로 취소한다. 작업 하나가 실패해도
     * 나머지 작업의 취소 시도를 막지 않는다 — Dispatch::cancel() 이 mid 하나의 실패를
     * 삼키지 않는 것과 같은 이유다.
     *
     * Dispatch::cancel() 은 그 작업이 더는 'scheduled'가 아니면(존재하지 않거나 이미
     * 끝났거나) 가드절 DomainError 를 던진다. scheduledJobIdsForChannel()·
     * scheduledJobIdsForTemplates() 가 목록을 만든 시점과 여기서 실제로 취소를 시도하는
     * 시점 사이에는 틈이 있다 — 다른 관리자가 열어 둔 이력 화면의 History::refresh() 가
     * 그 사이 이 작업을 먼저 끝냈거나, 끄기 버튼이 두 번 눌려 겹친 두 요청의 목록이
     * 같은 작업을 함께 보고 있었을 수 있다. 이 예외를 여기서 잡지 않으면 작업 하나
     * 때문에 뒤에 남은 작업은 통째로 시도되지도 못한 채 호출부에는 평범한 422 하나만
     * 올라가고, 무엇이 취소를 시도조차 못 했는지는 아무 데도 남지 않는다 — 그래서
     * mid 하나의 실패와 똑같이 실패로 세고 이유를 남긴 뒤 다음 작업으로 넘어간다.
     *
     * @param list<int> $jobIds
     * @return array{cancelled:int,failed:int,reasons:list<string>}
     */
    private function cancelJobs(array $jobIds): array
    {
        $cancelled = 0;
        $failed = 0;
        $reasons = [];
        foreach ($jobIds as $jobId) {
            try {
                $result = $this->dispatch->cancel($jobId);
            } catch (DomainError $e) {
                $failed++;
                $reasons[] = '작업 #' . $jobId . ': ' . (string) ($e->details()['job'] ?? $e->getMessage());
                continue;
            }
            $cancelled += $result['cancelled'];
            $failed += $result['failed'];
            array_push($reasons, ...$result['reasons']);
        }

        return ['cancelled' => $cancelled, 'failed' => $failed, 'reasons' => $reasons];
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
