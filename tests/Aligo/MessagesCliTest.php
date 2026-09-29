<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Db\Connection;
use GnuCms\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * bin/messages.php 는 선택 사항인 CLI 다(관리자가 이력 화면을 열 때도 갱신되므로
 * cron 이 없어도 동작한다). 그래도 이 테스트는 실제 알리고를 부르지 않고, 무엇보다
 * config/config.php(운영 gnucms.charmgen.com DB를 가리킨다)를 절대 건드리지 않는다 —
 * 매 테스트가 시스템 임시 디렉터리에 전용 테스트 DB를 초기화하고 설정 파일을 만들고
 * bin/messages.php의 마지막 인자로 그 설정 파일 경로를 넘긴다(migrate.php와 같은
 * 관례). 새로 만든 DB에는 발송 이력이 하나도 없으므로 refresh() 는 갱신할 mid 를
 * 찾지 못해 알리고에 조회 요청을 보내지 않고 0건으로 끝난다.
 */
final class MessagesCliTest extends TestCase
{
    private string $root;
    private string $configFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/' . GNUCMS_ID . '-messages-cli-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
        $this->configFile = $this->root . '/config.php';
        $config = [
            'db' => \GnuCms\Tests\Support\DatabaseTestCase::mysqlConfig(),
            'auth' => ['secret' => 'cli-test-secret-that-is-long-enough'],
        ];
        file_put_contents($this->configFile,
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");

        $db = Connection::create($config['db']);
        (new Schema($db))->drop();
        (new Schema($db))->create();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        foreach (glob($this->root . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root);
    }

    public function testRefreshCommandRunsAndReports(): void
    {
        [$status, $output] = $this->runCommand('refresh 2 ' . escapeshellarg($this->configFile));

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('갱신', $output);
    }

    public function testUnknownCommandExplainsUsage(): void
    {
        [$status, $output] = $this->runCommand('nope');

        self::assertSame(1, $status);
        self::assertStringContainsString('refresh', $output);
    }

    /** @return array{int,string} */
    private function runCommand(string $arguments): array
    {
        $script = dirname(__DIR__, 2) . '/bin/messages.php';
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $arguments . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }
}
