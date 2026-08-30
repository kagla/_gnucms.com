<?php

declare(strict_types=1);

namespace GnuCms\Tests\Install;

use GnuCms\Db\Connection;
use GnuCms\Db\Schema;
use GnuCms\Error\DomainError;
use GnuCms\Install\Installer;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/' . GNUCMS_ID . '-install-' . bin2hex(random_bytes(4));
        mkdir($this->workDir . '/config', 0775, true);
        mkdir($this->workDir . '/storage', 0775, true);
        mkdir($this->workDir . '/public', 0775, true);
        file_put_contents($this->workDir . '/public/install.php', '<?php // 설치기');
    }

    protected function tearDown(): void
    {
        @chmod($this->workDir . '/config', 0775);
        foreach (['config/config.php', 'storage/board.sqlite', 'public/install.php'] as $file) {
            @unlink($this->workDir . '/' . $file);
        }
        foreach (['config', 'storage/uploads', 'storage/editor', 'storage/logs', 'storage', 'public', ''] as $dir) {
            @rmdir(rtrim($this->workDir . '/' . $dir, '/'));
        }
    }

    public function testNotInstalledInitially(): void
    {
        self::assertFalse($this->installer()->isInstalled());
    }

    public function testFinishCreatesSchemaAdminConfigAndDeletesItself(): void
    {
        $result = $this->installer()->finish($this->dbConfig(), $this->site(), $this->admin());

        self::assertSame('sqlite', $result['dialect']);
        self::assertSame('owner@example.com', $result['admin_email']);
        self::assertTrue($result['self_deleted']);
        self::assertFileDoesNotExist($this->workDir . '/public/install.php');
        self::assertTrue($this->installer()->isInstalled());

        $config = require $this->configPath();
        $db = Connection::create($config['db']);
        self::assertTrue((new Schema($db))->exists());
        self::assertSame('내 커뮤니티', $db->selectOne("SELECT setting_value FROM site_settings WHERE setting_key = 'site_name'")['setting_value']);

        $user = $db->selectOne('SELECT * FROM users WHERE email = ?', ['owner@example.com']);
        self::assertSame(1, (int) $user['is_admin']);
        self::assertSame(1, (int) $user['email_verified']);
        self::assertSame('사이트지기', $user['display_name']);
        self::assertTrue(password_verify('secret-pass-123', (string) $user['password_hash']));
        self::assertSame('1', $db->selectOne("SELECT state_value FROM site_state WHERE state_key = 'first_admin_claimed'")['state_value']);
    }

    public function testGeneratedConfigHasOnlyLiveKeys(): void
    {
        $this->installer()->finish($this->dbConfig(), $this->site(), $this->admin());
        $config = require $this->configPath();

        self::assertGreaterThanOrEqual(43, strlen($config['auth']['secret']));
        self::assertSame(['secret'], array_keys($config['auth']));
        self::assertArrayNotHasKey('cors', $config);
        self::assertArrayNotHasKey('bootstrap_admin', $config);
        self::assertSame('https://community.example.com', $config['app']['url']);
        self::assertSame('no-reply@example.com', $config['mail']['from']);
        self::assertSame($this->workDir . '/storage/editor', $config['editor']['dir']);
        self::assertDirectoryExists($config['editor']['dir']);
        self::assertFalse($config['debug']);
        self::assertSame('0640', substr(sprintf('%o', fileperms($this->configPath())), -4));
    }

    public function testReuseKeepsExistingTablesAndSkipsAdmin(): void
    {
        $db = Connection::create($this->dbConfig());
        (new Schema($db))->create();
        $db->execute("UPDATE site_settings SET setting_value = '9.old' WHERE setting_key = 'schema_version'");
        $db->insert('boards', [
            'board_key' => 'free', 'name' => '자유', 'description' => '', 'list_type' => 'list', 'perm_read' => 'all',
            'perm_write' => 'member', 'perm_comment' => 'member', 'managers' => '[]', 'sort_order' => 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $result = $this->installer()->finish($this->dbConfig(), $this->site(), null, true);

        self::assertNull($result['admin_email']);
        self::assertSame(1, (int) $db->selectOne('SELECT COUNT(*) AS c FROM boards')['c']);
        self::assertSame((new Schema($db))->stamp(), (new Schema($db))->storedStamp());
        self::assertSame(0, (int) $db->selectOne('SELECT COUNT(*) AS c FROM users')['c']);
    }

    public function testSecondFinishIsRefused(): void
    {
        $this->installer()->finish($this->dbConfig(), $this->site(), $this->admin());

        try {
            $this->installer()->finish($this->dbConfig(), $this->site(), $this->admin());
            self::fail('두 번째 설치는 거부되어야 한다');
        } catch (DomainError $e) {
            self::assertSame(403, $e->status());
        }
    }

    public function testUnreachableDatabaseIsReported(): void
    {
        try {
            $this->installer()->finish(['dsn' => 'mysql:host=127.0.0.1;port=1;dbname=nope', 'username' => 'x', 'password' => 'y'], $this->site(), $this->admin());
            self::fail('422 가 나와야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertArrayHasKey('_', $e->details());
        }
        self::assertFileDoesNotExist($this->configPath());
    }

    public function testFailedConfigWriteLeavesNoAdminBehind(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root 로 실행하면 chmod 로 쓰기를 막을 수 없다.');
        }

        chmod($this->workDir . '/config', 0555);

        try {
            $this->installer()->finish($this->dbConfig(), $this->site(), $this->admin());
            self::fail('config.php 를 못 쓰면 500 이 나와야 한다');
        } catch (DomainError $e) {
            self::assertSame(500, $e->status());
        }

        self::assertFileDoesNotExist($this->configPath());
        $db = Connection::create($this->dbConfig());
        self::assertSame(0, (int) $db->selectOne('SELECT COUNT(*) AS c FROM users')['c']);
        self::assertSame('0', $db->selectOne("SELECT state_value FROM site_state WHERE state_key = 'first_admin_claimed'")['state_value']);

        chmod($this->workDir . '/config', 0775);
        $result = $this->installer()->finish($this->dbConfig(), $this->site(), $this->admin());
        self::assertSame('owner@example.com', $result['admin_email']);
    }

    public function testWithoutInstallScriptSelfDeletedIsNull(): void
    {
        $installer = new Installer($this->configPath(), $this->workDir . '/storage');

        $result = $installer->finish($this->dbConfig(), $this->site(), $this->admin());

        self::assertNull($result['self_deleted']);
        self::assertFileExists($this->workDir . '/public/install.php');
    }

    public function testSiteFromValidatesUrlAndMail(): void
    {
        self::assertSame(
            ['site_name' => '내 커뮤니티', 'app_url' => 'https://community.example.com', 'mail_from' => 'no-reply@example.com'],
            Installer::siteFrom(['site_name' => '내 커뮤니티', 'app_url' => 'https://community.example.com/', 'mail_from' => 'No-Reply@example.com'])
        );

        try {
            Installer::siteFrom(['site_name' => '', 'app_url' => 'javascript:alert(1)', 'mail_from' => "bad\naddress"]);
            self::fail('422 가 나와야 한다');
        } catch (DomainError $e) {
            self::assertSame(['site_name', 'app_url', 'mail_from'], array_keys($e->details()));
        }
    }

    public function testAdminFromValidatesLikeRegistration(): void
    {
        self::assertSame(
            ['email' => 'owner@example.com', 'display_name' => '사이트지기', 'password' => 'secret-pass-123'],
            Installer::adminFrom($this->admin() + ['password_confirmation' => 'secret-pass-123'])
        );

        try {
            Installer::adminFrom(['email' => 'nope', 'display_name' => 'a b', 'password' => 'short', 'password_confirmation' => 'other']);
            self::fail('422 가 나와야 한다');
        } catch (DomainError $e) {
            self::assertSame(['email', 'display_name', 'password', 'password_confirmation'], array_keys($e->details()));
        }

        try {
            Installer::adminFrom(['email' => 'a@example.com', 'display_name' => '김', 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123']);
            self::fail('짧은 표시 이름은 거부되어야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('display_name', $e->details());
        }
    }

    private function installer(): Installer
    {
        return new Installer($this->configPath(), $this->workDir . '/storage', $this->workDir . '/public/install.php');
    }

    private function configPath(): string
    {
        return $this->workDir . '/config/config.php';
    }

    /** @return array{dsn: string, username: ?string, password: ?string} */
    private function dbConfig(): array
    {
        return ['dsn' => 'sqlite:' . $this->workDir . '/storage/board.sqlite', 'username' => null, 'password' => null];
    }

    private function site(): array
    {
        return ['site_name' => '내 커뮤니티', 'app_url' => 'https://community.example.com', 'mail_from' => 'no-reply@example.com'];
    }

    private function admin(): array
    {
        return ['email' => 'owner@example.com', 'display_name' => '사이트지기', 'password' => 'secret-pass-123'];
    }
}
