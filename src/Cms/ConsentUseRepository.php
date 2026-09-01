<?php

declare(strict_types=1);

namespace GnuCms\Cms;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

/**
 * 약관을 어느 자리에 붙였는지 다룬다. 같은 약관이 회원가입에선 필수, 신청 폼에선
 * 선택일 수 있으므로 필수·선택과 차례는 약관이 아니라 이 붙임이 갖는다.
 */
final class ConsentUseRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * 한 자리에 붙은 약관을 차례대로. 내용 칸과 붙임 칸을 합쳐 준다.
     *
     * 붙임의 차례는 use_sort_order 로 따로 준다. 두 표가 같은 이름의 칸을 가져서,
     * sort_order 를 그냥 실으면 어느 쪽 값인지 SQL 판마다 달라진다.
     * is_consent 를 한 번 더 보는 것은 표시를 뗀 뒤 남은 붙임이 있어도
     * 가입 화면에는 절대 나오지 않게 하려는 빗장이다.
     */
    public function listForScope(string $scope, bool $publishedOnly = false): array
    {
        $sql = 'SELECT c.*, u.required, u.sort_order AS use_sort_order, u.scope'
            . ' FROM ' . $this->db->table('consent_uses') . ' u'
            . ' JOIN ' . $this->db->table('contents') . ' c ON c.id = u.content_id'
            . ' WHERE u.scope = ? AND c.deleted_at IS NULL AND c.is_consent = 1';
        if ($publishedOnly) {
            $sql .= " AND c.status = 'published'";
        }

        return $this->db->select($sql . ' ORDER BY u.sort_order ASC, c.id ASC', [$scope]);
    }

    /** 이 약관이 붙어 있는 자리들. 약관 관리 목록이 쓴다. */
    public function listForContent(int $contentId): array
    {
        return $this->db->select(
            'SELECT * FROM ' . $this->db->table('consent_uses')
            . ' WHERE content_id = ? ORDER BY scope ASC',
            [$contentId]
        );
    }

    /** 붙인다. 이미 같은 자리에 붙어 있으면 규칙만 덮어쓴다. */
    public function attach(string $scope, int $contentId, bool $required, int $sortOrder): void
    {
        $row = $this->db->selectOne(
            'SELECT id FROM ' . $this->db->table('consent_uses') . ' WHERE scope = ? AND content_id = ?',
            [$scope, $contentId]
        );
        if ($row === null) {
            $this->db->insert('consent_uses', [
                'scope' => $scope,
                'content_id' => $contentId,
                'required' => $required ? 1 : 0,
                'sort_order' => $sortOrder,
                'created_at' => Clock::now(),
            ]);
            return;
        }
        $this->db->execute(
            'UPDATE ' . $this->db->table('consent_uses') . ' SET required = ?, sort_order = ? WHERE id = ?',
            [$required ? 1 : 0, $sortOrder, (int) $row['id']]
        );
    }

    public function detach(string $scope, int $contentId): void
    {
        $this->db->delete('consent_uses', 'scope = :scope AND content_id = :content_id',
            ['scope' => $scope, 'content_id' => $contentId]);
    }

    /** 약관 표시를 뗄 때 붙임을 모두 걷는다. */
    public function detachContent(int $contentId): void
    {
        $this->db->delete('consent_uses', 'content_id = :content_id', ['content_id' => $contentId]);
    }
}
