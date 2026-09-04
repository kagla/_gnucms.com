<?php

declare(strict_types=1);

namespace GnuCms\Tests\Maintenance;

use GnuCms\Db\Connection;
use GnuCms\Db\Schema;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class BackupCliTest extends TestCase
{
    private string $root;
    private string $configFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/' . GNUCMS_ID . '-backup-cli-' . bin2hex(random_bytes(4));
        foreach (['uploads', 'editor', 'avatars'] as $directory) {
            mkdir($this->root . '/' . $directory, 0775, true);
        }
        $this->configFile = $this->root . '/config.php';
        $config = [
            'db' => ['dsn' => 'sqlite:' . $this->root . '/board.sqlite'],
            'storage' => ['dir' => $this->root],
            'uploads' => ['dir' => $this->root . '/uploads'],
            'editor' => ['dir' => $this->root . '/editor'],
            'auth' => ['secret' => 'cli-test-secret-that-is-long-enough'],
        ];
        file_put_contents($this->configFile,
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
        $db = Connection::create($config['db']);
        (new Schema($db))->create();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    public function testCliCreatesListsVerifiesAndRequiresConfirmationForRestore(): void
    {
        $extension = class_exists(\ZipArchive::class) ? 'zip' : 'tar';
        [$status, $created] = $this->runCommand('create --format=' . $extension);
        self::assertSame(0, $status, $created);
        self::assertMatchesRegularExpression('/파일: (gnucms-sqlite-\d{8}-\d{6}\.' . $extension . ')/', $created);
        preg_match('/파일: (gnucms-sqlite-\d{8}-\d{6}\.' . $extension . ')/', $created, $match);
        $name = $match[1];

        [$status, $listed] = $this->runCommand('list');
        self::assertSame(0, $status, $listed);
        self::assertStringContainsString($name, $listed);

        mkdir($this->root . '/recovered');
        $external = $this->root . '/recovered/' . $name;
        copy($this->root . '/backups/manual/' . $name, $external);
        [$status, $verified] = $this->runCommand('verify ' . escapeshellarg($external));
        self::assertSame(0, $status, $verified);
        self::assertStringContainsString('DB: sqlite', $verified);

        [$status, $notConfirmed] = $this->runCommand('restore ' . escapeshellarg($name));
        self::assertSame(2, $status, $notConfirmed);
        self::assertStringContainsString('--yes', $notConfirmed);

        [$status, $restored] = $this->runCommand('restore ' . escapeshellarg($external) . ' --yes');
        self::assertSame(0, $status, $restored);
        self::assertStringContainsString('복원 직전 안전 백업:', $restored);

        [$status, $notDeleted] = $this->runCommand('delete ' . escapeshellarg($name));
        self::assertSame(2, $status, $notDeleted);
        self::assertStringContainsString('--yes', $notDeleted);

        [$status, $deleted] = $this->runCommand('delete ' . escapeshellarg($name) . ' --yes');
        self::assertSame(0, $status, $deleted);
        self::assertStringContainsString('전체 백업을 삭제했습니다', $deleted);
        self::assertFileDoesNotExist($this->root . '/backups/manual/' . $name);
        self::assertFileDoesNotExist($this->root . '/backups/manual/' . $name . '.verified.json');
    }

    public function testCliCanExplicitlyCreateTarBackup(): void
    {
        if (!class_exists(\PharData::class)) {
            self::markTestSkipped('PHP phar 확장이 필요합니다.');
        }

        [$status, $created] = $this->runCommand('create --format=tar');

        self::assertSame(0, $status, $created);
        self::assertMatchesRegularExpression('/파일: gnucms-sqlite-\d{8}-\d{6}\.tar/', $created);
    }

    /** @return array{int,string} */
    private function runCommand(string $arguments): array
    {
        $script = dirname(__DIR__, 2) . '/bin/backup.php';
        $output = [];
        $status = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $arguments
                . ' --config=' . escapeshellarg($this->configFile) . ' 2>&1',
            $output,
            $status
        );

        return [$status, implode("\n", $output)];
    }
}
