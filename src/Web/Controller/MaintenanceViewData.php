<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Service\AttachmentService;

/** 유지보수 화면의 서로 독립된 두 목록을 같은 기준으로 나눈다. */
final class MaintenanceViewData
{
    private const BACKUP_PAGE_SIZE = 20;
    private const GARBAGE_PAGE_SIZE = 50;

    public static function backupPageFor(App $app, string $name): int
    {
        foreach ($app->backups()->status()['archives'] as $index => $archive) {
            if ($archive['name'] === $name) {
                return intdiv($index, self::BACKUP_PAGE_SIZE) + 1;
            }
        }
        return 1;
    }

    public static function build(App $app, array $query, ?string $backupError = null): array
    {
        $acl = $app->guestAcl();
        $acl->assertGlobalAdmin();

        $backup = $app->backups()->status();
        $archiveCount = count($backup['archives']);
        $backupPages = max(1, (int) ceil($archiveCount / self::BACKUP_PAGE_SIZE));
        $backupPage = min(self::page($query['backup_page'] ?? null), $backupPages);
        $backup['archives'] = array_slice($backup['archives'],
            ($backupPage - 1) * self::BACKUP_PAGE_SIZE, self::BACKUP_PAGE_SIZE);

        $garbage = $app->attachments()->garbageCandidates($acl,
            self::page($query['garbage_page'] ?? null), self::GARBAGE_PAGE_SIZE);

        return [
            'query' => $query,
            'schema' => $app->schemaUpgrader()->status(),
            'backup' => $backup,
            'backup_pagination' => [
                'page' => $backupPage, 'total_pages' => $backupPages,
                'total_items' => $archiveCount, 'per_page' => self::BACKUP_PAGE_SIZE,
            ],
            'garbage' => $garbage,
            'garbage_pagination' => [
                'page' => $garbage['page'], 'total_pages' => $garbage['total_pages'],
                'total_items' => $garbage['total_items'], 'per_page' => self::GARBAGE_PAGE_SIZE,
            ],
            'backup_upload_max_mb' => AttachmentService::serverMaxMb(),
            'backup_error' => $backupError,
        ];
    }

    /** 배열·음수·지나치게 큰 쪽 번호는 받지 않는다. */
    private static function page(mixed $value): int
    {
        if (!is_scalar($value) || !ctype_digit((string) $value)) {
            return 1;
        }
        return max(1, min(1000000, (int) $value));
    }
}
