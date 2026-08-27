<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Install;

use PHPUnit\Framework\TestCase;
use ApiBoard\Db\Connection;
use ApiBoard\Db\Schema;
use ApiBoard\Error\DomainError;
use ApiBoard\Install\Installer;

final class InstallerTest extends TestCase
{
    /** @var string */
    private $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/apiboard-install-' . bin2hex(random_bytes(4));
        mkdir($this->workDir . '/config', 0775, true);
        mkdir($this->workDir . '/storage', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (['config/config.php', 'storage/board.sqlite'] as $file) {
            @unlink($this->workDir . '/' . $file);
        }
        @rmdir($this->workDir . '/config');
        @rmdir($this->workDir . '/storage/uploads');
        @rmdir($this->workDir . '/storage/logs');
        @rmdir($this->workDir . '/storage');
        @rmdir($this->workDir);
    }

    public function testNotInstalledInitially(): void
    {
        $this->assertFalse($this->installer()->isInstalled());
    }

    public function testRunCreatesSchemaAndConfig(): void
    {
        $result = $this->installer()->run($this->input());

        $this->assertSame('sqlite', $result['dialect']);
        $this->assertFileExists($this->configPath());
        $this->assertTrue($this->installer()->isInstalled());

        $config = require $this->configPath();
        $db = Connection::create($config['db']);
        $this->assertTrue((new Schema($db))->exists());
    }

    public function testGeneratedConfigHasStrongSecretAndHashedPassword(): void
    {
        $this->installer()->run($this->input());
        $config = require $this->configPath();

        $this->assertGreaterThanOrEqual(43, strlen($config['auth']['secret']));
        $this->assertTrue(password_verify('supersecret1', $config['bootstrap_admin']['password_hash']));
        $this->assertSame('root', $config['bootstrap_admin']['id']);
        $this->assertFalse($config['debug']);
    }

    public function testCorsOriginsAreParsedLineByLine(): void
    {
        $this->installer()->run($this->input([
            'cors_origins' => "https://a.example.com\n  \nhttps://b.example.com  ",
        ]));
        $config = require $this->configPath();

        $this->assertSame(['https://a.example.com', 'https://b.example.com'], $config['cors']['allowed_origins']);
    }

    public function testSecondRunIsRefused(): void
    {
        $this->installer()->run($this->input());

        try {
            $this->installer()->run($this->input());
            $this->fail('두 번째 설치는 거부되어야 한다');
        } catch (DomainError $e) {
            $this->assertSame(403, $e->status());
        }
    }

    public function testShortAdminPasswordIsRejected(): void
    {
        try {
            $this->installer()->run($this->input(['admin_password' => 'short']));
            $this->fail('422 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertSame(422, $e->status());
            $this->assertArrayHasKey('admin_password', $e->details());
        }
    }

    public function testUnsupportedDriverIsRejected(): void
    {
        try {
            $this->installer()->run($this->input(['dsn' => 'oracle:host=localhost']));
            $this->fail('422 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertSame(422, $e->status());
            $this->assertArrayHasKey('dsn', $e->details());
        }
    }

    public function testUnreachableDatabaseIsReportedOnTheDsnField(): void
    {
        try {
            $this->installer()->run($this->input([
                'dsn' => 'mysql:host=127.0.0.1;port=1;dbname=nope',
            ]));
            $this->fail('422 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertSame(422, $e->status());
            $this->assertArrayHasKey('dsn', $e->details());
        }
    }

    private function installer(): Installer
    {
        return new Installer($this->configPath(), $this->workDir . '/storage');
    }

    private function configPath(): string
    {
        return $this->workDir . '/config/config.php';
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'dsn'            => 'sqlite:' . $this->workDir . '/storage/board.sqlite',
            'db_username'    => '',
            'db_password'    => '',
            'admin_id'       => 'root',
            'admin_password' => 'supersecret1',
            'cors_origins'   => '',
        ], $overrides);
    }
}
