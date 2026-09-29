<?php

declare(strict_types=1);

namespace GnuCms\Tests\Maintenance;

use GnuCms\Db\Connection;
use GnuCms\Db\Schema;
use GnuCms\Maintenance\BackupManager;
use GnuCms\Support\Clock;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Slim\Psr7\UploadedFile;

final class BackupManagerTest extends DatabaseTestCase
{
    private string $root;
    private array $config;
    private Connection $db;
    private BackupManager $manager;
    private string $configFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/' . GNUCMS_ID . '-full-backup-' . bin2hex(random_bytes(4));
        foreach (['uploads/2026/09', 'editor/content-key', 'avatars'] as $directory) {
            mkdir($this->root . '/' . $directory, 0775, true);
        }
        file_put_contents($this->root . '/uploads/2026/09/attachment', 'attachment-before');
        file_put_contents($this->root . '/editor/content-key/image.jpg', 'editor-before');
        file_put_contents($this->root . '/avatars/avatar.png', 'avatar-before');

        $this->config = [
            'db' => \GnuCms\Tests\Support\DatabaseTestCase::mysqlConfig(),
            'storage' => ['dir' => $this->root],
            'uploads' => ['dir' => $this->root . '/uploads'],
            'editor' => ['dir' => $this->root . '/editor'],
            'auth' => ['secret' => 'test-secret-that-must-stay-private'],
        ];
        $this->configFile = $this->root . '/config.php';
        file_put_contents($this->configFile, "<?php return ['auth' => ['secret' => 'original-secret']];\n");
        $this->db = Connection::create($this->config['db']);
        (new Schema($this->db))->drop();
        (new Schema($this->db))->create();
        $this->db->execute(
            'INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
            ['backup_test', 'before', '2026-09-04 00:00:00']
        );
        $this->manager = new BackupManager($this->db, $this->config, $this->root, $this->configFile);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
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

    public function testCreatesFullArchiveAndVerifiesEveryComponent(): void
    {
        $result = $this->manager->create();

        self::assertTrue($result['valid']);
        self::assertSame('mysql', $result['driver']);
        $extension = class_exists(\ZipArchive::class) ? 'zip' : 'tar';
        self::assertMatchesRegularExpression('/^gnucms-mysql-\d{8}-\d{6}\.' . $extension . '$/', $result['name']);
        $archive = $this->root . '/backups/manual/' . $result['name'];
        self::assertFileExists($archive);
        self::assertFileExists($archive . '.verified.json');

        $manifest = json_decode($this->archiveContents($archive, 'manifest.json'), true);
        self::assertSame(BackupManager::FORMAT, $manifest['format']);
        self::assertSame(BackupManager::FORMAT_VERSION, $manifest['format_version']);
        self::assertSame('database/mysql.sql', $manifest['database']['path']);
        self::assertSame('config/config.php', $manifest['config']['path']);
        self::assertArrayHasKey('files/uploads/2026/09/attachment', $manifest['files']);
        self::assertArrayHasKey('files/editor/content-key/image.jpg', $manifest['files']);
        self::assertArrayHasKey('files/avatars/avatar.png', $manifest['files']);
        self::assertStringContainsString(
            'original-secret',
            $this->archiveContents($archive, 'config/config.php')
        );

        $status = $this->manager->status();
        self::assertTrue($status['can_create']);
        self::assertFalse($status['can_restore']);
        self::assertSame($extension, $status['preferred_format']);
        self::assertNotNull($status['archives'][0]['verified_at']);
    }

    #[DataProvider('connectionProvider')]
    public function testBacksUpMysqlWithPrefixedTables(array $dbConfig): void
    {
        $dbConfig['prefix'] = 'backup_' . bin2hex(random_bytes(4)) . '_';
        $db = Connection::create($dbConfig);
        $schema = new Schema($db);
        $config = array_replace($this->config, ['db' => $dbConfig]);
        $manager = new BackupManager($db, $config, $this->root, $this->configFile);
        $status = $manager->status();
        if (!$status['can_create']) {
            self::markTestSkipped($status['unavailable_reason']);
        }

        try {
            $schema->create();
            $db->execute(
                'INSERT INTO ' . $db->table('site_settings') . ' (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
                ['backup_test', 'prefixed-backup-sentinel', '2026-09-04 00:00:00']
            );
            $result = $manager->create('manual', 'tar');
            self::assertTrue($result['valid']);
            self::assertSame('mysql', $result['driver']);
            $archive = $this->root . '/backups/manual/' . $result['name'];
            $manifest = json_decode($this->archiveContents($archive, 'manifest.json'), true);
            self::assertSame($dbConfig['prefix'], $manifest['database']['prefix']);
            $contents = $this->archiveContents($archive, $manifest['database']['path']);
            self::assertStringContainsString('prefixed-backup-sentinel', $contents);

            self::assertSame('sql', $manifest['database']['format']);
            self::assertSame('database/mysql.sql', $manifest['database']['path']);
            self::assertStringContainsString('CREATE TABLE `' . $db->tableName('site_settings') . '`', $contents);
            self::assertFalse($status['can_restore']);
            self::assertStringContainsString('mysql --host=', implode("\n", $status['instructions']));
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('자동 복원을 지원하지 않습니다');
            $manager->restore($result['name']);
        } finally {
            $schema->drop();
        }
    }

    public function testRejectsUnsupportedDatabaseInArchiveFilename(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('백업 파일 이름이 올바르지 않습니다');
        $this->manager->downloadPath('gnucms-unsupported-20260904-000000.tar');
    }

    public function testRejectsUnsupportedDatabaseInManifest(): void
    {
        $archive = $this->archiveWithUnsupportedDatabase();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('지원하는 GNUCMS 전체 백업 형식이 아닙니다');
        $this->manager->verify($archive);
    }

    public function testRejectsUnsupportedDatabaseInRenamedUploadAndRemovesTemporaryFile(): void
    {
        $archive = $this->archiveWithUnsupportedDatabase();
        $upload = new UploadedFile($archive, 'backup.tar', 'application/x-tar', filesize($archive) ?: null);

        try {
            $this->manager->storeUpload($upload);
            self::fail('지원하지 않는 DB 백업 업로드가 허용되었습니다.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('지원하는 GNUCMS 전체 백업 형식이 아닙니다', $e->getMessage());
            self::assertSame([], glob($this->root . '/backups/manual/.uploading-*'));
            self::assertSame([], glob($this->root . '/backups/manual/*.tar'));
        }
    }

    public function testRejectsAFileThatIsNotAGnuCmsArchive(): void
    {
        $extension = class_exists(\ZipArchive::class) ? 'zip' : 'tar';
        $name = 'gnucms-mysql-20260904-000000.' . $extension;
        mkdir($this->root . '/backups/manual', 0775, true);
        file_put_contents($this->root . '/backups/manual/' . $name, 'not an archive');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GNUCMS 백업 형식을 읽을 수 없습니다');
        $this->manager->verify($name);
    }

    public function testExtensionDataAndEnabledStateRestoreWithoutExecutionPermits(): void
    {
        $schema = new \GnuCms\Extension\PackageSchema($this->db, $this->root);
        $schema->install('plugins/backup-test', 1, ['ext_saved'], static function (Connection $db): void {
            $db->execute('CREATE TABLE ' . $db->table('ext_saved') . ' (id INTEGER PRIMARY KEY, message TEXT NOT NULL)');
            $db->execute('INSERT INTO ' . $db->table('ext_saved') . ' (id, message) VALUES (1, ?)', ['before']);
        });
        $state = new \GnuCms\Extension\StateStore($this->root . '/extensions');
        $state->update(static fn (): array => ['plugins/backup-test']);
        $permit = new \GnuCms\Extension\RuntimePermit($this->root);
        $permit->set('plugins/backup-test', 'revision-1');
        $saved = $this->manager->create();
        $archive = $this->root . '/backups/manual/' . $saved['name'];
        $manifest = json_decode($this->archiveContents($archive, 'manifest.json'), true);
        self::assertArrayHasKey('files/extensions/enabled.json', $manifest['files']);
        self::assertArrayNotHasKey('files/extensions/state.lock', $manifest['files']);
        foreach (array_keys($manifest['files']) as $file) self::assertStringNotContainsString('permits', $file);
        self::assertStringContainsString('ext_saved', $this->archiveContents($archive, 'database/mysql.sql'));
        $this->db->execute('DROP TABLE ext_saved');
    }

    public function testRejectsAnArchiveWhoseContentsNoLongerMatchTheManifest(): void
    {
        $result = $this->manager->create();
        $path = $this->root . '/backups/manual/' . $result['name'];
        if (str_ends_with($path, '.zip')) {
            $archive = new \ZipArchive();
            self::assertTrue($archive->open($path));
            self::assertTrue($archive->addFromString('files/uploads/2026/09/attachment', 'tampered'));
            self::assertTrue($archive->close());
        } else {
            $archive = new \PharData($path);
            $archive->addFromString('files/uploads/2026/09/attachment', 'tampered');
            unset($archive);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('체크섬이 일치하지 않습니다');
        $this->manager->verify($result['name']);
    }

    public function testStillVerifiesAndRestoresTarBackupsWhenZipIsPreferred(): void
    {
        if (!class_exists(\PharData::class)) {
            self::markTestSkipped('PHP phar 확장이 필요합니다.');
        }
        $created = $this->manager->create('manual', 'tar');
        $name = $created['name'];

        $verified = $this->manager->verify($name);
        self::assertTrue($verified['valid']);
        self::assertStringEndsWith('.tar', $verified['name']);

    }

    public function testCreatesZipWhenItIsExplicitlySelected(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('PHP zip 확장이 필요합니다.');
        }

        $created = $this->manager->create('manual', 'zip');

        self::assertTrue($created['valid']);
        self::assertStringEndsWith('.zip', $created['name']);
    }

    public function testUsesSiteTimezoneForFilenameAndManifestDate(): void
    {
        Clock::freeze('2026-09-04 16:30:45');
        $manager = new BackupManager(
            $this->db,
            $this->config,
            $this->root,
            $this->configFile,
            'Asia/Seoul'
        );

        $created = $manager->create('manual', 'tar');
        $archive = $this->root . '/backups/manual/' . $created['name'];
        $manifest = json_decode($this->archiveContents($archive, 'manifest.json'), true);

        self::assertSame('gnucms-mysql-20260905-013045.tar', $created['name']);
        self::assertSame('2026-09-05T01:30:45+09:00', $manifest['created_at']);
    }

    public function testRejectsAnUnknownArchiveFormat(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zip 또는 tar');
        $this->manager->create('manual', 'rar');
    }

    public function testDeletesStoredArchiveAndItsVerificationRecord(): void
    {
        $created = $this->manager->create();
        $path = $this->root . '/backups/manual/' . $created['name'];
        self::assertFileExists($path);
        self::assertFileExists($path . '.verified.json');

        $deleted = $this->manager->delete($created['name']);

        self::assertSame($created['name'], $deleted['deleted']);
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($path . '.verified.json');
        self::assertSame([], $this->manager->status()['archives']);
    }

    public function testRenamedUploadedBackupGetsAUniqueCanonicalNameWithoutOverwriting(): void
    {
        $created = $this->manager->create('manual', 'tar');
        $archive = $this->root . '/backups/manual/' . $created['name'];
        $duplicate = $this->root . '/duplicate.tar';
        copy($archive, $duplicate);
        $originalHash = hash_file('sha256', $archive);

        $uploaded = $this->manager->storeUpload(new UploadedFile(
            $duplicate,
            '내가 바꾼 백업 이름 (1).tar',
            'application/x-tar',
            filesize($duplicate) ?: null
        ));

        self::assertSame($originalHash, hash_file('sha256', $archive));
        self::assertFileExists($archive . '.verified.json');
        self::assertNotSame($created['name'], $uploaded['name']);
        self::assertMatchesRegularExpression('/-2\.tar$/', $uploaded['name']);
        self::assertFileExists($this->root . '/backups/manual/' . $uploaded['name']);
        self::assertFileExists($this->root . '/backups/manual/' . $uploaded['name'] . '.verified.json');
    }

    public function testDeleteOnlyAcceptsAStoredBackupName(): void
    {
        $created = $this->manager->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('삭제할 백업 파일 이름이 올바르지 않습니다');
        $this->manager->delete('../' . $created['name']);
    }

    public function testRejectsAnUploadRootThatWouldRecursivelyIncludeBackups(): void
    {
        $config = $this->config;
        $config['uploads']['dir'] = $this->root;
        $manager = new BackupManager($this->db, $config, $this->root, $this->configFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('안전하지 않은 업로드 원본 경로');
        $manager->create();
    }

    private function archiveWithUnsupportedDatabase(): string
    {
        $created = $this->manager->create('manual', 'tar');
        $archive = $this->root . '/backups/manual/' . $created['name'];
        $manifest = json_decode($this->archiveContents($archive, 'manifest.json'), true);
        $manifest['database']['driver'] = 'unsupported';
        $tar = new \PharData($archive);
        $tar->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        unset($tar);

        return $archive;
    }

    private function archiveContents(string $archive, string $entry): string
    {
        if (str_ends_with($archive, '.zip')) {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($archive));
            $contents = $zip->getFromName($entry);
            self::assertIsString($contents);
            self::assertTrue($zip->close());

            return $contents;
        }

        $contents = file_get_contents('phar://' . $archive . '/' . $entry);
        self::assertIsString($contents);

        return $contents;
    }

}
