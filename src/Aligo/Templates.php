<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

/**
 * 알리고에서 승인 템플릿을 가져와 사본으로 보관한다. 여기서 템플릿을 만들거나 고치지 않는다.
 * 승인(APR)이고 정상(A)인 사본만 켤 수 있고, 가져오기에서 그 조건을 잃으면 자동으로 꺼진다.
 * 알리고 목록에 있었는데(=목록이 비어있지 않은데) 이 사본만 빠졌다면 마찬가지로 꺼진다 —
 * 더는 상태를 확인할 수 없는 템플릿으로 계속 발송할 수는 없다. 다만 내용은 지우지 않고
 * 마지막으로 확인한 값 그대로 남겨 이력·감사 목적에 쓴다. 반대로 목록 자체가 통째로 비어
 * 오면(네트워크·알리고 쪽 이상 응답일 수 있다) 아무것도 끄지 않는다 — 묵은 사본을 켜 둔
 * 채로 두는 대가는 발송 시점의 실패 한 건이지만, 잘못 껐다가는 알림톡 전체가 아무도
 * 모르게 조용히 멈춘다.
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

        // disabledTplCodes 는 이번 fetch() 에서 승인·정상을 잃거나 목록에서 사라져 실제로
        // 꺼진(=이전에 enabled=1 이었던) 사본의 코드만 담는다. AligoService::importTemplates()
        // 가 이 코드들로 걸린 예약을 찾아 취소한다 — Templates 는 Dispatch 를 모르므로
        // 여기서는 "무엇이 꺼졌는지"만 돌려주고 취소는 하지 않는다.
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
                    'tpl_code' => $code, 'enabled' => 0, 'fetched_at' => Clock::now(),
                ]);
                $counts['imported']++;
                continue;
            }

            // 승인·정상을 잃은 사본은 켜져 있었더라도 끈다.
            $disabling = (int) $existing['enabled'] === 1 && !$this->approved($status, $insp);
            if (!$disabling && !$this->changed($existing, $row)) {
                // 알리고 쪽 값이 그대로면 다시 쓰지 않는다 — 두 번째로 같은 목록을 가져와도
                // imported·updated·disabled 가 모두 0 이어야 한다.
                continue;
            }
            if ($disabling) {
                $row['enabled'] = 0;
                $counts['disabled']++;
                $counts['disabled_tpl_codes'][] = $code;
            }
            $row['fetched_at'] = Clock::now();
            $this->db->update('alimtalk_templates', $row, 'tpl_code = :code', ['code' => $code]);
            $counts['updated']++;
        }

        // 목록이 비어 있지 않은데 이번 목록에 없는 사본: 알리고에서 삭제되었거나 이
        // 발신프로필 소속이 아니게 된 것이다. 더는 승인 상태를 확인할 수 없으므로 켜져
        // 있었다면 끈다. 내용·상태는 마지막으로 확인한 값 그대로 남겨 새로 쓰지 않는다.
        // 목록 자체가 비어 왔을 때는 건드리지 않는다 — 맨 위 docblock 참고.
        if ($items !== []) {
            foreach ($this->usable() as $row) {
                $code = (string) $row['tpl_code'];
                if (isset($seen[$code])) {
                    continue;
                }
                $this->db->update('alimtalk_templates', ['enabled' => 0], 'tpl_code = :code', ['code' => $code]);
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
        return $this->db->select('SELECT * FROM ' . $this->db->table('alimtalk_templates')
            . ' WHERE enabled = 1 ORDER BY name, id');
    }

    public function find(string $tplCode): ?array
    {
        return $this->db->selectOne('SELECT * FROM ' . $this->db->table('alimtalk_templates')
            . ' WHERE tpl_code = ?', [$tplCode]);
    }

    public function setEnabled(string $tplCode, bool $on): void
    {
        $row = $this->find($tplCode);
        if ($row === null) {
            throw DomainError::validation(['tpl_code' => '먼저 템플릿을 가져와 주세요.']);
        }
        if ($on && !$this->approved((string) $row['status'], (string) $row['insp_status'])) {
            throw DomainError::validation(['tpl_code' =>
                '카카오 승인이 끝나고 정상 상태인 템플릿만 쓸 수 있습니다. 알리고에서 검수를 마친 뒤 다시 가져와 주세요.']);
        }
        $this->db->update('alimtalk_templates', ['enabled' => $on ? 1 : 0], 'tpl_code = :code', ['code' => $tplCode]);
    }

    private function approved(string $status, string $insp): bool
    {
        return $status === 'A' && $insp === 'APR';
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
