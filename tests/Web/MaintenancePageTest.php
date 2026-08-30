<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Db\MaintenanceRequired;
use GnuCms\Web\MaintenancePage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MaintenancePageTest extends TestCase
{
    public function testBusyPageAsksToReload(): void
    {
        $html = MaintenancePage::html(new MaintenanceRequired(MaintenanceRequired::BUSY));

        self::assertStringContainsString('옮기는 중입니다', $html);
        self::assertStringContainsString('새로고침', $html);
        self::assertStringNotContainsString('error.log', $html);
    }

    public function testFailedPageNamesLogAndBackupButHidesCause(): void
    {
        $e = new MaintenanceRequired(
            MaintenanceRequired::FAILED,
            '/srv/site/storage/backups/board-v9-20260830-010203.sqlite',
            new RuntimeException('SQLSTATE[HY000] secret-detail')
        );

        $html = MaintenancePage::html($e);

        self::assertStringContainsString('옮기지 못했습니다', $html);
        self::assertStringContainsString('storage/logs/error.log', $html);
        self::assertStringContainsString('board-v9-20260830-010203.sqlite', $html);
        self::assertStringNotContainsString('secret-detail', $html);
    }

    public function testFailedPageWithoutBackupOmitsBackupLine(): void
    {
        $html = MaintenancePage::html(new MaintenanceRequired(MaintenanceRequired::FAILED));

        self::assertStringNotContainsString('백업', $html);
    }
}
