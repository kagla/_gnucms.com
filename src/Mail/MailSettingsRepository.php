<?php

declare(strict_types=1);

namespace ApiBoard\Mail;

use ApiBoard\Db\Connection;
use ApiBoard\Support\Clock;

final class MailSettingsRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function all(): array
    {
        $settings = [];
        foreach ($this->db->select('SELECT setting_key, setting_value FROM '
            . $this->db->q('mail_settings')) as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $settings;
    }

    public function save(array $settings): void
    {
        $this->db->transaction(function () use ($settings): void {
            foreach ($settings as $key => $value) {
                $changed = $this->db->update('mail_settings', [
                    'setting_value' => (string) $value,
                    'updated_at' => Clock::now(),
                ], 'setting_key = :key', ['key' => $key]);
                if ($changed === 0 && $this->db->selectOne(
                    'SELECT setting_key FROM ' . $this->db->q('mail_settings') . ' WHERE setting_key = ?',
                    [$key]
                ) === null) {
                    $this->db->insert('mail_settings', [
                        'setting_key' => $key,
                        'setting_value' => (string) $value,
                        'updated_at' => Clock::now(),
                    ]);
                }
            }
        });
    }
}
