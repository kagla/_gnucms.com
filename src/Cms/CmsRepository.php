<?php

declare(strict_types=1);

namespace ApiBoard\Cms;

use ApiBoard\Db\Connection;
use ApiBoard\Support\Clock;

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
        foreach ($this->db->select('SELECT setting_key, setting_value FROM ' . $this->db->q('site_settings')) as $row) {
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
                    'SELECT setting_key FROM ' . $this->db->q('site_settings') . ' WHERE setting_key = ?',
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

    public function listPages(): array
    {
        return array_map([$this, 'hydratePage'], $this->db->select(
            'SELECT * FROM ' . $this->db->q('pages') . ' WHERE deleted_at IS NULL ORDER BY sort_order ASC, id ASC'
        ));
    }

    public function listDeletedPages(): array
    {
        return array_map([$this, 'hydratePage'], $this->db->select(
            'SELECT * FROM ' . $this->db->q('pages') . ' WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, id DESC'
        ));
    }

    public function publishedMenuPages(): array
    {
        return array_map([$this, 'hydratePage'], $this->db->select(
            'SELECT * FROM ' . $this->db->q('pages')
            . " WHERE status = 'published' AND show_in_menu = 1 AND deleted_at IS NULL"
            . ' ORDER BY sort_order ASC, id ASC'
        ));
    }

    public function findPageById(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM ' . $this->db->q('pages') . ' WHERE id = ? AND deleted_at IS NULL', [$id]
        );
        return $row === null ? null : $this->hydratePage($row);
    }

    public function findDeletedPageById(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM ' . $this->db->q('pages') . ' WHERE id = ? AND deleted_at IS NOT NULL', [$id]
        );
        return $row === null ? null : $this->hydratePage($row);
    }

    public function findPublishedBySlug(string $slug): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM ' . $this->db->q('pages')
            . " WHERE slug = ? AND status = 'published' AND deleted_at IS NULL",
            [$slug]
        );
        return $row === null ? null : $this->hydratePage($row);
    }

    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->q('pages') . ' WHERE slug = ?', [$slug]);
        return $row === null ? null : $this->hydratePage($row);
    }

    public function createPage(array $data): int
    {
        $now = Clock::now();
        return (int) $this->db->insert('pages', array_merge($data, [
            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => $data['status'] === 'published' ? $now : null,
        ]));
    }

    public function updatePage(int $id, array $data, ?string $publishedAt): void
    {
        $data['updated_at'] = Clock::now();
        $data['published_at'] = $data['status'] === 'published' ? ($publishedAt ?? Clock::now()) : null;
        $this->db->update('pages', $data, 'id = :id', ['id' => $id]);
    }

    public function deletePage(int $id): void
    {
        $this->db->update('pages', [
            'status' => 'draft', 'show_in_menu' => 0, 'published_at' => null,
            'deleted_at' => Clock::now(), 'updated_at' => Clock::now(),
        ], 'id = :id', ['id' => $id]);
    }

    public function restorePage(int $id): void
    {
        $this->db->update('pages', [
            'deleted_at' => null, 'updated_at' => Clock::now(),
        ], 'id = :id', ['id' => $id]);
    }

    public function permanentlyDeletePage(int $id): void
    {
        $this->db->delete('pages', 'id = :id AND deleted_at IS NOT NULL', ['id' => $id]);
    }

    public function countPages(): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->q('pages') . ' WHERE deleted_at IS NULL'
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
