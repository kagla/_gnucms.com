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
 *   event_key  이 발송을 일으킨 알림 이벤트 (선택)
 *   created_by 요청한 관리자 표시명 (선택)
 *   recipients [['phone' =>, 'name' =>, 'user_id' =>, 'vars' => []], ...]
 *
 * message_jobs.status 는 네 값 중 하나다: 'sending'(발송 중 — 결과 기록 자체가 실패해
 * 여기 머무를 수도 있다), 'sent'(전원 성공), 'failed'(전원 실패), 'partial'(일부만 성공).
 * 이력 화면은 이 네 값을 모두 구분해서 보여줘야 한다.
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

        [$body, $tplCode] = $this->resolveBody($channel, $request);
        $title = trim((string) ($request['title'] ?? '')) ?: null;
        $failover = $channel === 'at' && !empty($request['failover']);

        // 변수를 먼저 채운 뒤에 분류·길이를 검사한다. 템플릿 상태로는 90바이트 이하라도
        // 이름 같은 변수를 채우면 쉽게 넘어간다. msg_type 을 명시해서 보내기 때문에,
        // 알리고는 짧게 신고해 놓고 긴 본문을 보내도 자동으로 LMS 로 올려주지 않는다.
        $prepared = $this->prepare($body, $request['recipients'] ?? []);
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
            // 알림톡 본문 자체는 UTF-8 그대로 나가 EUC-KR 제약을 받지 않지만, 실패했을 때
            // 대신 나가는 대체문자(fmessage_N)는 문자 API와 똑같이 EUC-KR 로 나간다. 여기서
            // 걸러 두지 않으면 이모지 같은 글자가 깨진 채로 대체문자에 실려 나간다. 템플릿
            // 원문이 아니라 변수 치환이 끝난 수신자별 본문을 검사한다 — 실제로 나갈 글자는
            // 변수값에 들어 있을 수 있고 원문에는 없을 수 있기 때문이다. 제목은 대체문자에
            // 싣지 않으므로 검사하지 않는다.
            foreach ($prepared as $one) {
                MessageText::assertFits($one['body'], null);
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
            'status' => 'sending',
            'test_mode' => $account['test_mode'] ? 1 : 0,
            'created_at' => Clock::now(),
        ]);

        foreach ($prepared as $index => $one) {
            $prepared[$index]['id'] = (int) $this->db->insert('message_recipients', [
                'job_id' => $jobId,
                'phone' => $one['phone'],
                'name' => $one['name'],
                'user_id' => $one['user_id'],
                'body' => $one['body'],
                'status' => 'queued',
                'fallback_body' => $failover ? $one['body'] : null,
                'requested_at' => Clock::now(),
            ]);
        }

        $success = 0;
        $failure = 0;
        foreach (array_chunk($prepared, self::CHUNK) as $chunk) {
            try {
                $result = $channel === 'at'
                    ? $this->alimtalk->send($this->alimtalkFields($chunk, $request, $account))
                    : $this->sms->sendMass($this->smsFields($chunk, $stored, $title, $account));
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
            $success += count($chunk);
        }

        $status = $failure === 0 ? 'sent' : ($success === 0 ? 'failed' : 'partial');
        $this->db->update('message_jobs', [
            'success' => $success, 'failure' => $failure,
            'status' => $status,
            'finished_at' => Clock::now(),
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
        if ($template === null || (int) $template['enabled'] !== 1) {
            throw DomainError::validation(['tpl_code' => '사용 중인 승인 템플릿을 골라 주세요.']);
        }

        return [(string) $template['content'], $code];
    }

    /** 번호를 정규화하고 중복을 없애며 변수를 치환한다. 하나라도 어긋나면 작업을 만들지 않는다. */
    private function prepare(string $body, array $recipients): array
    {
        $prepared = [];
        $seen = [];
        foreach ($recipients as $one) {
            $phone = PhoneNumber::normalize((string) ($one['phone'] ?? ''));
            if (isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $prepared[] = [
                'phone' => $phone,
                'name' => ($one['name'] ?? '') !== '' ? (string) $one['name'] : null,
                'user_id' => ($one['user_id'] ?? '') !== '' ? (string) $one['user_id'] : null,
                'body' => Variables::apply($body, (array) ($one['vars'] ?? [])),
            ];
        }

        return $prepared;
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

    private function alimtalkFields(array $chunk, array $request, array $account): array
    {
        $fields = [
            'senderkey' => $account['senderkey'],
            'tpl_code' => (string) $request['tpl_code'],
            'sender' => $account['sender'],
            'testMode' => $account['test_mode'] ? 'Y' : 'N',
        ];
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
                $fields['fmessage_' . $n] = $one['body'];
            }
        }

        return $fields;
    }

    private function smsFields(array $chunk, string $stored, ?string $title, array $account): array
    {
        $fields = [
            'sender' => $account['sender'],
            'cnt' => (string) count($chunk),
            'msg_type' => strtoupper($stored),
            'testmode_yn' => $account['test_mode'] ? 'Y' : 'N',
        ];
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
