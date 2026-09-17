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
    public const RECHECK_SECONDS = 60;
    public const GIVE_UP_DAYS = 7;
    private const BATCH = 5;

    private Connection $db;
    private AlimtalkApi $alimtalk;
    private SmsApi $sms;

    public function __construct(Connection $db, AlimtalkApi $alimtalk, SmsApi $sms)
    {
        $this->db = $db;
        $this->alimtalk = $alimtalk;
        $this->sms = $sms;
    }

    public function refresh(int $limit = self::BATCH): int
    {
        $this->giveUpOnStaleRows();
        $this->giveUpOnStaleFallbacks();

        // 알림톡 결과와 대체문자 결과는 서로 다른 대기열이다 — 대체문자 결과 조회는
        // status 가 아니라 fallback_status 로 대상을 고른다(아래 refreshFallbacks 참고).
        return $this->refreshPrimary($limit) + $this->refreshFallbacks($limit);
    }

    private function refreshPrimary(int $limit): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', Clock::timestamp() - self::RECHECK_SECONDS);
        $rows = $this->db->select(
            'SELECT r.mid AS mid, j.channel AS channel FROM ' . $this->db->table('message_recipients') . ' r'
            . ' JOIN ' . $this->db->table('message_jobs') . ' j ON j.id = r.job_id'
            . ' WHERE r.status = ? AND r.mid IS NOT NULL AND (r.checked_at IS NULL OR r.checked_at < ?)'
            . ' GROUP BY r.mid, j.channel ORDER BY MIN(r.id) LIMIT ' . max(1, $limit),
            ['accepted', $cutoff]
        );

        $done = 0;
        foreach ($rows as $row) {
            $mid = (string) $row['mid'];
            // 같은 mid 를 다른 관리자가 동시에 묻지 않도록 먼저 표시를 찍는다.
            $claimed = $this->db->update('message_recipients', ['checked_at' => Clock::now()],
                'mid = :mid AND status = :status AND (checked_at IS NULL OR checked_at < :cutoff)',
                ['mid' => $mid, 'status' => 'accepted', 'cutoff' => $cutoff]);
            if ($claimed === 0) {
                continue;
            }
            try {
                $this->apply($mid, (string) $row['channel']);
            } catch (DomainError | TransportFailure $e) {
                // 조회 실패는 다음 방문에 다시 시도한다. 결과를 잃지 않는다.
                continue;
            }
            $done++;
        }

        return $done;
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
     */
    private function refreshFallbacks(int $limit): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', Clock::timestamp() - self::RECHECK_SECONDS);
        $rows = $this->db->select(
            'SELECT r.smid AS smid FROM ' . $this->db->table('message_recipients') . ' r'
            . ' WHERE r.fallback_status = ? AND r.smid IS NOT NULL AND (r.checked_at IS NULL OR r.checked_at < ?)'
            . ' GROUP BY r.smid ORDER BY MIN(r.id) LIMIT ' . max(1, $limit),
            ['accepted', $cutoff]
        );

        $done = 0;
        foreach ($rows as $row) {
            $smid = (string) $row['smid'];
            $claimed = $this->db->update('message_recipients', ['checked_at' => Clock::now()],
                'smid = :smid AND fallback_status = :status AND (checked_at IS NULL OR checked_at < :cutoff)',
                ['smid' => $smid, 'status' => 'accepted', 'cutoff' => $cutoff]);
            if ($claimed === 0) {
                continue;
            }
            try {
                $this->applyFallback($smid);
            } catch (DomainError | TransportFailure $e) {
                // 이번 조회 실패도 다음 방문에 다시 시도한다.
                continue;
            }
            $done++;
        }

        return $done;
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
    }

    /** 문자 조회 응답 한 건이 성공인지 판단한다. 알림톡 대체문자와 순수 문자 발송이 함께 쓴다. */
    private static function smsSucceeded(array $item): bool
    {
        return str_contains((string) ($item['sms_state'] ?? ''), '성공');
    }

    /** 오래된 건은 조회를 멈춘다. 무한히 묻지 않는다. */
    private function giveUpOnStaleRows(): void
    {
        $limit = gmdate('Y-m-d H:i:s', Clock::timestamp() - self::GIVE_UP_DAYS * 86400);
        $this->db->update('message_recipients', ['status' => 'unknown'],
            'status = :status AND requested_at < :limit', ['status' => 'accepted', 'limit' => $limit]);
    }

    /**
     * 대체문자도 오래되면 그만 묻는다. 기준은 requested_at(원 발송 요청 시각)이 아니라
     * result_at(대체문자가 있었다는 사실을 알아낸 시각)이다 — 알림톡 결과 자체가 늦게
     * 확인되면 requested_at 은 이미 오래됐을 수 있지만, 대체문자를 기다리기 시작한 것은
     * 그 실패를 안 순간부터다.
     */
    private function giveUpOnStaleFallbacks(): void
    {
        $limit = gmdate('Y-m-d H:i:s', Clock::timestamp() - self::GIVE_UP_DAYS * 86400);
        $this->db->update('message_recipients', ['fallback_status' => 'unknown'],
            'fallback_status = :status AND result_at < :limit', ['status' => 'accepted', 'limit' => $limit]);
    }

    public function pendingCount(): int
    {
        return (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM '
            . $this->db->table('message_recipients') . ' WHERE status = ?', ['accepted'])['c'];
    }

    public function jobs(int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $total = (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM '
            . $this->db->table('message_jobs'))['c'];
        $items = $this->db->select('SELECT * FROM ' . $this->db->table('message_jobs')
            . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
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
