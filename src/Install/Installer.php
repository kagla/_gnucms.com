<?php

declare(strict_types=1);

namespace ApiBoard\Install;

use ApiBoard\Db\Connection;
use ApiBoard\Db\DialectFactory;
use ApiBoard\Db\Schema;
use ApiBoard\Error\DomainError;
use ApiBoard\Support\Base64Url;
use ApiBoard\Validation\Validator;
use Throwable;

final class Installer
{
    public const ADMIN_PASSWORD_MIN = 8;

    /** @var string */
    private $configPath;

    /** @var string */
    private $storageDir;

    public function __construct(string $configPath, string $storageDir)
    {
        $this->configPath = $configPath;
        $this->storageDir = rtrim($storageDir, '/');
    }

    public function isInstalled(): bool
    {
        return is_file($this->configPath);
    }

    /** @return array{dialect: string, config_path: string} */
    public function run(array $input): array
    {
        if ($this->isInstalled()) {
            throw DomainError::forbidden('이미 설치되어 있습니다. 다시 설치하려면 config/config.php 를 지우세요.');
        }

        $v = new Validator($input);
        $dsn = $v->requiredString('dsn', 500);
        $adminId = $v->requiredString('admin_id', 64);
        $adminPassword = (string) ($input['admin_password'] ?? '');
        if (mb_strlen($adminPassword) < self::ADMIN_PASSWORD_MIN) {
            $v->fail('admin_password', self::ADMIN_PASSWORD_MIN . '자 이상이어야 합니다.');
        }
        $v->check();

        if (strpos($dsn, ':') === false) {
            throw DomainError::validation(['dsn' => 'DSN 형식이 올바르지 않습니다.']);
        }

        try {
            DialectFactory::fromDsn($dsn);
        } catch (DomainError $e) {
            throw DomainError::validation(['dsn' => $e->getMessage()]);
        }

        $dbConfig = [
            'dsn'      => $dsn,
            'username' => ((string) ($input['db_username'] ?? '')) ?: null,
            'password' => ((string) ($input['db_password'] ?? '')) ?: null,
        ];

        try {
            $db = Connection::create($dbConfig);
            (new Schema($db))->create();
        } catch (Throwable $e) {
            throw DomainError::validation(['dsn' => 'DB 에 연결하거나 테이블을 만들지 못했습니다: ' . $e->getMessage()]);
        }

        $this->ensureStorageDirectories();

        $config = [
            'db'   => $dbConfig,
            'auth' => [
                'secret' => Base64Url::encode(random_bytes(32)),
                'ttl'    => 3600,
                'leeway' => 60,
            ],
            'bootstrap_admin' => [
                'id'            => $adminId,
                'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
            ],
            'uploads' => [
                'dir'         => $this->storageDir . '/uploads',
                'max_bytes'   => 5 * 1024 * 1024,
                'allowed_ext' => [
                    'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip', 'txt',
                    'hwp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                ],
            ],
            'cors' => [
                'allowed_origins' => $this->parseOrigins((string) ($input['cors_origins'] ?? '')),
            ],
            'log' => [
                'file' => $this->storageDir . '/logs/error.log',
            ],
            'debug' => false,
        ];

        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";

        if (file_put_contents($this->configPath, $php, LOCK_EX) === false) {
            throw DomainError::internal('설정 파일을 쓰지 못했습니다: ' . $this->configPath);
        }
        @chmod($this->configPath, 0640);

        return [
            'dialect'     => $db->dialect()->name(),
            'config_path' => $this->configPath,
        ];
    }

    /** @return string[] */
    private function parseOrigins(string $raw): array
    {
        $origins = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '' && !in_array($line, $origins, true)) {
                $origins[] = $line;
            }
        }

        return $origins;
    }

    private function ensureStorageDirectories(): void
    {
        foreach ([$this->storageDir . '/uploads', $this->storageDir . '/logs'] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw DomainError::internal('디렉터리를 만들 수 없습니다: ' . $directory);
            }
        }
    }
}
