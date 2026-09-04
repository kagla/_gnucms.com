<?php

declare(strict_types=1);

namespace GnuCms\Spam;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

final class TurnstileSettingsRepository
{
    private const PREFIX = 'turnstile.';

    public function __construct(private Connection $db)
    {
    }

    public function all(): array
    {
        $settings = [];
        $sql = 'SELECT setting_key, setting_value FROM ' . $this->db->table('site_settings')
            . ' WHERE setting_key LIKE ?';
        foreach ($this->db->select($sql, [self::PREFIX . '%']) as $row) {
            $key = (string) $row['setting_key'];
            $settings[substr($key, strlen(self::PREFIX))] = (string) $row['setting_value'];
        }

        return $settings;
    }

    public function save(array $settings): void
    {
        $this->db->transaction(function () use ($settings): void {
            foreach ($settings as $key => $value) {
                $storedKey = self::PREFIX . $key;
                $changed = $this->db->update('site_settings', [
                    'setting_value' => (string) $value,
                    'updated_at' => Clock::now(),
                ], 'setting_key = :key', ['key' => $storedKey]);
                if ($changed === 0 && $this->db->selectOne(
                    'SELECT setting_key FROM ' . $this->db->table('site_settings') . ' WHERE setting_key = ?',
                    [$storedKey]
                ) === null) {
                    $this->db->insert('site_settings', [
                        'setting_key' => $storedKey,
                        'setting_value' => (string) $value,
                        'updated_at' => Clock::now(),
                    ]);
                }
            }
        });
    }
}
