<?php

declare(strict_types=1);

namespace GnuCms\Install;

use GnuCms\Account\UserRepository;
use GnuCms\Db\Connection;
use GnuCms\Db\Schema;
use GnuCms\Error\DomainError;
use GnuCms\Support\Base64Url;
use GnuCms\Support\Clock;
use GnuCms\Validation\Validator;
use Throwable;

/**
 * 설치 3·4단계의 검증과 5단계의 마무리.
 * 순서: 표 → 사이트 이름 → 관리자 → config.php → install.php 삭제.
 * config.php 를 맨 마지막에 쓰므로 중간에 실패해도 "반쯤 설치된" 상태가 남지 않는다.
 */
final class Installer
{
    private string $configPath;
    private string $storageDir;
    /** @var string|null install.php 경로. null 이면 스스로 지우지 않는다 */
    private ?string $installScript;

    public function __construct(string $configPath, string $storageDir, ?string $installScript = null)
    {
        $this->configPath = $configPath;
        $this->storageDir = rtrim($storageDir, '/');
        $this->installScript = $installScript;
    }

    public function isInstalled(): bool
    {
        return is_file($this->configPath);
    }

    /** @return array{site_name: string, app_url: string, mail_from: string} */
    public static function siteFrom(array $input): array
    {
        $v = new Validator($input);
        $siteName = trim($v->requiredString('site_name', 50));
        $appUrl = rtrim($v->requiredString('app_url', 500), '/');
        $mailFrom = strtolower($v->requiredString('mail_from', 254));
        if ($appUrl !== '' && filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
            $v->fail('app_url', '올바른 http 또는 https 주소를 입력해 주세요.');
        } elseif ($appUrl !== '' && !in_array((string) parse_url($appUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $v->fail('app_url', '사이트 주소는 http 또는 https로 시작해야 합니다.');
        }
        if ($mailFrom !== '' && filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false) {
            $v->fail('mail_from', '올바른 이메일 주소를 입력해 주세요.');
        }
        $v->check();

        return ['site_name' => $siteName, 'app_url' => $appUrl, 'mail_from' => $mailFrom];
    }

    /** 회원가입과 같은 규칙. @return array{email: string, display_name: string, password: string} */
    public static function adminFrom(array $input): array
    {
        $v = new Validator($input);
        $email = strtolower(trim($v->requiredString('email', 254)));
        $name = trim($v->requiredString('display_name', 100));
        $password = $v->requiredPassword('password');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $v->fail('email', '올바른 이메일 주소를 입력해 주세요.');
        }
        if ($name !== '' && UserRepository::displayNameHasBadChars($name)) {
            $v->fail('display_name', '표시 이름은 한글·영문·숫자만 쓸 수 있습니다.');
        } elseif ($name !== '' && UserRepository::displayNameTooShort($name)) {
            $v->fail('display_name', UserRepository::displayNameRule());
        }
        if ((string) ($input['password_confirmation'] ?? '') !== (string) ($input['password'] ?? '')) {
            $v->fail('password_confirmation', '비밀번호 확인이 다릅니다.');
        }
        $v->check();

        return ['email' => $email, 'display_name' => $name, 'password' => $password];
    }

    /**
     * @param array{dsn: string, username: ?string, password: ?string, prefix?: string} $dbConfig
     * @param array{site_name: string, app_url: string, mail_from: string} $site
     * @param array{email: string, display_name: string, password: string}|null $admin null 이면 관리자를 만들지 않는다(이어 쓰기)
     * @param bool $reuse 이미 표가 있는 DB 를 이어 쓴다. 표를 새로 만들지 않고 새 판으로 옮긴다
     * @return array{dialect: string, admin_email: ?string, config_path: string, self_deleted: ?bool}
     */
    public function finish(array $dbConfig, array $site, ?array $admin, bool $reuse = false): array
    {
        if ($this->isInstalled()) {
            throw DomainError::forbidden('이미 설치되어 있습니다. 다시 설치하려면 config/config.php 를 지우세요.');
        }

        try {
            $db = Connection::create($dbConfig);
            $schema = new Schema($db);
            if ($reuse) {
                $schema->ensureCurrent();
            } else {
                $schema->create();
            }
        } catch (Throwable $e) {
            throw DomainError::validation(['_' => 'DB 에 연결하거나 표를 만들지 못했습니다: ' . $e->getMessage()]);
        }

        $this->ensureStorageDirectories();

        // 사이트 이름 갱신·관리자 생성·config.php 쓰기를 한 트랜잭션으로 묶는다.
        // config.php 쓰기가 실패(디스크 가득 참, config/ 권한 없음 등)하면 관리자 행도 같이
        // 롤백되어야, 재시도할 때 findByEmail() 이 "이미 있는 이메일" 로 막히지 않는다.
        $adminEmail = $db->transaction(function () use ($db, $dbConfig, $site, $admin): ?string {
            $db->execute(
                'UPDATE ' . $db->table('site_settings') . ' SET setting_value = ?, updated_at = ? WHERE setting_key = ?',
                [$site['site_name'], Clock::now(), 'site_name']
            );

            $adminEmail = null;
            if ($admin !== null) {
                $users = new UserRepository($db);
                if ($users->findByEmail($admin['email']) !== null) {
                    throw DomainError::validation(['email' => '이미 있는 이메일입니다.']);
                }
                $id = $users->create(
                    $admin['email'],
                    password_hash($admin['password'], PASSWORD_DEFAULT),
                    $users->uniqueDisplayName($admin['display_name']),
                    true
                );
                $users->verifyEmail($id);
                $db->execute(
                    'UPDATE ' . $db->table('site_settings')
                    . ' SET setting_value = ?, updated_at = ? WHERE setting_key = ?',
                    ['1', Clock::now(), 'system.first_admin_claimed']
                );
                $adminEmail = $admin['email'];
            }

            $this->writeConfig($dbConfig, $site);

            return $adminEmail;
        });

        $selfDeleted = null;
        if ($this->installScript !== null) {
            $selfDeleted = !is_file($this->installScript) || @unlink($this->installScript);
        }

        return [
            'dialect'      => $db->dialect()->name(),
            'admin_email'  => $adminEmail,
            'config_path'  => $this->configPath,
            'self_deleted' => $selfDeleted,
        ];
    }

    /**
     * @param array{dsn: string, username: ?string, password: ?string, prefix?: string} $dbConfig
     * @param array{site_name: string, app_url: string, mail_from: string} $site
     */
    private function writeConfig(array $dbConfig, array $site): void
    {
        $config = [
            'app' => [
                'url' => $site['app_url'],
            ],
            'mail' => [
                'from' => $site['mail_from'],
            ],
            'oauth' => [
                'google' => ['client_id' => '', 'client_secret' => ''],
                'naver'  => ['client_id' => '', 'client_secret' => ''],
                'kakao'  => ['client_id' => '', 'client_secret' => ''],
            ],
            'db' => [
                'dsn'      => $dbConfig['dsn'],
                'username' => ($dbConfig['username'] ?? '') === '' ? null : $dbConfig['username'],
                'password' => ($dbConfig['password'] ?? '') === '' ? null : $dbConfig['password'],
                'prefix'   => (string) ($dbConfig['prefix'] ?? ''),
            ],
            'auth' => [
                'secret'       => Base64Url::encode(random_bytes(32)),
                'password_min' => 8,
            ],
            'turnstile' => [
                'enabled'    => false,
                'site_key'   => '',
                'secret_key' => '',
                'hostname'   => (string) (parse_url($site['app_url'], PHP_URL_HOST) ?? ''),
            ],
            'uploads' => [
                'dir'         => $this->storageDir . '/uploads',
                'max_bytes'   => 5 * 1024 * 1024,
                'allowed_ext' => [
                    'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip', 'txt',
                    'hwp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                ],
            ],
            'editor' => [
                'dir'       => $this->storageDir . '/editor',
                'max_bytes' => 5 * 1024 * 1024,
            ],
            'log' => [
                'file' => $this->storageDir . '/logs/error.log',
            ],
            'debug' => false,
        ];

        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        if (@file_put_contents($this->configPath, $php, LOCK_EX) === false) {
            throw DomainError::internal('설정 파일을 쓰지 못했습니다: ' . $this->configPath);
        }
        @chmod($this->configPath, 0640);
    }

    private function ensureStorageDirectories(): void
    {
        foreach ([$this->storageDir . '/uploads', $this->storageDir . '/editor', $this->storageDir . '/logs'] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw DomainError::internal('디렉터리를 만들 수 없습니다: ' . $directory);
            }
        }
    }
}
