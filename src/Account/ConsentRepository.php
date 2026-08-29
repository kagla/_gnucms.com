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

    /**
     * 관리 화면에 보여 줄 동의 내역. 그때 본 문서가 무엇이었는지, 그 뒤로 문서가
     * 바뀌었는지까지 같이 읽는다. 문서가 지워졌으면 제목 칸이 비어 온다.
     */
    public function forUserWithDocument(int $userId): array
    {
        return $this->db->select(
            'SELECT uc.consent_type, uc.agreed, uc.agreed_at, uc.content_updated_at,'
            . ' c.title AS content_title, c.slug AS content_slug,'
            . ' c.updated_at AS content_current_updated_at'
            . ' FROM ' . $this->db->q('user_consents') . ' uc'
            . ' LEFT JOIN ' . $this->db->q('contents') . ' c ON c.id = uc.content_id'
            . ' WHERE uc.user_id = ? ORDER BY c.consent_order ASC, uc.id ASC',
            [$userId]
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
