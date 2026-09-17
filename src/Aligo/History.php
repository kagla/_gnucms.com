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
                $state = (string) ($item['sms_state'] ?? '');
                $ok = str_contains($state, '성공');
                $rslt = $ok ? 'S' : 'F';
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

    /** 오래된 건은 조회를 멈춘다. 무한히 묻지 않는다. */
    private function giveUpOnStaleRows(): void
    {
        $limit = gmdate('Y-m-d H:i:s', Clock::timestamp() - self::GIVE_UP_DAYS * 86400);
        $this->db->update('message_recipients', ['status' => 'unknown'],
            'status = :status AND requested_at < :limit', ['status' => 'accepted', 'limit' => $limit]);
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
