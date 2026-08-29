<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

final class ConsentRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * 보여 준 동의 항목은 동의하지 않았어도 줄을 남긴다. 줄이 없으면 '안 했다' 인지
     * '묻지도 않았다' 인지 나중에 가릴 수 없다. 선택 동의는 이 구분이 특히 중요하다.
     */
    public function record(int $userId, string $type, array $content, bool $agreed = true): void
    {
        $row = $this->db->selectOne(
            'SELECT id FROM ' . $this->db->q('user_consents') . ' WHERE user_id = ? AND consent_type = ?',
            [$userId, $type]
        );
        $values = [
            'content_id' => (int) $content['id'],
            'content_updated_at' => (string) $content['updated_at'],
            'agreed' => $agreed ? 1 : 0,
            'agreed_at' => Clock::now(),
        ];
        if ($row === null) {
            $this->db->insert('user_consents', $values + ['user_id' => $userId, 'consent_type' => $type]);
            return;
        }
        $this->db->execute(
            'UPDATE ' . $this->db->q('user_consents')
            . ' SET content_id = ?, content_updated_at = ?, agreed = ?, agreed_at = ? WHERE id = ?',
            [$values['content_id'], $values['content_updated_at'], $values['agreed'],
             $values['agreed_at'], (int) $row['id']]
        );
    }

    public function forUser(int $userId): array
    {
        return $this->db->select(
            'SELECT * FROM ' . $this->db->q('user_consents') . ' WHERE user_id = ? ORDER BY id ASC',
            [$userId]
        );
    }
}
