<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

/**
 * 알리고에서 승인 템플릿을 가져와 사본으로 보관한다. 여기서 템플릿을 만들거나 고치지 않는다.
 * 승인(APR)이고 정상(A)인 사본만 켤 수 있고, 가져오기에서 그 조건을 잃으면 자동으로 꺼진다.
 * 알리고 목록에서 사본이 통째로 사라져도 마찬가지로 꺼진다 — 더는 상태를 확인할 수 없는
 * 템플릿으로 계속 발송할 수는 없다. 다만 내용은 지우지 않고 마지막으로 확인한 값 그대로
 * 남겨 이력·감사 목적에 쓴다.
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

        $counts = ['imported' => 0, 'updated' => 0, 'disabled' => 0];
        $seen = [];
        foreach ($this->api->templates($account['senderkey']) as $item) {
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
                'fetched_at' => Clock::now(),
            ];

            $existing = $this->find($code);
            if ($existing === null) {
                $this->db->insert('alimtalk_templates', $row + ['tpl_code' => $code, 'enabled' => 0]);
                $counts['imported']++;
                continue;
            }

            // 승인·정상을 잃은 사본은 켜져 있었더라도 끈다.
            if ((int) $existing['enabled'] === 1 && !$this->approved($status, $insp)) {
                $row['enabled'] = 0;
                $counts['disabled']++;
            }
            $this->db->update('alimtalk_templates', $row, 'tpl_code = :code', ['code' => $code]);
            $counts['updated']++;
        }

        // 이번 목록에 없는 사본: 알리고에서 삭제되었거나 이 발신프로필 소속이 아니게 된
        // 것이다. 더는 승인 상태를 확인할 수 없으므로 켜져 있었다면 끈다. 내용·상태는
        // 마지막으로 확인한 값 그대로 남겨 새로 쓰지 않는다 — 이력을 지울 이유가 없다.
        foreach ($this->usable() as $row) {
            $code = (string) $row['tpl_code'];
            if (isset($seen[$code])) {
                continue;
            }
            $this->db->update('alimtalk_templates', ['enabled' => 0], 'tpl_code = :code', ['code' => $code]);
            $counts['disabled']++;
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
}
