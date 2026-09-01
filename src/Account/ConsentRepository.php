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
     *
     * @param string $subjectType user | submission
     * @param array  $content     contents 한 줄
     */
    public function record(
        string $subjectType,
        int $subjectId,
        string $scope,
        array $content,
        bool $agreed = true,
        ?ConsentTrace $trace = null
    ): void {
        $contentId = (int) $content['id'];
        $row = $this->db->selectOne(
            'SELECT id FROM ' . $this->db->table('consents_given')
            . ' WHERE subject_type = ? AND subject_id = ? AND scope = ? AND content_id = ?',
            [$subjectType, $subjectId, $scope, $contentId]
        );
        $values = [
            'consent_type' => (string) $content['slug'],
            'content_updated_at' => (string) $content['updated_at'],
            'agreed' => $agreed ? 1 : 0,
            'agreed_at' => Clock::now(),
            'agreed_ip' => $trace === null ? null : $trace->ip,
            'agreed_ua' => $trace === null ? null : $trace->userAgent,
        ];
        if ($row === null) {
            $this->db->insert('consents_given', $values + [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'scope' => $scope,
                'content_id' => $contentId,
            ]);
            return;
        }
        $this->db->execute(
            'UPDATE ' . $this->db->table('consents_given')
            . ' SET consent_type = ?, content_updated_at = ?, agreed = ?, agreed_at = ?,'
            . ' agreed_ip = ?, agreed_ua = ? WHERE id = ?',
            [$values['consent_type'], $values['content_updated_at'], $values['agreed'],
             $values['agreed_at'], $values['agreed_ip'], $values['agreed_ua'], (int) $row['id']]
        );
    }

    public function forSubject(string $subjectType, int $subjectId): array
    {
        return $this->db->select(
            'SELECT * FROM ' . $this->db->table('consents_given')
            . ' WHERE subject_type = ? AND subject_id = ? ORDER BY id ASC',
            [$subjectType, $subjectId]
        );
    }

    /**
     * 관리 화면에 보여 줄 동의 내역. 그때 본 문서가 무엇이었는지, 그 뒤로 문서가
     * 바뀌었는지까지 같이 읽는다. 문서가 지워졌으면 제목 칸이 비어 온다.
     */
    public function forSubjectWithDocument(string $subjectType, int $subjectId): array
    {
        return $this->db->select(
            'SELECT g.consent_type, g.scope, g.agreed, g.agreed_at, g.content_updated_at,'
            . ' g.agreed_ip, c.title AS content_title, c.slug AS content_slug,'
            . ' c.updated_at AS content_current_updated_at'
            . ' FROM ' . $this->db->table('consents_given') . ' g'
            . ' LEFT JOIN ' . $this->db->table('contents') . ' c ON c.id = g.content_id'
            . ' WHERE g.subject_type = ? AND g.subject_id = ? ORDER BY g.id ASC',
            [$subjectType, $subjectId]
        );
    }

    /** 한 약관에 누가 동의했는지. 동의 현황 화면이 쓴다. */
    public function forContent(int $contentId): array
    {
        return $this->db->select(
            'SELECT g.*, u.email AS user_email, u.display_name AS user_display_name'
            . ' FROM ' . $this->db->table('consents_given') . ' g'
            . ' LEFT JOIN ' . $this->db->table('users') . " u"
            . "   ON g.subject_type = 'user' AND u.id = g.subject_id"
            . ' WHERE g.content_id = ? ORDER BY g.id DESC',
            [$contentId]
        );
    }

    /** @return array{agreed:int,declined:int} */
    public function countsForContent(int $contentId): array
    {
        $rows = $this->db->select(
            'SELECT agreed, COUNT(*) AS c FROM ' . $this->db->table('consents_given')
            . ' WHERE content_id = ? GROUP BY agreed',
            [$contentId]
        );
        $counts = ['agreed' => 0, 'declined' => 0];
        foreach ($rows as $row) {
            $counts[((int) $row['agreed']) === 1 ? 'agreed' : 'declined'] = (int) $row['c'];
        }
        return $counts;
    }

    public function forUser(int $userId): array
    {
        return $this->forSubject('user', $userId);
    }

    public function forUserWithDocument(int $userId): array
    {
        return $this->forSubjectWithDocument('user', $userId);
    }
}
