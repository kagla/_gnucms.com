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

    /**
     * 예약된 작업 취소. Dispatch::cancel() 로 그대로 넘긴다 — 같은 문을 쓴다.
     *
     * 취소한 뒤에는 작업 집계를 다시 세게 한다. 취소는 결과 조회를 거치지 않고 수신자
     * 행을 바꾸는 유일한 경로라, 여기서 세지 않으면 화면의 총·성공·실패 숫자에 취소가
     * 영영 나타나지 않는다 — 전원 취소된 502명짜리 작업이 접수 건수를 그대로 쥔 채
     * "성공 502 · 취소됨"으로 남는다. Dispatch 가 History 를 직접 알게 하는 대신
     * (Settings·Templates 와 같은 이유로) 모든 조각을 쥔 이 클래스가 조율한다.
     */
    public function cancel(int $jobId): array
    {
        return $this->cancelOne($jobId, null);
    }

    /**
     * 취소 한 건. 공개 cancel() 과 내부 일괄 취소가 같은 문을 쓰되, 내부 경로만
     * 묶음 목록을 좁힐 수 있다 — 좁히는 취소는 확장에 열어 둘 만큼 안전한 도구가
     * 아니다(어느 묶음에 누가 들어 있는지는 이 클래스만 안다).
     *
     * @param list<string>|null $onlyMids
     * @return array{cancelled:int,failed:int,reasons:list<string>}
     */
    private function cancelOne(int $jobId, ?array $onlyMids): array
    {
        $result = $this->dispatch->cancel($jobId, $onlyMids);
        $this->history->recompute($jobId);

        return $result;
    }

    /**
     * 이 회원에게 아직 나가지 않은 예약을 멈춘다. 탈퇴와 차단이 부른다.
     *
     * **왜 필요한가.** 탈퇴는 이 시스템이 개인정보를 놓아주겠다고 약속하는 자리라
     * users.phone 을 지우는데, 예약은 최대 30일 뒤까지 살아 있고 수신자 행은 번호를
     * 따로 들고 있다 — 아무것도 하지 않으면 탈퇴한 사람의 전화기가 며칠 뒤에 울린다.
     * 차단도 같다(차단은 번호를 지우지도 않는다).
     *
     * **어디까지 멈출 수 있는가.** 알리고의 취소 단위는 수신자가 아니라 접수
     * 묶음(mid)이다. 500명 묶음에서 한 사람만 빼는 길은 없고, 남은 사람을 다시 접수하는
     * 길은 이 저장소가 스스로 금지한 재발송이다(응답을 못 받은 채 다시 보내면 진짜
     * 전화기로 두 통이 간다). 그래서 **그 사람 혼자 든 묶음만** 취소하고, 다른 수신자가
     * 함께 든 묶음은 건드리지 않는다 — 회원 한 사람의 탈퇴가 관리자가 걸어 둔 다른
     * 499명의 발송을 함께 지우는 편이 더 나쁘고, 탈퇴는 방문자가 스스로 누르는
     * 버튼이라 그 길을 열어 두면 남의 발송을 지우는 도구가 된다. 멈추지 못한 건은
     * kept 로 세어 돌려준다 — 침묵하지 않기 위해서다.
     *
     * 이미 나간 발송(status 가 'sending' 이상인 작업)은 취소 대상이 아니다. 알리고가
     * 이미 내보냈으므로 멈출 것이 없다 — 이력에 남은 번호는 지우지 않는다(보존은
     * 의도된 설계이고, 문제는 보존이 아니라 새 발송이다).
     *
     * @return array{cancelled:int,failed:int,reasons:list<string>,kept:int}
     */
    public function cancelScheduledForUser(int $userId): array
    {
        $midsByJob = $this->pendingBookingsForUser($userId);
        $cancelled = 0;
        $failed = 0;
        $kept = 0;
        $reasons = [];
        foreach ($midsByJob as $jobId => $mids) {
            $mine = [];
            foreach ($mids as $mid) {
                if ($this->bookingHasOtherRecipients($jobId, $mid, $userId)) {
                    $kept++;
                    $reasons[] = '작업 #' . $jobId . ': 같은 접수 묶음에 다른 수신자가 있어'
                        . ' 이 예약만 따로 멈출 수 없습니다.';
                    continue;
                }
                $mine[] = $mid;
            }
            if ($mine === []) {
                continue;
            }
            try {
                // 채널·템플릿 일괄 취소와 같은 이유로 작업 하나의 실패가 나머지를 막지
                // 않는다(cancelJobs() 주석). 여기서는 목록을 만든 시점과 취소를 시도하는
                // 시점 사이에 그 작업이 나가 버렸을 수 있다.
                $result = $this->cancelOne($jobId, $mine);
            } catch (DomainError $e) {
                $failed++;
                $reasons[] = '작업 #' . $jobId . ': ' . (string) ($e->details()['job'] ?? $e->getMessage());
                continue;
            }
            $cancelled += $result['cancelled'];
            $failed += $result['failed'];
            array_push($reasons, ...$result['reasons']);
        }

        return ['cancelled' => $cancelled, 'failed' => $failed, 'reasons' => $reasons, 'kept' => $kept];
    }

    /**
     * 탈퇴·차단이 부르는 입구. cancelScheduledForUser() 와 같은 일을 하되 **아무것도
     * 던지지 않는다** — 부르는 쪽이 한 일(탈퇴·차단)은 이미 끝났고 되돌릴 수 없으므로,
     * 취소가 실패했다고 예외를 올리면 그 화면은 "나갈 수 없는 사이트"가 된다.
     *
     * 그렇다고 조용히 삼키지도 않는다: 멈추지 못한 건이 있으면 운영자 로그에 한 줄
     * 남긴다(AccountService::requestPasswordReset() 이 아무 데도 못 보냈을 때와 같은
     * 자리, 같은 방식). 알리고 계층의 예외 문구에는 API 키가 실리지 않는다.
     *
     * @param string $what 로그에 적을 말("탈퇴한"·"차단된")
     */
    public function stopScheduledForUser(int $userId, string $what): void
    {
        try {
            $result = $this->cancelScheduledForUser($userId);
        } catch (\Throwable $e) {
            error_log('[' . GNUCMS_ID . '] ' . $what . ' 회원 #' . $userId
                . ' 의 예약 발송을 멈추지 못했습니다: ' . $e->getMessage());

            return;
        }
        if ($result['failed'] > 0 || $result['kept'] > 0) {
            error_log('[' . GNUCMS_ID . '] ' . $what . ' 회원 #' . $userId . ' 의 예약 발송 가운데 '
                . ($result['failed'] + $result['kept']) . '건을 멈추지 못했습니다: '
                . implode(' / ', $result['reasons']));
        }
    }

    /**
     * 이 회원 앞으로 아직 나가지 않은 접수 묶음. 예약(scheduled) 작업의, 접수된 채
     * (accepted) 결과를 기다리는 행만 본다.
     *
     * @return array<int,list<string>> 작업 id => 묶음(mid) 목록
     */
    private function pendingBookingsForUser(int $userId): array
    {
        $rows = $this->db->select('SELECT DISTINCT job_id, mid FROM '
            . $this->db->table('message_recipients')
            . ' WHERE user_id = ? AND status = ? AND mid IS NOT NULL',
            [(string) $userId, 'accepted']);
        if ($rows === []) {
            return [];
        }
        $jobIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['job_id'], $rows)));
        $scheduled = $this->db->select('SELECT id FROM ' . $this->db->table('message_jobs')
            . ' WHERE status = ? AND id IN (' . implode(',', array_fill(0, count($jobIds), '?')) . ')',
            array_merge(['scheduled'], $jobIds));
        $scheduledIds = array_map(static fn (array $row): int => (int) $row['id'], $scheduled);

        $byJob = [];
        foreach ($rows as $row) {
            $jobId = (int) $row['job_id'];
            if (in_array($jobId, $scheduledIds, true)) {
                $byJob[$jobId][] = (string) $row['mid'];
            }
        }

        return $byJob;
    }

    /** 이 묶음에 아직 결과를 기다리는 **다른** 수신자가 있는가(번호만 적어 보낸 행 포함). */
    private function bookingHasOtherRecipients(int $jobId, string $mid, int $userId): bool
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM '
            . $this->db->table('message_recipients')
            . ' WHERE job_id = ? AND mid = ? AND status = ? AND (user_id IS NULL OR user_id <> ?)',
            [$jobId, $mid, 'accepted', (string) $userId]);

        return (int) ($row['c'] ?? 0) > 0;
    }

    /**
     * 계정 설정을 저장한다. 관리자 화면의 저장 버튼(AdminAligoController::save())이
     * 지나는 길이다.
     *
     * **저장이 채널을 끄는 두 번째 길이다.** Settings::save() 는 사용자ID 나 API 키가
     * 바뀌면 두 스위치를 모두 끈다(옛 계정에서 확인한 상태를 새 계정에 물려주지 않는다).
     * 그 길에는 끄기 버튼이 지나는 setChannelEnabled() 의 예약 취소가 없어서, 계정을
     * 바꾼 관리자는 초록 체크와 함께 「설정을 저장했습니다」를 보고 예약은 그대로
     * 살아 있었다 — 게다가 그 예약은 새 키로는 취소할 수도 없다.
     *
     * 그래서 같은 취소 조율(cancelJobs)을 이 길에도 태운다. 부르는 자리는 저장
     * **직전**이다(Settings::save() 의 갈고리 주석): 취소 요청은 그 예약을 접수한
     * 옛 계정의 자격증명으로 나가야 알리고가 받아 준다. 그 대가로, 취소까지 끝난 뒤
     * 설정 쓰기가 터지면 예약은 취소됐는데 설정은 그대로인 상태가 남는다 — 반대
     * 순서(설정은 바뀌었는데 예약은 영영 못 멈춤)보다 나은 쪽을 골랐다.
     *
     * @return array{cancelled:int,failed:int,reasons:list<string>} 함께 취소한(또는 취소하지
     *   못한) 예약. 화면은 이 숫자를 저장 안내에 싣는다 — 끄기 버튼과 같은 문장이다.
     */
    public function saveSettings(array $input): array
    {
        $result = ['cancelled' => 0, 'failed' => 0, 'reasons' => []];
        $this->settings->save($input, function (array $channels) use (&$result): void {
            $jobIds = [];
            foreach ($channels as $channel) {
                // 채널 하나에 걸린 예약은 다른 채널 목록에 다시 나오지 않는다(작업 하나는
                // 채널 하나다). 한 번에 모아 두고 한 번만 취소를 돌린다.
                array_push($jobIds, ...$this->scheduledJobIdsForChannel($channel));
            }
            $result = $this->cancelJobs($jobIds);
        });

        return $result;
    }

    /**
     * 채널 발송 허용 스위치를 바꾼다. 관리자 화면의 끄기 버튼
     * (AdminAligoController::toggle())이 이 메서드를 거치므로, 여기서 취소를 조율하지
     * 않으면 스위치를 꺼도 이미 걸린 예약은 그대로 나간다. 채널이 꺼지는 다른 길
     * (계정 변경)은 saveSettings() 가 같은 조율을 태운다.
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
     * @return array{cancelled:int,failed:int,reasons:list<string>,cancel_unverified?:bool}
     */
    public function setChannelEnabled(string $channel, bool $on): array
    {
        $this->settings->setEnabled($channel, $on);
        if ($on) {
            return ['cancelled' => 0, 'failed' => 0, 'reasons' => []];
        }

        try {
            return $this->cancelJobs($this->scheduledJobIdsForChannel($channel));
        } catch (\Throwable) {
            // 발송 허용은 이미 꺼졌다. 예약 목록 조회·취소가 예기치 않게 실패해도
            // 끄기를 실패로 돌려주지 않고, 취소 결과를 확인하지 못했다고 알린다.
            return ['cancelled' => 0, 'failed' => 0, 'reasons' => [], 'cancel_unverified' => true];
        }
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
                // 화면에서 누르는 취소와 같은 문을 쓴다 — 집계를 다시 세는 것까지 같다.
                $result = $this->cancel($jobId);
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

    /** 스위치 저장 직후에도 안전하게 읽을 수 있는 계정·채널 상태. */
    public function channelStatus(): array
    {
        $values = $this->settings->formValues();
        try {
            $runtime = $this->settings->runtime();
        } catch (DomainError) {
            // 저장된 키가 손상돼도 끄기 이후의 상태 확인은 열려 있어야 한다.
            $runtime = null;
        }
        $configured = $runtime !== null;

        return [
            'configured' => $configured,
            'sms_switch_on' => $values['sms_enabled'],
            'alimtalk_switch_on' => $values['alimtalk_enabled'],
            'sms_enabled' => $configured && $values['sms_enabled'],
            'alimtalk_enabled' => $configured && $values['alimtalk_enabled'],
            'test_mode' => $configured && $values['test_mode'],
        ];
    }

    public function status(): array
    {
        return $this->channelStatus() + ['pending' => $this->history->pendingCount()];
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
