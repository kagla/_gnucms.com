<?php

declare(strict_types=1);

namespace StandardBoard\Tests;

use PHPUnit\Framework\TestCase;
use StandardBoard\Config;
use StandardBoard\Http\ApiError;

final class ConfigTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/standard-board-config-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config/config.php');
        @unlink($this->dir . '/.env');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);

        foreach (['DEBUG', 'AUTH_SECRET', 'DB_DSN'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key], $_SERVER['HTTP_' . $key]);
        }
    }

    public function testDefaultsApplyWhenNothingIsConfigured(): void
    {
        $config = $this->load();

        $this->assertSame('', $config['db']['dsn']);
        $this->assertSame(3600, $config['auth']['ttl']);
        $this->assertNull($config['bootstrap_admin']);
        $this->assertSame([], $config['cors']['allowed_origins']);
        $this->assertFalse($config['debug']);
        $this->assertSame($this->dir . '/storage/uploads', $config['uploads']['dir']);
    }

    public function testConfigFileOverridesDefaults(): void
    {
        $this->writeConfig(['auth' => ['ttl' => 60], 'debug' => true]);

        $config = $this->load();

        $this->assertSame(60, $config['auth']['ttl']);
        $this->assertTrue($config['debug']);
        // 같은 절의 건드리지 않은 값은 기본값이 남는다.
        $this->assertSame(60, $config['auth']['leeway']);
    }

    public function testEnvFileOverridesConfigFile(): void
    {
        $this->writeConfig(['db' => ['dsn' => 'sqlite:/from/config.sqlite'], 'debug' => false]);
        $this->writeEnv("DB_DSN=sqlite:/from/env.sqlite\nDEBUG=true\n");

        $config = $this->load();

        $this->assertSame('sqlite:/from/env.sqlite', $config['db']['dsn']);
        $this->assertTrue($config['debug']);
    }

    public function testRealEnvironmentVariableOverridesEnvFile(): void
    {
        $this->writeEnv("AUTH_SECRET=from-env-file\n");
        putenv('AUTH_SECRET=from-process');

        $this->assertSame('from-process', $this->load()['auth']['secret']);
    }

    public function testRequestHeadersCannotInjectConfiguration(): void
    {
        // 요청 헤더는 언제나 HTTP_ 접두사가 붙어 $_SERVER 에 들어온다.
        // 정확한 이름만 찾으므로 설정을 밀어 넣을 수 없다.
        $this->writeEnv("DEBUG=false\n");
        $_SERVER['HTTP_DEBUG'] = 'true';

        $this->assertFalse($this->load()['debug']);
    }

    public function testWorksWithEnvFileAloneWhenConfigFileIsMissing(): void
    {
        $this->writeEnv("DB_DSN=sqlite:/only/env.sqlite\nAUTH_SECRET=abc\n");

        $config = $this->load();

        $this->assertSame('sqlite:/only/env.sqlite', $config['db']['dsn']);
        $this->assertSame('abc', $config['auth']['secret']);
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $this->writeEnv("WHATEVER=1\nDEBUG=true\n");

        $config = $this->load();

        $this->assertTrue($config['debug']);
        $this->assertArrayNotHasKey('WHATEVER', $config);
    }

    public function testIntegersAndBooleansAreCast(): void
    {
        $this->writeEnv("AUTH_TTL=900\nUPLOADS_MAX_BYTES=1048576\nDEBUG=on\n");

        $config = $this->load();

        $this->assertSame(900, $config['auth']['ttl']);
        $this->assertSame(1048576, $config['uploads']['max_bytes']);
        $this->assertTrue($config['debug']);
    }

    public function testCommaSeparatedListsBecomeArrays(): void
    {
        $this->writeEnv("CORS_ALLOWED_ORIGINS=https://a.example.com, https://b.example.com ,,https://a.example.com\n");

        $this->assertSame(
            ['https://a.example.com', 'https://b.example.com'],
            $this->load()['cors']['allowed_origins']
        );
    }

    public function testListReplacesTheDefaultInsteadOfMerging(): void
    {
        $this->writeEnv("UPLOADS_ALLOWED_EXT=txt,pdf\n");

        $this->assertSame(['txt', 'pdf'], $this->load()['uploads']['allowed_ext']);
    }

    public function testConfigFileListReplacesTheDefaultInsteadOfMerging(): void
    {
        $this->writeConfig(['uploads' => ['allowed_ext' => ['txt']]]);

        $this->assertSame(['txt'], $this->load()['uploads']['allowed_ext']);
    }

    public function testEmptyValueMeansUnsetAndKeepsTheLowerLayer(): void
    {
        // .env.example 을 복사해 일부만 채우는 것이 정상적인 사용법이다.
        // 채우지 않은 줄이 아래 층을 지워 버리면 안 된다.
        $this->writeConfig([
            'db'   => ['dsn' => 'sqlite:/from/config.sqlite', 'username' => 'someone'],
            'auth' => ['secret' => 'from-config'],
        ]);
        $this->writeEnv("DB_DSN=\nDB_USERNAME=\nAUTH_SECRET=\nCORS_ALLOWED_ORIGINS=\n");

        $config = $this->load();

        $this->assertSame('sqlite:/from/config.sqlite', $config['db']['dsn']);
        $this->assertSame('someone', $config['db']['username']);
        $this->assertSame('from-config', $config['auth']['secret']);
    }

    public function testCopyingTheShippedExampleOverridesNothing(): void
    {
        // 배포물의 .env.example 을 그대로 .env 로 복사해도 설정이 그대로여야 한다.
        $this->writeConfig([
            'db'    => ['dsn' => 'sqlite:/from/config.sqlite'],
            'auth'  => ['secret' => 'from-config', 'ttl' => 111],
            'debug' => true,
        ]);
        copy(__DIR__ . '/../.env.example', $this->dir . '/.env');

        $config = $this->load();

        $this->assertSame('sqlite:/from/config.sqlite', $config['db']['dsn']);
        $this->assertSame('from-config', $config['auth']['secret']);
        $this->assertSame(111, $config['auth']['ttl']);
        $this->assertTrue($config['debug']);
    }

    public function testLiteralNullClearsAnOptionalValue(): void
    {
        // 빈 값이 "설정하지 않음" 이 되었으므로 지우는 방법이 따로 필요하다.
        $this->writeConfig(['db' => ['username' => 'someone', 'password' => 'secret']]);
        $this->writeEnv("DB_USERNAME=null\nDB_PASSWORD=NULL\n");

        $config = $this->load();

        $this->assertNull($config['db']['username']);
        $this->assertNull($config['db']['password']);
    }

    public function testBootstrapAdminCanBeConfiguredFromEnv(): void
    {
        $this->writeEnv("BOOTSTRAP_ADMIN_ID=root\nBOOTSTRAP_ADMIN_PASSWORD_HASH=\$2y\$10\$abc\n");

        $config = $this->load();

        $this->assertSame('root', $config['bootstrap_admin']['id']);
        $this->assertSame('$2y$10$abc', $config['bootstrap_admin']['password_hash']);
    }

    public function testBootstrapAdminCanBeClosedFromEnv(): void
    {
        $this->writeConfig(['bootstrap_admin' => ['id' => 'root', 'password_hash' => 'x']]);
        $this->writeEnv("BOOTSTRAP_ADMIN_ENABLED=false\n");

        $this->assertNull($this->load()['bootstrap_admin']);
    }

    public function testUnparsableBooleanIsReported(): void
    {
        $this->writeEnv("DEBUG=ture\n");

        try {
            $this->load();
            $this->fail('오류가 나야 한다');
        } catch (ApiError $e) {
            $this->assertStringContainsString('DEBUG', $e->getMessage());
        }
    }

    public function testUnparsableIntegerIsReported(): void
    {
        $this->writeEnv("AUTH_TTL=한시간\n");

        $this->expectException(ApiError::class);
        $this->load();
    }

    private function load(): array
    {
        return Config::load($this->dir . '/config/config.php', $this->dir . '/.env', $this->dir);
    }

    private function writeConfig(array $config): void
    {
        file_put_contents(
            $this->dir . '/config/config.php',
            "<?php\n\nreturn " . var_export($config, true) . ";\n"
        );
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->dir . '/.env', $contents);
    }
}
