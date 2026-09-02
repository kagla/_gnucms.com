<?php

declare(strict_types=1);

namespace GnuCms\Cms;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

final class CmsRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function settings(): array
    {
        $settings = [];
        foreach ($this->db->select('SELECT setting_key, setting_value FROM ' . $this->db->table('site_settings')
            . " WHERE setting_key NOT LIKE 'mail.%' AND setting_key NOT LIKE 'oauth.%'"
            . " AND setting_key NOT LIKE 'system.%'") as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $settings;
    }

    public function saveSettings(array $settings): void
    {
        $this->db->transaction(function () use ($settings): void {
            foreach ($settings as $key => $value) {
                $changed = $this->db->update('site_settings', [
                    'setting_value' => (string) $value,
                    'updated_at' => Clock::now(),
                ], 'setting_key = :key', ['key' => $key]);
                if ($changed === 0 && $this->db->selectOne(
                    'SELECT setting_key FROM ' . $this->db->table('site_settings') . ' WHERE setting_key = ?',
                    [$key]
                ) === null) {
                    $this->db->insert('site_settings', [
                        'setting_key' => $key,
                        'setting_value' => (string) $value,
                        'updated_at' => Clock::now(),
                    ]);
                }
            }
        });
    }

    /** @param bool|null $consentOnly null 이면 전부, true 면 약관만, false 면 약관 말고 */
    public function listPages(?bool $consentOnly = null): array
    {
        $sql = 'SELECT * FROM ' . $this->db->table('contents') . ' WHERE deleted_at IS NULL';
        if ($consentOnly === true) {
            $sql .= ' AND is_consent = 1';
        } elseif ($consentOnly === false) {
            $sql .= ' AND is_consent = 0';
        }

        return array_map([$this, 'hydratePage'], $this->db->select(
            $sql . ' ORDER BY sort_order ASC, id ASC'
        ));
    }

    public function listDeletedPages(): array
    {
        return array_map([$this, 'hydratePage'], $this->db->select(
            'SELECT * FROM ' . $this->db->table('contents') . ' WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, id DESC'
        ));
    }

    public function publishedMenuPages(): array
    {
        return array_map([$this, 'hydratePage'], $this->db->select(
            'SELECT * FROM ' . $this->db->table('contents')
            // 약관에서 show_in_menu 는 '하단에 표시' 라는 뜻이라 상단 메뉴에서는 뺀다.
            . " WHERE status = 'published' AND show_in_menu = 1 AND is_consent = 0 AND deleted_at IS NULL"
            . ' ORDER BY sort_order ASC, id ASC'
        ));
    }

    /** 사이트맵과 공개 내용 RSS에 쓰는 모든 공개 내용·약관. 메뉴 표시 여부와는 무관하다. */
    public function listPublishedPages(): array
    {
        return array_map([$this, 'hydratePage'], $this->db->select(
            'SELECT * FROM ' . $this->db->table('contents')
            . " WHERE status = 'published' AND deleted_at IS NULL"
            . ' ORDER BY updated_at DESC, id DESC'
        ));
    }

    /**
     * 사이트 하단에 늘어놓을 공개 약관. 로그인 없이도 보이므로 ACL 을 타지 않는다.
     * 약관에서 show_in_menu 는 '하단에 표시' 토글이다. 끄면 주소로만 열 수 있다.
     */
    public function listPublishedConsentPages(): array
    {
        return $this->db->select(
            'SELECT * FROM ' . $this->db->table('contents')
            . " WHERE is_consent = 1 AND status = 'published' AND show_in_menu = 1 AND deleted_at IS NULL"
            . ' ORDER BY sort_order ASC, id ASC'
        );
    }

    public function findPageById(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM ' . $this->db->table('contents') . ' WHERE id = ? AND deleted_at IS NULL', [$id]
        );
        return $row === null ? null : $this->hydratePage($row);
    }

    public function findDeletedPageById(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM ' . $this->db->table('contents') . ' WHERE id = ? AND deleted_at IS NOT NULL', [$id]
        );
        return $row === null ? null : $this->hydratePage($row);
    }

    public function findPublishedBySlug(string $slug): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM ' . $this->db->table('contents')
            . " WHERE slug = ? AND status = 'published' AND deleted_at IS NULL",
            [$slug]
        );
        return $row === null ? null : $this->hydratePage($row);
    }

    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('contents') . ' WHERE slug = ?', [$slug]);
        return $row === null ? null : $this->hydratePage($row);
    }

    public function createPage(array $data): int
    {
        $now = Clock::now();
        return (int) $this->db->insert('contents', array_merge($data, [
            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => $data['status'] === 'published' ? $now : null,
        ]));
    }

    public function updatePage(int $id, array $data, ?string $publishedAt): void
    {
        $data['updated_at'] = Clock::now();
        $data['published_at'] = $data['status'] === 'published' ? ($publishedAt ?? Clock::now()) : null;
        $this->db->update('contents', $data, 'id = :id', ['id' => $id]);
    }

    /** 약관 표시만 켠다. 씨앗 붙이기가 옛 판에서 손수 만든 페이지를 만났을 때 쓴다. */
    public function markConsent(int $id): void
    {
        $this->db->execute(
            'UPDATE ' . $this->db->table('contents') . ' SET is_consent = 1 WHERE id = ?',
            [$id]
        );
    }

    public function deletePage(int $id): void
    {
        $this->db->update('contents', [
            'status' => 'draft', 'show_in_menu' => 0, 'published_at' => null,
            'deleted_at' => Clock::now(), 'updated_at' => Clock::now(),
        ], 'id = :id', ['id' => $id]);
    }

    public function restorePage(int $id): void
    {
        $this->db->update('contents', [
            'deleted_at' => null, 'updated_at' => Clock::now(),
        ], 'id = :id', ['id' => $id]);
    }

    public function permanentlyDeletePage(int $id): void
    {
        $this->db->delete('contents', 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]);
    }

    public function countPages(): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->table('contents') . ' WHERE deleted_at IS NULL'
        );
        return (int) ($row['c'] ?? 0);
    }

    private function hydratePage(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['show_in_menu'] = (bool) $row['show_in_menu'];
        $row['sort_order'] = (int) $row['sort_order'];
        return $row;
    }
}
