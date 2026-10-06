<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

/**
 * 알리고 템플릿을 읽기 전용 사본으로 보관한다. 별도의 사용 스위치는 두지 않는다.
 * 현재 발신프로필의 카카오 승인(APR)·정상(A) 또는 대기(R) 템플릿을 바로 선택한다.
 * 목록에서 사라진 사본은 내용·검수 결과를 보존하고 상태 M(목록 없음)으로 표시한다.
 * 빈 목록 응답은 일시적인 조회 이상일 수 있어 기존 사본을 일괄 무효화하지 않는다.
 */
final class Templates
{
    private Connection $db;
    private AlimtalkApi $api;
    private Settings $settings;

    public function __construct(Connection $db, AlimtalkApi $api, Settings $settings)
    {
        $this->db = $db;
        $this->api = $api;
        $this->settings = $settings;
    }

    public function fetch(): array
    {
        $account = $this->settings->runtime();
        if ($account === null || $account['senderkey'] === '') {
            throw DomainError::validation(['senderkey' => '발신프로필키를 먼저 저장해 주세요.']);
        }

        $items = $this->api->templates($account['senderkey']);

        // 발송 가능 상태를 잃은 코드로 기존 예약 취소를 시도한다.
        $counts = ['imported' => 0, 'updated' => 0, 'disabled' => 0, 'disabled_tpl_codes' => []];
        $seen = [];
        foreach ($items as $item) {
            $code = (string) ($item['templtCode'] ?? '');
            if ($code === '') {
                continue;
            }
            $seen[$code] = true;
            $status = (string) ($item['status'] ?? '');
            $insp = (string) ($item['inspStatus'] ?? '');
            $row = [
                'senderkey' => $account['senderkey'],
                'name' => (string) ($item['templtName'] ?? ''),
                'content' => (string) ($item['templtContent'] ?? ''),
                'template_type' => (string) ($item['templateType'] ?? ''),
                'emphasis_type' => (string) ($item['templateEmType'] ?? ''),
                'status' => $status,
                'insp_status' => $insp,
                'buttons' => (string) json_encode($item['buttons'] ?? [], JSON_UNESCAPED_UNICODE),
            ];

            $existing = $this->find($code);
            if ($existing === null) {
                $this->db->insert('alimtalk_templates', $row + [
                    'tpl_code' => $code, 'enabled' => self::approved($status, $insp) ? 1 : 0, 'fetched_at' => Clock::now(),
                ]);
                $counts['imported']++;
                continue;
            }

            $disabling = $this->canUse($existing) && !self::approved($status, $insp);
            // enabled는 이전 버전 호환을 위한 상태 사본이며 사용자가 켜거나 끄지 않는다.
            $row['enabled'] = self::approved($status, $insp) ? 1 : 0;
            if (!$this->changed($existing, $row)) {
                continue;
            }
            if ($disabling) {
                $counts['disabled']++;
                $counts['disabled_tpl_codes'][] = $code;
            }
            $row['fetched_at'] = Clock::now();
            $this->db->update('alimtalk_templates', $row, 'tpl_code = :code', ['code' => $code]);
            $counts['updated']++;
        }

        if ($items !== []) {
            foreach ($this->usable() as $row) {
                $code = (string) $row['tpl_code'];
                if (isset($seen[$code])) {
                    continue;
                }
                $this->db->update('alimtalk_templates', ['status' => 'M', 'enabled' => 0],
                    'tpl_code = :code', ['code' => $code]);
                $counts['disabled']++;
                $counts['disabled_tpl_codes'][] = $code;
            }
        }

        return $counts;
    }

    public function all(): array
    {
        return $this->db->select('SELECT * FROM ' . $this->db->table('alimtalk_templates') . ' ORDER BY name, id');
    }

    public function usable(): array
    {
        $senderkey = $this->settings->formValues()['senderkey'];
        if ($senderkey === '') return [];
        return $this->db->select('SELECT * FROM ' . $this->db->table('alimtalk_templates')
            . " WHERE senderkey = ? AND insp_status = 'APR' AND status IN ('A', 'R') ORDER BY name, id", [$senderkey]);
    }

    public function find(string $tplCode): ?array
    {
        return $this->db->selectOne('SELECT * FROM ' . $this->db->table('alimtalk_templates')
            . ' WHERE tpl_code = ?', [$tplCode]);
    }

    /** 현재 저장된 발신프로필의 발송 가능한 승인 사본인가. */
    public function canUse(array $row): bool
    {
        $senderkey = $this->settings->formValues()['senderkey'];
        return $senderkey !== '' && (string) $row['senderkey'] === $senderkey
            && self::approved((string) $row['status'], (string) $row['insp_status']);
    }

    public static function approved(string $status, string $insp): bool
    {
        return $insp === 'APR' && in_array($status, ['A', 'R'], true);
    }

    /** senderkey·name·content·template_type·emphasis_type·status·insp_status·buttons 중 하나라도 다르면 참. */
    private function changed(array $existing, array $row): bool
    {
        foreach ($row as $column => $value) {
            if ((string) ($existing[$column] ?? '') !== (string) $value) {
                return true;
            }
        }

        return false;
    }
}
