<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

/**
 * 알리고는 결과 웹훅이 없어 조회로만 결과를 안다. 호스팅 cron 을 요구하지 않으므로
 * 관리자가 이력 화면을 열 때 조금씩 갱신한다. 접속이 없으면 늦어지며 그 사실을 화면에 적는다.
 */
final class History
{
    /**
     * jobs() 의 event 인자에 주는, "관리자가 손으로 보낸 것"을 뜻하는 값. 알림이 만든
     * 작업은 event_key 에 Notify\Events 의 키가 적히고 관리자 발송은 비어 있는데(설계
     * 문서: 관리자 수동 발송이면 NULL), "비어 있음"은 값이 아니라서 거르는 쪽에서 부를
     * 이름이 필요하다. Events 의 어떤 키와도 겹치지 않는다.
     */
    public const MANUAL = 'manual';

    public const RECHECK_SECONDS = 60;
    public const GIVE_UP_DAYS = 7;
    private const BATCH = 5;

    /**
     * 아직 발송 시각이 오지 않은 예약을 걸러내는 조건. 알리고는 scheduled_at 이 되기
     * 전에는 그 mid 에 대해 아무것도 모르므로, 그 건은 물어봐야 소용없고(조회 예산만
     * 쓴다) 결과를 기다리는 중이라고 셀 수도 없다(기다릴 것이 아직 시작되지도 않았다).
     * 조회 두 곳과 대기 수를 세는 곳이 모두 이 한 조건을 쓴다 — 셋이 어긋나면 화면이
     * "결과를 기다리는 중 N건"이라 말하면서 갱신 버튼은 아무 것도 부르지 않게 된다.
     * $sql 에 이어 붙이고 마지막 파라미터로 Clock::now() 를 준다. 작업 표의 별칭은 j 다.
     */
    private const DUE_ONLY = ' AND (j.scheduled_at IS NULL OR j.scheduled_at <= ?)';

    private Connection $db;
    private AlimtalkApi $alimtalk;
    private SmsApi $sms;
    /**
     * 이번 refresh() 에서 마지막으로 실패한 조회의 사유. 개별 조회 실패는 다음 방문에
     * 다시 시도하면 되므로 예외로 올리지 않지만, 아무 데도 남기지 않으면 키가 취소된
     * 사이트의 관리자는 결과가 천천히 "결과를 알 수 없음"으로 바뀌는 것만 보고 이유를
     * 끝내 알 수 없다. 화면이 읽어 갈 수 있게 여기 담아 둔다.
     */
    private ?string $lastFailure = null;

    public function __construct(Connection $db, AlimtalkApi $alimtalk, SmsApi $sms)
    {
        $this->db = $db;
        $this->alimtalk = $alimtalk;
        $this->sms = $sms;
    }

    /**
     * $limit 은 이번 방문에 부를 알리고 조회 횟수의 상한이다. 두 대기열(알림톡 결과·
     * 대체문자 결과)이 이 예산을 나눠 쓴다 — 예전에는 양쪽에 같은 값을 그대로 넘겨
     * "최대 5개"가 실제로는 최대 10개였다. 화면 하나가 API를 오래 붙잡지 않는 것이
     * 이 상한의 목적이므로, 합쳐서 세는 쪽이 맞다.
     */
    public function refresh(int $limit = self::BATCH): int
    {
        $this->lastFailure = null;
        $limit = max(1, $limit);
        $this->giveUpOnStaleRows();
        $this->giveUpOnStaleFallbacks();

        // 알림톡 결과와 대체문자 결과는 서로 다른 대기열이다 — 대체문자 결과 조회는
        // status 가 아니라 fallback_status 로 대상을 고른다(아래 refreshFallbacks 참고).
        $primary = $this->refreshPrimary($limit);
        $remaining = $limit - $primary['calls'];
        $fallback = $remaining > 0 ? $this->refreshFallbacks($remaining) : ['calls' => 0, 'done' => 0];

        return $primary['done'] + $fallback['done'];
    }

    /** 이번 refresh() 에서 마지막으로 실패한 조회의 사유. 실패가 없었으면 null. */
    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    /**
     * 화면에 적을 실패 사유 한 줄. 검증 오류의 겉 메시지는 "입력값을 확인해 주세요."
     * 같은 일반 문장이라 도움이 되지 않으므로, 담긴 사유("알리고 계정을 먼저 저장해
     * 주세요." 등)를 꺼내 쓴다. 어느 쪽이든 API 키 원문은 실리지 않는다.
     */
    private static function reasonOf(DomainError | TransportFailure $e): string
    {
        if ($e instanceof DomainError) {
            $details = $e->details();
            if ($details !== []) {
                return (string) reset($details);
            }
        }

        return $e->getMessage();
    }

    /** @return array{calls:int,done:int} 실제로 부른 조회 횟수와 그중 성공한 횟수 */
    private function refreshPrimary(int $limit): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', Clock::timestamp() - self::RECHECK_SECONDS);
        // 예약 발송은 접수(booked) 시점에 이미 수신자 행이 'accepted'로 찍히지만, 알리고는
        // scheduled_at 이 되기 전에는 이 mid 에 대해 아무것도 모른다 — status 만 보고 고르면
        // 아직 나가지도 않은 건에 조회 예산(화면을 한 번 열 때 다섯 번)을 쓰게 된다.
        // j.scheduled_at 이 NULL(즉시 발송)이거나 이미 지났을 때만 후보로 삼는다.
        $rows = $this->db->select(
            'SELECT r.mid AS mid, j.channel AS channel FROM ' . $this->db->table('message_recipients') . ' r'
            . ' JOIN ' . $this->db->table('message_jobs') . ' j ON j.id = r.job_id'
            . ' WHERE r.status = ? AND r.mid IS NOT NULL AND (r.checked_at IS NULL OR r.checked_at < ?)'
            . self::DUE_ONLY
            . ' GROUP BY r.mid, j.channel ORDER BY MIN(r.id) LIMIT ' . max(1, $limit),
            ['accepted', $cutoff, Clock::now()]
        );

        $calls = 0;
        $done = 0;
        foreach ($rows as $row) {
            $mid = (string) $row['mid'];
            // 같은 mid 를 다른 관리자가 동시에 묻지 않도록 먼저 표시를 찍는다.
            $claimed = $this->db->update('message_recipients', ['checked_at' => Clock::now()],
                'mid = :mid AND status = :status AND (checked_at IS NULL OR checked_at < :cutoff)',
                ['mid' => $mid, 'status' => 'accepted', 'cutoff' => $cutoff]);
            if ($claimed === 0) {
                // 다른 요청이 이미 가져갔다. API 를 부르지 않았으므로 예산도 쓰지 않았다.
                continue;
            }
            $calls++;
            try {
                $this->apply($mid, (string) $row['channel']);
            } catch (DomainError | TransportFailure $e) {
                // 조회 실패는 다음 방문에 다시 시도한다. 결과를 잃지 않는다.
                $this->lastFailure = self::reasonOf($e);
                continue;
            }
            $done++;
        }

        return ['calls' => $calls, 'done' => $done];
    }

    /**
     * 알림톡이 실패해 대체문자로 넘어간 건은 대체문자 자체의 결과(fallback_status)를
     * 따로 물어야 한다. checked_at 을 그대로 재사용한다 — 대체문자 대기열(fallback_status
     * ='accepted')과 알림톡 대기열(status='accepted')은 절대 겹치지 않는다. apply() 가
     * smid 를 적는 것과 status 를 'failed' 로 확정하는 것은 같은 UPDATE 한 번으로 이뤄지므로,
     * 한 행이 두 대기열에 동시에 걸리는 순간은 없다. 다만 알림톡 확인 직후 같은 방문 안에서
     * 바로 대체문자까지 묻지는 않는다 — 방금 그 확인 때 찍힌 checked_at 이 아직 재확인
     * 주기(60초) 안이라 다음 방문에서야 대체문자 조회가 시작된다. 데이터 유실은 아니고
     * 한 주기 늦게 시작될 뿐이다.
     *
     * @return array{calls:int,done:int} 실제로 부른 조회 횟수와 그중 성공한 횟수
     */
    private function refreshFallbacks(int $limit): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', Clock::timestamp() - self::RECHECK_SECONDS);
        // 대체문자는 원 알림톡 결과가 들어온 뒤에야 생기므로 이 시점엔 scheduled_at 은
        // 이미 지나 있을 수밖에 없다 — 그래도 조건을 refreshPrimary() 와 똑같이 맞춰
        // 둔다. 판단 기준이 한 곳(self::DUE_ONLY)에만 있어야 어긋나지 않는다.
        $rows = $this->db->select(
            'SELECT r.smid AS smid FROM ' . $this->db->table('message_recipients') . ' r'
            . ' JOIN ' . $this->db->table('message_jobs') . ' j ON j.id = r.job_id'
            . ' WHERE r.fallback_status = ? AND r.smid IS NOT NULL AND (r.checked_at IS NULL OR r.checked_at < ?)'
            . self::DUE_ONLY
            . ' GROUP BY r.smid ORDER BY MIN(r.id) LIMIT ' . max(1, $limit),
            ['accepted', $cutoff, Clock::now()]
        );

        $calls = 0;
        $done = 0;
        foreach ($rows as $row) {
            $smid = (string) $row['smid'];
            $claimed = $this->db->update('message_recipients', ['checked_at' => Clock::now()],
                'smid = :smid AND fallback_status = :status AND (checked_at IS NULL OR checked_at < :cutoff)',
                ['smid' => $smid, 'status' => 'accepted', 'cutoff' => $cutoff]);
            if ($claimed === 0) {
                continue;
            }
            $calls++;
            try {
                $this->applyFallback($smid);
            } catch (DomainError | TransportFailure $e) {
                // 이번 조회 실패도 다음 방문에 다시 시도한다.
                $this->lastFailure = self::reasonOf($e);
                continue;
            }
            $done++;
        }

        return ['calls' => $calls, 'done' => $done];
    }

    private function apply(string $mid, string $channel): void
    {
        $isAlimtalk = $channel === 'at';
        $list = $isAlimtalk ? $this->alimtalk->detail($mid) : $this->sms->detail($mid);
        foreach ($list as $item) {
            $phone = PhoneNumber::digits((string) ($item['phone'] ?? $item['receiver'] ?? ''));
            if ($phone === '') {
                continue;
            }
            if ($isAlimtalk) {
                $rslt = (string) ($item['rslt'] ?? '');
                $ok = $rslt === 'S' || $rslt === '';
                $reason = $ok ? null : ResultCodes::deliveryReason($rslt, (string) ($item['rslt_message'] ?? ''));
                $msgid = (string) ($item['msgid'] ?? '');
                $smid = ($item['smid'] ?? '') !== '' ? (string) $item['smid'] : null;
            } else {
                $ok = self::smsSucceeded($item);
                $rslt = $ok ? 'S' : 'F';
                $state = (string) ($item['sms_state'] ?? '');
                $reason = $ok ? null : ($state !== '' ? $state : '전송에 실패했습니다.');
                $msgid = (string) ($item['mdid'] ?? '');
                $smid = null;
            }

            $this->db->update('message_recipients', [
                'msgid' => $msgid !== '' ? $msgid : null,
                'status' => $ok ? 'sent' : 'failed',
                'rslt' => $rslt !== '' ? $rslt : null,
                'rslt_message' => $reason === null ? null : mb_substr($reason, 0, 200),
                'smid' => $smid,
                'fallback_status' => $smid !== null ? 'accepted' : null,
                'result_at' => Clock::now(),
            ], 'mid = :mid AND phone = :phone', ['mid' => $mid, 'phone' => $phone]);
        }
        $this->recomputeJobs('mid', $mid);
    }

    /**
     * 대체문자는 항상 문자이므로 SmsApi::detail() 을 그대로 쓴다. 결과는 fallback_status 에만
     * 적는다 — 원 알림톡의 status·rslt·rslt_message 는 알림톡 자체의 결과이므로 건드리지 않는다.
     */
    private function applyFallback(string $smid): void
    {
        $list = $this->sms->detail($smid);
        foreach ($list as $item) {
            $phone = PhoneNumber::digits((string) ($item['receiver'] ?? ''));
            if ($phone === '') {
                continue;
            }
            $this->db->update('message_recipients', [
                'fallback_status' => self::smsSucceeded($item) ? 'sent' : 'failed',
            ], 'smid = :smid AND phone = :phone', ['smid' => $smid, 'phone' => $phone]);
        }
        $this->recomputeJobs('smid', $smid);
    }

    /**
     * 방금 결과를 적은 수신자 행이 속한 작업의 집계를 다시 센다. mid·smid 하나는 한
     * 작업 안에서만 쓰이지만(500명 분할은 작업 안에서 일어난다), 값이 겹쳐 들어오는
     * 경우까지 안전하도록 관련된 작업을 모두 다시 센다.
     */
    private function recomputeJobs(string $column, string $value): void
    {
        $rows = $this->db->select('SELECT DISTINCT job_id FROM '
            . $this->db->table('message_recipients') . ' WHERE ' . $column . ' = ?', [$value]);
        foreach ($rows as $row) {
            $this->recomputeJob((int) $row['job_id']);
        }
    }

    /**
     * 작업 하나의 집계를 수신자 행에서 다시 센다. 취소처럼 결과 조회를 거치지 않고
     * 수신자 행을 바꾸는 일이 일어난 뒤에 호출부(AligoService)가 부른다 — 결과가
     * 들어오는 경로는 apply()·applyFallback() 이 알아서 부른다.
     */
    public function recompute(int $jobId): void
    {
        $this->recomputeJob($jobId);
    }

    /**
     * 작업의 success·failure·cancelled·status 를 수신자 행에서 다시 센다. Dispatch 가
     * 접수 직후에 적은 값은 "알리고가 몇 건을 접수했나"였고, 결과가 들어오기 시작하면
     * "몇 명에게 실제로 갔나"로 바뀌어야 한다. 이걸 하지 않으면 전원이 차단으로 실패한
     * 작업이 목록에서는 "상태 성공 · 성공 500 · 실패 0" 으로 보이고 상세에서는 500건
     * 실패가 나열되는, 같은 화면이 스스로 모순되는 이력이 된다.
     *
     * 대체발송을 켠 알림톡은 fallback_status 가 최종 답이다 — 알림톡이 실패해도
     * 대체문자가 도착했으면 그 사람은 메시지를 받았다. 그래서 COALESCE 로 대체발송
     * 결과를 먼저 본다. 각 상태가 무엇으로 집계되는지는 JobStatus 에 적어 뒀다 —
     * 취소된 수신자가 작업 수준에서 무엇을 뜻하는지도 그 한 곳에 적혀 있다.
     *
     * 숫자는 언제나 다시 센다. 상태만 두 경우에 그대로 둔다:
     *   - 취소: 관리자가 멈췄다는 사실이므로 뒤늦게 도착한 결과(취소에 실패한 mid 가
     *     나중에 응답을 줄 수 있다)가 있어도 절대 뒤집지 않는다.
     *   - 예약: 발송 시각이 오기 전까지만 그렇다. 접수(booked) 직후부터 수신자는 이미
     *     'accepted'라 그 사이 집계만 보면 'sending'으로 보이겠지만, 알리고는 그 시각이
     *     오기 전엔 아무것도 하지 않았으므로 작업은 여전히 예약일 뿐이다. 시각이 지나면
     *     보통 작업처럼 집계를 따라 상태가 넘어간다.
     * 예전에는 이 두 경우에 숫자까지 멈춰 세웠는데, 그러면 전원 취소된 502명짜리
     * 작업이 접수 건수를 그대로 쥔 채 "총 502 · 성공 502 · 실패 0 · 취소됨"으로 남는다 —
     * 아무에게도 가지 않았는데 502건 성공이라고 적힌 화면이다. 상태를 지키는 것과
     * 숫자를 멈추는 것은 다른 일이다.
     */
    private function recomputeJob(int $jobId): void
    {
        $job = $this->db->selectOne('SELECT status, scheduled_at, finished_at FROM '
            . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        if ($job === null) {
            // 작업 자체가 없으면(있을 수 없지만 방어적으로) 셀 것이 없다.
            return;
        }

        $effective = 'COALESCE(fallback_status, status)';
        $tally = $this->db->selectOne(
            'SELECT'
            . ' SUM(CASE WHEN ' . $effective . " = 'sent' THEN 1 ELSE 0 END) AS s,"
            . ' SUM(CASE WHEN ' . $effective . " = 'failed' THEN 1 ELSE 0 END) AS f,"
            . ' SUM(CASE WHEN ' . $effective . " IN ('queued', 'accepted') THEN 1 ELSE 0 END) AS p,"
            . ' SUM(CASE WHEN ' . $effective . " = 'unknown' THEN 1 ELSE 0 END) AS u,"
            . ' SUM(CASE WHEN ' . $effective . " = 'cancelled' THEN 1 ELSE 0 END) AS x,"
            . ' COUNT(*) AS c FROM ' . $this->db->table('message_recipients') . ' WHERE job_id = ?',
            [$jobId]
        );
        if ($tally === null || (int) $tally['c'] === 0) {
            // 수신자 행이 하나도 없으면 셀 것이 없다. 있지도 않은 결과로 상태를 덮지 않는다.
            return;
        }

        $pending = (int) $tally['p'];
        $fields = [
            'success' => (int) $tally['s'],
            'failure' => (int) $tally['f'],
            'cancelled' => (int) $tally['x'],
        ];
        if (!$this->statusIsAlreadyDecided($job)) {
            $fields['status'] = JobStatus::of((int) $tally['s'], (int) $tally['f'], $pending,
                (int) $tally['u'], (int) $tally['x']);
        }
        if ($pending === 0 && ($job['finished_at'] ?? null) === null) {
            // 더 기다릴 수신자가 없으면 그때가 이 작업이 끝난 시각이다. 이미 적혀 있으면
            // 그대로 둔다 — 늦게 온 결과가 종료 시각을 계속 뒤로 미루면 안 된다.
            $fields['finished_at'] = Clock::now();
        }

        $this->db->update('message_jobs', $fields, 'id = :id', ['id' => $jobId]);
    }

    /** 집계가 상태를 정하면 안 되는 작업인지. 이유는 recomputeJob() 문서 주석에 있다. */
    private function statusIsAlreadyDecided(array $job): bool
    {
        if ($job['status'] === 'cancelled') {
            return true;
        }

        return $job['status'] === 'scheduled'
            && $job['scheduled_at'] !== null && $job['scheduled_at'] > Clock::now();
    }

    /**
     * 문자 조회 응답 한 건이 성공인지 판단한다. 알림톡 대체문자와 순수 문자 발송이 함께 쓴다.
     * 비교 대상인 '성공'은 UTF-8 문자열이다 — 문자 API 응답은 EUC-KR 로 올 수 있으므로
     * SmsApi::call() 이 디코딩 전에 UTF-8 로 맞춰 준다. 그 정규화를 거치지 않은 본문과
     * 비교하면 실제로 성공한 건이 전부 실패로 기록된다.
     */
    private static function smsSucceeded(array $item): bool
    {
        return str_contains((string) ($item['sms_state'] ?? ''), '성공');
    }

    /**
     * 오래된 건은 조회를 멈춘다. 무한히 묻지 않는다. 포기한 뒤에는 그 작업의 집계를
     * 다시 센다 — 포기한 건은 결과 조회를 더 타지 않으므로 여기서 정리하지 않으면
     * 작업이 영원히 "결과를 기다리는 중"으로 남는다.
     *
     * 포기 시계는 requested_at(접수를 요청한 시각)이 아니라 COALESCE(scheduled_at,
     * requested_at)부터 잰다 — 예약은 접수와 실제 발송 사이에 최대 30일(SendTime::MAX_DAYS)
     * 간격이 날 수 있어서, requested_at 만 보면 5일 뒤로 예약한 건이 나가기도 전에
     * "결과를 알 수 없음"이 된다. 작업을 봐야 하므로 SELECT 쪽은 JOIN 을 쓰고, UPDATE
     * 쪽은 SQLite 가 UPDATE...JOIN 을 지원하지 않아 같은 조건을 상관 서브쿼리로 쓴다 —
     * DISTINCT job_id 로 작업을 추려 recomputeJob() 을 도는 지금 구조는 그대로 둔다.
     */
    private function giveUpOnStaleRows(): void
    {
        $limit = gmdate('Y-m-d H:i:s', Clock::timestamp() - self::GIVE_UP_DAYS * 86400);
        $jobs = $this->db->select(
            'SELECT DISTINCT r.job_id AS job_id FROM ' . $this->db->table('message_recipients') . ' r'
            . ' JOIN ' . $this->db->table('message_jobs') . ' j ON j.id = r.job_id'
            . ' WHERE r.status = ? AND COALESCE(j.scheduled_at, r.requested_at) < ?',
            ['accepted', $limit]
        );
        if ($jobs === []) {
            return;
        }
        $this->db->update('message_recipients', ['status' => 'unknown'],
            'status = :status AND COALESCE('
            . '(SELECT scheduled_at FROM ' . $this->db->table('message_jobs') . ' WHERE id = job_id),'
            . ' requested_at) < :limit',
            ['status' => 'accepted', 'limit' => $limit]);
        foreach ($jobs as $job) {
            $this->recomputeJob((int) $job['job_id']);
        }
    }

    /**
     * 대체문자도 오래되면 그만 묻는다. 기준은 requested_at(원 발송 요청 시각)이 아니라
     * result_at(대체문자가 있었다는 사실을 알아낸 시각)이다 — 알림톡 결과 자체가 늦게
     * 확인되면 requested_at 은 이미 오래됐을 수 있지만, 대체문자를 기다리기 시작한 것은
     * 그 실패를 안 순간부터다.
     *
     * 여기만 scheduled_at 을 보지 않는 것도 같은 이유로 일부러 그렇게 둔 것이다:
     * 대체문자는 알림톡 결과가 들어온 뒤에야 생기므로 이 행이 존재하는 시점에 예약
     * 시각은 이미 지나 있고, result_at 이 언제나 그보다 늦다. 예약 때문에 시계가 일찍
     * 도는 일은 여기서는 일어날 수 없다 — 나중에 이 차이를 보고 예약 버그로 의심하며
     * giveUpOnStaleRows() 처럼 COALESCE 를 붙이지 않아도 된다.
     */
    private function giveUpOnStaleFallbacks(): void
    {
        $limit = gmdate('Y-m-d H:i:s', Clock::timestamp() - self::GIVE_UP_DAYS * 86400);
        $jobs = $this->db->select('SELECT DISTINCT job_id FROM ' . $this->db->table('message_recipients')
            . ' WHERE fallback_status = ? AND result_at < ?', ['accepted', $limit]);
        if ($jobs === []) {
            return;
        }
        $this->db->update('message_recipients', ['fallback_status' => 'unknown'],
            'fallback_status = :status AND result_at < :limit', ['status' => 'accepted', 'limit' => $limit]);
        foreach ($jobs as $job) {
            $this->recomputeJob((int) $job['job_id']);
        }
    }

    /**
     * 아직 결과를 기다리는 수신자 수. 이력 화면의 "결과를 기다리는 중" 안내와 갱신
     * 버튼이 이 값으로 나타나고 사라진다(설정 화면의 배지도 AligoService::status() 를
     * 거쳐 같은 값을 쓴다). 대체문자 대기열(fallback_status)도 함께 세야 한다 —
     * 알림톡 결과가 다 잡힌 뒤 대체문자 결과만 남은 동안에도 갱신할 일이 남아 있는데,
     * status 만 세면 그 사이 안내와 버튼이 통째로 사라진다.
     *
     * 발송 시각이 아직 오지 않은 예약은 세지 않는다(self::DUE_ONLY — 두 조회가 쓰는
     * 것과 같은 조건이다). 예약은 접수 직후부터 수신자가 'accepted'라 조건 없이 세면
     * 5일 뒤로 잡아 둔 예약의 수신자까지 "결과를 기다리는 중"에 들어간다 — 화면은
     * 그 숫자와 함께 갱신 버튼을 내주지만, 그 버튼은 발송 시각이 올 때까지(최대 30일)
     * 알리고를 단 한 번도 부르지 않는다. 설계 문서 §5-1 이 그대로 금지하는 거짓말이다:
     * 알리고가 접수만 하고 결과가 없는 것이 정상인 상태를 §5 의 "대기 중"에 섞지 않는다.
     */
    public function pendingCount(): int
    {
        return (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM '
            . $this->db->table('message_recipients') . ' r'
            . ' JOIN ' . $this->db->table('message_jobs') . ' j ON j.id = r.job_id'
            . ' WHERE (r.status = ? OR r.fallback_status = ?)'
            . self::DUE_ONLY, ['accepted', 'accepted', Clock::now()])['c'];
    }

    /**
     * 이력 목록. $event 는 event_key 로 거르는 값이다.
     *
     *  - null       — 거르지 않는다(전부)
     *  - self::MANUAL — 관리자가 손으로 보낸 것만(event_key 가 비어 있는 작업)
     *  - 그 밖의 값  — 그 알림이 만든 작업만(Notify\Events 의 키)
     *
     * 거른 목록과 총 개수는 반드시 같은 조건을 써야 한다 — 어긋나면 한 페이지짜리
     * 목록에 열 페이지짜리 페이저가 붙는다. 그래서 조건을 한 번만 만들어 둘 다에 준다.
     */
    public function jobs(int $page = 1, int $perPage = 20, ?string $event = null): array
    {
        $page = max(1, $page);
        [$where, $params] = self::eventFilter($event);
        $total = (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM '
            . $this->db->table('message_jobs') . $where, $params)['c'];
        $items = $this->db->select('SELECT * FROM ' . $this->db->table('message_jobs') . $where
            . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage,
            'event' => $event];
    }

    /**
     * 위 필터의 WHERE 절과 파라미터. 수동 발송은 event_key 가 NULL 인 작업이지만
     * 빈 문자열도 같이 본다 — 뜻이 같은 두 저장 모양 중 하나만 세면 그 작업은 "전체"
     * 에만 나타나고 어느 갈래에도 들어가지 않는다.
     *
     * @return array{0:string,1:list<string>}
     */
    private static function eventFilter(?string $event): array
    {
        if ($event === null) {
            return ['', []];
        }
        if ($event === self::MANUAL) {
            return [" WHERE (event_key IS NULL OR event_key = '')", []];
        }

        return [' WHERE event_key = ?', [$event]];
    }

    public function job(int $id): ?array
    {
        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs') . ' WHERE id = ?', [$id]);
        if ($job === null) {
            return null;
        }
        $job['recipients'] = $this->db->select('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ? ORDER BY id', [$id]);

        return $job;
    }
}
