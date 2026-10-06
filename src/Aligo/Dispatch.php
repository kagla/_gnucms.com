<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

/**
 * 발송 작업 한 건을 만들고 알리고에 보낸다.
 *
 * 요청 모양:
 *   channel    'at' | 'sms'            (문자는 치환이 끝난 본문 길이로 sms·lms 가 정해진다)
 *   body       본문. 알림톡이면 비우고 tpl_code 의 본문을 쓴다
 *   title      LMS 제목 (선택)
 *   tpl_code   알림톡 템플릿 코드 (알림톡 필수)
 *   failover   알림톡 실패 시 문자 대체발송 (선택)
 *   recipients 의 fallback_body·fallback_vars 는 대체문자에 쓸 본문·변수 (선택).
 *              없으면 알림톡 본문을 그대로 대체문자로 쓴다.
 *   recipients 의 fallback_secret_vars 는 대체문자 이력에서 가릴 변수 이름 (선택)
 *   event_key  이 발송을 일으킨 알림 이벤트 (선택)
 *   secret_vars 값이 표에 남으면 안 되는 변수 이름들. 실제로 나가는 본문은 그대로이고,
 *               수신자 행에 적을 사본에서만 그 값이 '***' 로 바뀐다 (선택)
 *   created_by 요청한 관리자 표시명 (선택)
 *   scheduled_at 예약 시각. 비우면 즉시 발송 — SendTime::parse() 가 검증해 UTC로 바꾼다.
 *                오프셋 없는 값은 한국 시각(KST)으로 읽는다(그 클래스 문서 주석 참고) (선택)
 *   recipients [['phone' =>, 'name' =>, 'user_id' =>, 'vars' => []], ...]
 *
 * message_jobs.status 는 JobStatus::VALUES 의 일곱 값 중 하나다 — 그 규칙과 뜻은
 * JobStatus 한 곳에 적혀 있고, 여기(접수 직후)와 History(결과가 들어올 때마다)가
 * 같은 규칙을 쓴다. 접수 직후에는 아직 아무 전달도 확인되지 않았으므로 보통
 * 'sending'(결과를 기다리는 중)이다 — 'sent'(전원 성공)는 결과를 다 확인한 뒤에만 붙는다.
 *
 * success·failure 도 두 단계의 뜻이 다르다. 접수 직후에는 알리고가 돌려준 접수
 * 성공·실패 건수(scnt·fcnt)이고, 결과가 들어오기 시작하면 History 가 수신자 행에서
 * 다시 세어 전달 성공·실패 건수로 바꾼다. 둘 다 "그 시점에 알 수 있는 가장 정확한 값"이다.
 */
final class Dispatch
{
    public const CHUNK = 500;

    private Connection $db;
    private AlimtalkApi $alimtalk;
    private SmsApi $sms;
    private Templates $templates;
    private Settings $settings;

    public function __construct(Connection $db, AlimtalkApi $alimtalk, SmsApi $sms,
        Templates $templates, Settings $settings)
    {
        $this->db = $db;
        $this->alimtalk = $alimtalk;
        $this->sms = $sms;
        $this->templates = $templates;
        $this->settings = $settings;
    }

    public function send(array $request): int
    {
        $channel = (string) ($request['channel'] ?? 'sms');
        if (!in_array($channel, Settings::CHANNELS, true)) {
            throw DomainError::validation(['channel' => '알림톡 또는 문자를 선택해 주세요.']);
        }
        if (!$this->settings->isEnabled($channel)) {
            throw DomainError::validation(['channel' =>
                ($channel === 'at' ? '알림톡' : '문자') . ' 발송이 허용되어 있지 않습니다. 설정에서 허용해 주세요.']);
        }
        $account = $this->settings->runtime();

        // 예약 시각은 수신자를 준비하기 전에, 무엇보다 먼저 검증한다 — 잘못된 시각을
        // 나중에 걸러내면 이미 만들어진 작업 행이나 알리고 호출이 유령으로 남는다.
        $scheduledAt = SendTime::parse($request['scheduled_at'] ?? null);

        [$body, $tplCode] = $this->resolveBody($channel, $request);
        $title = trim((string) ($request['title'] ?? '')) ?: null;
        $failover = $channel === 'at' && !empty($request['failover']);

        // 변수를 먼저 채운 뒤에 분류·길이를 검사한다. 템플릿 상태로는 90바이트 이하라도
        // 이름 같은 변수를 채우면 쉽게 넘어간다. msg_type 을 명시해서 보내기 때문에,
        // 알리고는 짧게 신고해 놓고 긴 본문을 보내도 자동으로 LMS 로 올려주지 않는다.
        $prepared = $this->prepare($body, $request['recipients'] ?? [], self::secretNames($request));
        if ($prepared === []) {
            throw DomainError::validation(['recipients' => '보낼 수 있는 수신번호가 없습니다.']);
        }

        // 작업 하나에는 msg_type 하나만 실을 수 있으므로, 치환된 수신자 본문 중
        // 하나라도 SMS 경계를 넘으면 작업 전체를 LMS 로 보낸다. 애매하면 LMS 다 —
        // 과분류는 단가가 조금 오르지만, 저분류는 본문이 잘리거나 거절된다.
        $stored = $channel === 'at' ? 'at' : $this->classify($prepared);
        if ($stored !== 'at') {
            foreach ($prepared as $one) {
                MessageText::assertFits($one['body'], $title);
            }
        } elseif ($failover) {
            // 알림톡과 대체문자 API 요청은 모두 UTF-8이다. 대체문자(fmessage_N)는
            // 단말기의 EUC-KR 문자 범위·길이 제한을 별도로 확인한다. 여기서
            // 걸러 두지 않으면 이모지 같은 글자가 깨진 채로 대체문자에 실려 나간다. 템플릿
            // 원문이 아니라 변수 치환이 끝난 수신자별 본문을 검사한다 — 실제로 나갈 글자는
            // 변수값에 들어 있을 수 있고 원문에는 없을 수 있기 때문이다. 제목은 대체문자에
            // 싣지 않으므로 검사하지 않는다.
            foreach ($prepared as $one) {
                MessageText::assertFits($one['fallback_body'], null);
            }
        }

        $jobId = (int) $this->db->insert('message_jobs', [
            'channel' => $stored,
            'tpl_code' => $tplCode,
            'senderkey' => $channel === 'at' ? $account['senderkey'] : null,
            'sender' => $account['sender'],
            'title' => $stored === 'lms' ? $title : null,
            'body' => $body,
            'failover' => $failover ? 1 : 0,
            'event_key' => $request['event_key'] ?? null,
            'created_by' => $request['created_by'] ?? null,
            'total' => count($prepared),
            'success' => 0, 'failure' => 0,
            'status' => $scheduledAt !== null ? 'scheduled' : 'sending',
            'test_mode' => $account['test_mode'] ? 1 : 0,
            'scheduled_at' => $scheduledAt,
            'created_at' => Clock::now(),
        ]);

        foreach ($prepared as $index => $one) {
            $prepared[$index]['id'] = (int) $this->db->insert('message_recipients', [
                'job_id' => $jobId,
                'phone' => $one['phone'],
                'name' => $one['name'],
                'user_id' => $one['user_id'],
                // 나간 본문이 아니라 **표에 남길 사본**이다. 비밀을 담은 변수가
                // 신고된 발송에서는 그 값만 가려져 있다(secret_vars, prepare() 주석).
                'body' => $one['stored'],
                'status' => 'queued',
                'fallback_body' => $failover ? $one['fallback_stored'] : null,
                'requested_at' => Clock::now(),
            ]);
        }

        $success = 0;
        $failure = 0;
        $pending = 0;
        $providerCosts = [];
        foreach (array_chunk($prepared, self::CHUNK) as $chunk) {
            try {
                $result = $channel === 'at'
                    ? $this->alimtalk->send($this->alimtalkFields($chunk, $request, $account, $scheduledAt))
                    : $this->sms->sendMass($this->smsFields($chunk, $stored, $title, $account, $scheduledAt));
            } catch (DomainError | TransportFailure $e) {
                // 발송 자체가 실패했다(응답이 없거나 알리고가 거절했다). 재시도하지 않는다 —
                // 응답을 못 받은 채 다시 보내면 중복 발송이 된다.
                $this->markChunk($chunk, 'failed', null, $e->getMessage());
                $failure += count($chunk);
                continue;
            }

            // 알리고는 이미 이 묶음을 받아들였다. markChunk() 를 위 try 안에 두면, 전송은
            // 성공했는데 그 결과를 남기는 DB 쓰기만 실패해도 위 catch 로 떨어져 'failed'로
            // 덮인다 — 이력에는 "안 보냄"으로 남고, 그걸 보고 관리자가 다시 보내면 실제
            // 전화기에는 중복 발송이 된다. 그래서 markChunk() 를 try 밖으로 빼 그 예외가
            // 발송 실패와 섞이지 않게 한다. 기록 자체가 실패하면 여기서 그대로 올려보낸다 —
            // 작업이 'sending' 상태로 남는 편이 "결과를 모른다"는 정직한 표시다.
            $this->markChunk($chunk, 'accepted', $result['mid'], null);
            if ($channel === 'at') {
                // API가 반환한 비용만 접수 시점에 보존한다. 이력 조회에는 이 정보가 없다.
                // 결과 실패·예약 취소·대체문자 비용을 이 값에서 임의로 빼거나 더하지 않는다.
                $providerCosts[] = [
                    'mid' => $result['mid'], 'source' => 'aligo.alimtalk.send',
                    'scnt' => $result['scnt'], 'fcnt' => $result['fcnt'],
                    'recorded_at' => Clock::now(), 'cost' => $result['cost'] ?? null,
                ];
                $this->db->update('message_jobs', [
                    'provider_costs' => json_encode($providerCosts, JSON_THROW_ON_ERROR),
                ], 'id = :id', ['id' => $jobId]);
            }
            // 묶음 크기가 아니라 알리고가 돌려준 접수 건수를 쓴다. 500명을 보냈는데
            // scnt 498·fcnt 2 로 답했다면 2명은 접수조차 되지 않은 것이고, 그걸
            // count($chunk) 로 세면 이력이 500건 성공이라고 거짓말한다. 어느 2명인지는
            // 알리고가 알려주지 않으므로 수신자 행은 모두 'accepted' 로 남는다 —
            // 그 2명은 결과 조회에서 끝내 답이 없어 나중에 'unknown' 으로 정리된다.
            $success += $result['scnt'];
            $failure += $result['fcnt'];
            $pending += count($chunk);
        }

        $this->db->update('message_jobs', [
            'success' => $success, 'failure' => $failure,
            // 예약은 적어도 한 묶음이라도 접수(booked)됐을 때만 'scheduled'다 — 그
            // 묶음은 실제로 그 시각에 나갈 것이고 취소도 할 수 있다. 일부가 섞여 있어도
            // 'partial'로 적지 않는다 — 'partial'은 "이미 다 끝났는데 일부만 실패"를
            // 뜻하고, 예약은 아직 끝나지 않았다(그 시각에 나간다). 실패 건수는 이미
            // failure 칼럼에 남는다. 반대로 단 하나도 접수되지 못했다면(pending === 0,
            // 곧 success === 0) 알리고는 이 예약을 전혀 모른다 — 'scheduled'라고 적으면
            // 화면이 시스템이 모르는 사실을 안다고 말하는 것이고, 나중에 취소를 시도하면
            // 존재하지 않는 mid를 취소하려 든다. 그럴 때는 보통 발송과 같은 규칙
            // (JobStatus::of())으로 넘겨 'failed'가 되게 한다.
            'status' => ($scheduledAt !== null && $pending > 0)
                ? 'scheduled'
                : JobStatus::of($success, $failure, $pending, 0),
            // 접수된 채 기다리는(pending) 수신자가 있으면 아직 끝난 것이 아니다 — 예약이
            // 살아 있는 경우도 pending > 0 이므로 이 규칙 하나로 함께 풀린다. 예약이든
            // 아니든 pending === 0 이면 더 일어날 일이 없으므로 그 자리에서 끝난다.
            'finished_at' => $pending === 0 ? Clock::now() : null,
        ], 'id = :id', ['id' => $jobId]);

        return $jobId;
    }

    /** @return array{0:string,1:?string} 본문과 템플릿 코드 */
    private function resolveBody(string $channel, array $request): array
    {
        if ($channel !== 'at') {
            $body = trim((string) ($request['body'] ?? ''));
            if ($body === '') {
                throw DomainError::validation(['body' => '본문을 입력해 주세요.']);
            }

            return [$body, null];
        }

        $code = trim((string) ($request['tpl_code'] ?? ''));
        $template = $code === '' ? null : $this->templates->find($code);
        if ($template === null || !$this->templates->canUse($template)) {
            throw DomainError::validation(['tpl_code' => '현재 채널의 승인된 템플릿을 골라 주세요.']);
        }

        return [(string) $template['content'], $code];
    }

    /**
     * 번호를 정규화하고 중복을 없애며 변수를 치환한다. 하나라도 어긋나면 작업을 만들지 않는다.
     *
     * 수신자 하나마다 본문이 **둘**이다.
     *   body    실제로 알리고에 나가는 본문. 길이 분류(classify)와 길이 검사도 이것으로 한다.
     *   stored  message_recipients 에 남길 사본. $secret 에 적힌 변수의 값만 '***' 로
     *           바뀌어 있고, 신고된 변수가 없으면 body 와 글자 하나까지 같다.
     *
     * 둘을 가르는 이유는 Notify\Events 의 secret 주석에 있다 — 짧게는, 비밀번호 재설정
     * 링크는 코어가 어디에도 되돌릴 수 있는 형태로 저장하지 않기로 한 값인데 발송 이력은
     * 영구 표이고 백업에 따라다닌다. 가리는 자리를 여기로 둔 것은 여기가 두 본문이 함께
     * 있는 유일한 곳이기 때문이다. 이력 화면이 잃는 것은 수신자별 본문 안의 그 값 하나뿐이다
     * (작업 본문은 치환 전 원문 그대로라 문구는 남고, 상세 화면은 애초에 수신자 본문을
     * 보여주지 않는다).
     *
     * @param list<string> $secret 값을 표에 남기지 않을 변수 이름
     */
    private function prepare(string $body, array $recipients, array $secret = []): array
    {
        $prepared = [];
        $seen = [];
        foreach ($recipients as $one) {
            $phone = PhoneNumber::normalize((string) ($one['phone'] ?? ''));
            if (isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $vars = (array) ($one['vars'] ?? []);
            $real = Variables::apply($body, $vars);
            $fallbackTemplate = $one['fallback_body'] ?? null;
            $fallbackVars = (array) ($one['fallback_vars'] ?? []);
            $fallbackSecret = array_values(array_filter(
                (array) ($one['fallback_secret_vars'] ?? []), 'is_string'));
            $fallbackReal = is_string($fallbackTemplate) && $fallbackTemplate !== ''
                ? Variables::apply($fallbackTemplate, $fallbackVars) : $real;
            $fallbackStored = is_string($fallbackTemplate) && $fallbackTemplate !== ''
                ? Variables::apply($fallbackTemplate, self::hide($fallbackVars, $fallbackSecret))
                : ($secret === [] ? $real : Variables::apply($body, self::hide($vars, $secret)));
            $prepared[] = [
                'phone' => $phone,
                'name' => ($one['name'] ?? '') !== '' ? (string) $one['name'] : null,
                'user_id' => ($one['user_id'] ?? '') !== '' ? (string) $one['user_id'] : null,
                'body' => $real,
                'fallback_body' => $fallbackReal,
                'fallback_stored' => $fallbackStored,
                // 가릴 것이 없으면 두 번 치환하지 않는다. '***' 는 빈 값이 아니므로
                // Variables::apply() 가 거절하지 않는다 — 가린 쪽만 따로 터지는 일은 없다.
                'stored' => $secret === [] ? $real : Variables::apply($body, self::hide($vars, $secret)),
            ];
        }

        return $prepared;
    }

    /** 표에 남기지 않을 값을 가린 변수 묶음. 없는 이름은 만들지 않는다. */
    private static function hide(array $vars, array $secret): array
    {
        foreach ($secret as $name) {
            if (array_key_exists($name, $vars)) {
                $vars[$name] = '***';
            }
        }

        return $vars;
    }

    /**
     * 요청이 신고한 "값을 표에 남기지 않을 변수" 이름들. 확장도 보내는 요청이라
     * 문자열이 아닌 것은 조용히 버린다 — 여기서 터뜨려 발송을 막을 만한 값이 아니다.
     *
     * @return list<string>
     */
    private static function secretNames(array $request): array
    {
        $names = [];
        foreach ((array) ($request['secret_vars'] ?? []) as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** 치환이 끝난 수신자 본문 중 하나라도 SMS 경계를 넘으면 작업 전체를 LMS 로 분류한다. */
    private function classify(array $prepared): string
    {
        foreach ($prepared as $one) {
            if (MessageText::channelFor($one['body']) === 'lms') {
                return 'lms';
            }
        }

        return 'sms';
    }

    private function alimtalkFields(array $chunk, array $request, array $account, ?string $scheduledAt): array
    {
        $fields = [
            'senderkey' => $account['senderkey'],
            'tpl_code' => (string) $request['tpl_code'],
            'sender' => $account['sender'],
            'testMode' => $account['test_mode'] ? 'Y' : 'N',
        ];
        if ($scheduledAt !== null) {
            // 알림톡은 문자와 달리 한 칸(senddate)에 날짜·시간을 함께 담는다.
            $fields['senddate'] = SendTime::alimtalk($scheduledAt);
        }
        if (!empty($request['failover'])) {
            $fields['failover'] = 'Y';
        }
        $n = 0;
        foreach ($chunk as $one) {
            $n++;
            $fields['receiver_' . $n] = $one['phone'];
            $fields['message_' . $n] = $one['body'];
            $fields['subject_' . $n] = mb_substr($one['body'], 0, 20);
            if ($one['name'] !== null) {
                $fields['recvname_' . $n] = $one['name'];
            }
            if (!empty($request['failover'])) {
                $fields['fmessage_' . $n] = $one['fallback_body'];
            }
        }

        return $fields;
    }

    private function smsFields(array $chunk, string $stored, ?string $title, array $account, ?string $scheduledAt): array
    {
        $fields = [
            'sender' => $account['sender'],
            'cnt' => (string) count($chunk),
            'msg_type' => strtoupper($stored),
            'testmode_yn' => $account['test_mode'] ? 'Y' : 'N',
        ];
        if ($scheduledAt !== null) {
            // 문자는 날짜(rdate)와 시간(rtime)을 각각 다른 칸에 담는다.
            $fields += SendTime::sms($scheduledAt);
        }
        if ($stored === 'lms' && $title !== null) {
            $fields['title'] = $title;
        }
        $n = 0;
        foreach ($chunk as $one) {
            $n++;
            $fields['rec_' . $n] = $one['phone'];
            $fields['msg_' . $n] = $one['body'];
        }

        return $fields;
    }

    /**
     * 예약된 작업을 취소한다. 500명이 넘는 작업은 묶음마다 다른 mid 를 갖고, 그중
     * 일부만 취소될 수 있다. 그 사실을 삼키면 관리자는 전부 멈춘 줄 안다 — 그래서
     * 개수를 세어 그대로 돌려준다.
     *
     * 취소가 거절되는 이유는 하나가 아니다. 발송 5분 전을 지났을 수도 있지만(문자
     * -804), 알리고에 닿지 못했을 수도, API 키가 취소됐을 수도, 서버 IP 가 등록돼
     * 있지 않을 수도 있다 — 알림톡 코드표에는 시한 초과를 가리키는 코드 자체가 없어
     * 그쪽은 시한인지 아닌지 알아낼 방법조차 없다. 그래서 사유를 지어내지 않는다:
     * 실제로 받은 사유를 그 묶음의 수신자 행(rslt_message)에 적어 이력 상세의 "사유"
     * 칸이 그대로 보여주게 하고, 화면의 안내 문장은 개수만 말한다. 지어낸 사유는
     * 침묵보다 나쁘다 — "시한이 지났다"는 문장은 다시 시도하지 말라는 지시인데,
     * 통신이 한 번 끊겼던 것뿐이라면 다시 시도했을 때 실제로 취소됐을 것이다.
     *
     * 그래서 다시 눌러도 된다. 하나라도 취소하지 못하면 작업은 'scheduled'로 남아
     * 화면의 취소 버튼도 그대로 보이는데, 두 번째 시도는 지난번에 취소하지 못한
     * 묶음만 다시 부른다 — 이미 취소된 묶음은 수신자 행이 'cancelled'라 아래 SELECT
     * 가 고르지 않기 때문이다. 이미 멈춘 것을 다시 멈추려 들지 않으므로 안전하고,
     * 일시적인 실패였다면 이번에는 성공한다 — 그때는 지난번에 적어 둔 실패 사유도 함께
     * 지운다(아래 성공 분기 참고). 작업이 더는 'scheduled'가 아니게 된 뒤(전부 취소됐거나
     * 이미 나갔거나)에는 가드절이 422 로 거절한다.
     *
     * **묶음 일부만 취소할 수도 있다**($onlyMids). 알리고에 취소를 요청하는 단위는
     * 수신자가 아니라 접수 묶음(mid)이므로, 수신자 한 사람만 빼는 취소는 이 API 에
     * 없다 — 그래서 "이 사람의 예약을 멈춘다"는 요구는 "그 사람이 든 묶음을 멈춘다"로만
     * 옮길 수 있고, 그 묶음에 다른 사람이 함께 들어 있는지는 부르는 쪽이 판단한다
     * (AligoService::cancelScheduledForUser()). 목록을 좁혀 부른 경우에는 남은 묶음이
     * 그대로 나가므로 작업은 'cancelled'가 되지 않고 'scheduled'로 남는다.
     *
     * @param list<string>|null $onlyMids 이 묶음만 취소한다. null 이면 이 작업의 모든 묶음.
     * @return array{cancelled:int,failed:int,reasons:list<string>}
     */
    public function cancel(int $jobId, ?array $onlyMids = null): array
    {
        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
        if ($job === null || $job['status'] !== 'scheduled') {
            throw DomainError::validation(['job' => '예약된 작업만 취소할 수 있습니다.']);
        }
        if ($onlyMids === []) {
            // 좁힌 목록이 비었다 = 취소할 묶음이 없다. 모든 묶음을 뜻하는 null 과 다르다.
            return ['cancelled' => 0, 'failed' => 0, 'reasons' => []];
        }

        // job_id는 스케줄이 걸려 있어도 job.status 하나만으로는 어느 수신자가 실제로
        // 알리고에 접수됐는지 알 수 없다 — 한 묶음은 접수(mid 있음)되고 다른 묶음은
        // 접수 자체가 실패(mid 없음)했을 수 있다. status = 'accepted' 로도 한 번 더
        // 좁혀 두면, 이 작업을 다시 취소하려 시도할 때(먼저 취소된 mid가 섞여 있어도)
        // 이미 성공적으로 취소된 mid 를 또 부르지 않는다.
        $sql = 'SELECT mid FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ? AND mid IS NOT NULL AND status = ?';
        $params = [$jobId, 'accepted'];
        if ($onlyMids !== null) {
            $sql .= ' AND mid IN (' . implode(',', array_fill(0, count($onlyMids), '?')) . ')';
            $params = array_merge($params, array_values($onlyMids));
        }
        // DB 실행 계획에 따라 묶음 순서가 바뀌지 않도록 접수 순서로 취소한다.
        $sql .= ' GROUP BY mid ORDER BY MIN(id)';
        $rows = $this->db->select($sql, $params);
        $mids = array_map(static fn (array $row): string => (string) $row['mid'], $rows);

        $cancelled = 0;
        $failed = 0;
        $reasons = [];
        foreach ($mids as $mid) {
            try {
                if ($job['channel'] === 'at') {
                    $this->alimtalk->cancel($mid);
                } else {
                    $this->sms->cancel($mid);
                }
            } catch (DomainError | TransportFailure $e) {
                // 이 mid 의 취소 결과를 알 수 없는 채로 다시 부르지 않는다 — 발송과 같은
                // 이유다. 실패 하나가 나머지 mid 의 취소 시도를 막지도 않는다.
                $failed++;
                $reasons[] = $mid . ': ' . $e->getMessage();
                // 받은 사유를 그대로 이 묶음의 수신자 행에 남긴다 — 이력 상세의 "사유"
                // 칸이 이 값을 보여주므로 안내 문장이 원인을 지어낼 필요가 없다. 취소에
                // 실패한 이 묶음은 예정대로 나가므로, 나중에 결과가 들어오면
                // History::apply() 가 같은 칸을 실제 전달 결과로 덮는다.
                $this->db->update('message_recipients',
                    ['rslt_message' => mb_substr('취소하지 못했습니다: ' . $e->getMessage(), 0, 200)],
                    'job_id = :job_id AND mid = :mid AND status = :status',
                    ['job_id' => $jobId, 'mid' => $mid, 'status' => 'accepted']);
                continue;
            }
            $cancelled++;
            // rslt_message 를 함께 지운다. 지난 시도에서 이 묶음의 취소가 실패했다면 그때
            // 적은 "취소하지 못했습니다: …"가 아직 행에 남아 있는데, 이번에 멈췄으므로
            // 그 문장은 더는 참이 아니다. 지우지 않으면 상태는 '취소됨'인데 사유 칸은
            // "취소하지 못했습니다"라고 말하는 행이 영원히 남는다 — 이 행은 이제 결과
            // 조회를 타지 않으므로(History::apply() 는 'accepted' 행만 건드린다) 아무도
            // 대신 정리해 주지 않는다.
            $this->db->update('message_recipients', ['status' => 'cancelled', 'rslt_message' => null],
                'job_id = :job_id AND mid = :mid AND status = :status',
                ['job_id' => $jobId, 'mid' => $mid, 'status' => 'accepted']);
        }

        // 취소한 mid 가 하나도 없거나(있을 수 없는 상황이지만 방어적으로), 하나라도
        // 실패했다면 작업은 아직 취소된 것이 아니다 — 나갈 메시지가 남아 있는데
        // 취소됐다고 적으면 거짓말이다. 상태를 그대로 둔다.
        //
        // 접수된 채 남은 수신자가 있는지도 함께 본다. 목록을 좁혀 부른 경우
        // ($onlyMids)에는 이번에 부른 묶음이 모두 성공해도 다른 묶음은 예정대로
        // 나가므로, 그때 'cancelled'라고 적으면 나갈 메시지를 두고 멈췄다고 말하는 것이
        // 된다. 목록을 좁히지 않은 평소의 취소에서는 이 조건이 늘 참이다(모든 accepted
        // 묶음을 불렀고 전부 성공했으므로 남은 행이 없다).
        if ($cancelled > 0 && $failed === 0 && $this->acceptedCount($jobId) === 0) {
            $this->db->update('message_jobs', [
                'status' => 'cancelled',
                'cancelled_at' => Clock::now(),
            ], 'id = :id', ['id' => $jobId]);
        }

        return ['cancelled' => $cancelled, 'failed' => $failed, 'reasons' => $reasons];
    }

    /** 아직 접수된 채 결과를 기다리는 수신자 수. 취소가 작업 전체를 멈췄는지 가른다. */
    private function acceptedCount(int $jobId): int
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM '
            . $this->db->table('message_recipients') . ' WHERE job_id = ? AND status = ?',
            [$jobId, 'accepted']);

        return (int) ($row['c'] ?? 0);
    }

    private function markChunk(array $chunk, string $status, ?string $mid, ?string $reason): void
    {
        foreach ($chunk as $one) {
            $this->db->update('message_recipients', [
                'status' => $status,
                'mid' => $mid,
                'rslt_message' => $reason === null ? null : mb_substr($reason, 0, 200),
                'sent_at' => $status === 'accepted' ? Clock::now() : null,
            ], 'id = :id', ['id' => $one['id']]);
        }
    }
}
