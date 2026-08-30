<?php

declare(strict_types=1);

namespace GnuCms\Db;

use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;
use Throwable;

/**
 * 코드를 올린 뒤 첫 요청에서 스키마를 새 판으로 옮긴다. 관리 서버는 없다.
 *
 * 순서: 도장 비교 → 최근 실패면 건너뜀 → 파일 잠금 → 백업(SQLite) → migrateAll → 기록.
 * 실패하면 도장을 찍지 않고 upgrade-failed.json 을 남긴 뒤 MaintenanceRequired 를 던진다.
 * 그 파일이 RETRY_AFTER_SECONDS 안이면 다시 시도하지 않고 바로 점검 화면으로 보낸다.
 */
final class SchemaUpgrader
{
    public const KEEP_BACKUPS = 5;
    public const RETRY_AFTER_SECONDS = 60;

    private Connection $db;
    private string $storageDir;
    /** @var callable */
    private $migrate;
    /** @var callable */
    private $log;

    /**
     * @param callable|null $migrate 실제 마이그레이션 대신 부를 것(테스트용). 기본은 Schema::migrateAll()
     * @param callable|null $log     한 줄을 받는 기록 함수. 기본은 storage/logs/error.log 에 덧붙임
     */
    public function __construct(Connection $db, string $storageDir, ?callable $migrate = null, ?callable $log = null)
    {
        $this->db = $db;
        $this->storageDir = rtrim($storageDir, '/');
        $this->migrate = $migrate ?? [new Schema($db), 'migrateAll'];
        $this->log = $log ?? function (string $line): void {
            $dir = $this->storageDir . '/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $wrote = is_dir($dir)
                ? @file_put_contents($dir . '/error.log', '[' . gmdate('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX)
                : false;
            if ($wrote === false) {
                error_log($line);
            }
        };
    }

    public function run(): void
    {
        $schema = new Schema($this->db);
        $stored = $schema->storedStamp();
        if ($stored === $schema->stamp()) {
            return;
        }

        $failed = $this->readFailure();
        if ($failed !== null && time() - (int) ($failed['at'] ?? 0) < self::RETRY_AFTER_SECONDS) {
            throw new MaintenanceRequired(MaintenanceRequired::FAILED, $failed['backup'] ?? null);
        }

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0775, true);
        }
        $lockPath = $this->storageDir . '/upgrade.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            ($this->log)('[schema-upgrade] 잠금 파일을 만들 수 없습니다: ' . $lockPath);
            throw new MaintenanceRequired(MaintenanceRequired::FAILED);
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new MaintenanceRequired(MaintenanceRequired::BUSY);
        }

        try {
            // 잠금을 잡는 사이 다른 요청이 끝냈을 수 있다.
            $stored = $schema->storedStamp();
            if ($stored === $schema->stamp()) {
                return;
            }

            $backup = null;
            try {
                // 실패 뒤 재시도라면, 그 실패 이전의 원본 스냅숏을 그대로 쓴다.
                // 매번 새로 VACUUM 하면 5개까지만 남기는 정리 때문에 다섯 번
                // 재시도한 뒤에는 첫 시도 이전의 깨끗한 백업이 사라진다.
                $reusable = $failed['backup'] ?? null;
                if (is_string($reusable) && $reusable !== '' && is_file($reusable)) {
                    $backup = $reusable;
                } else {
                    $backup = $this->backup($stored);
                }
                ($this->migrate)();
                $this->upsertSetting('schema_upgraded_at', Clock::now());
                $this->upsertSetting('schema_backup', $backup ?? '');
                @unlink($this->failurePath());
            } catch (Throwable $e) {
                ($this->log)('[schema-upgrade] ' . get_class($e) . ': ' . $e->getMessage());
                $this->writeFailure($e->getMessage(), $backup);
                throw new MaintenanceRequired(MaintenanceRequired::FAILED, $backup, $e);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 관리 콘솔에 보일 값.
     *
     * @return array{version: string, stamp: string, upgraded_at: ?string, backup: ?string, can_backup: bool, keep: int, backups: list<array{name: string, size: int, mtime: int}>}
     */
    public function status(): array
    {
        $backups = [];
        foreach ($this->backupFiles() as $file) {
            $backups[] = ['name' => basename($file), 'size' => (int) filesize($file), 'mtime' => (int) filemtime($file)];
        }
        $backup = $this->setting('schema_backup');

        return [
            'version'     => Schema::VERSION,
            'stamp'       => (new Schema($this->db))->stamp(),
            'upgraded_at' => $this->setting('schema_upgraded_at'),
            'backup'      => $backup === null || $backup === '' ? null : $backup,
            'can_backup'  => $this->db->dialect()->name() === 'sqlite',
            'keep'        => self::KEEP_BACKUPS,
            'backups'     => $backups,
        ];
    }

    /** SQLite 면 VACUUM INTO 로 일관된 복사본을 만들고 경로를 돌려준다. 다른 DB 는 null. */
    private function backup(?string $storedStamp): ?string
    {
        if ($this->db->dialect()->name() !== 'sqlite') {
            return null;
        }
        $dir = $this->storageDir . '/backups';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('백업 폴더를 만들 수 없습니다: ' . $dir);
        }
        $old = $storedStamp === null ? '0' : (string) strtok($storedStamp, '.');
        $base = $dir . '/board-v' . preg_replace('/[^0-9A-Za-z]/', '', $old) . '-' . gmdate('Ymd-His');
        $path = $base . '.sqlite';
        for ($n = 2; is_file($path); $n++) {
            $path = $base . '-' . $n . '.sqlite';
        }
        // VACUUM INTO 는 쓰는 중에도 안전한 스냅숏을 만든다. 경로의 작은따옴표는 두 겹으로 피한다.
        $this->db->pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $path) . "'");
        $this->prune();

        return $path;
    }

    /** 최근 KEEP_BACKUPS 개만 남긴다. 이름이 판 번호 다음 일시 순이라 자연 정렬 역순이 최신순이다. */
    private function prune(): void
    {
        foreach (array_slice($this->backupFiles(), self::KEEP_BACKUPS) as $old) {
            @unlink($old);
        }
    }

    /** @return string[] 최신순. 이름을 판 번호(자연 정렬) 다음 일시로 내림차순 비교한다. */
    private function backupFiles(): array
    {
        $files = glob($this->storageDir . '/backups/board-v*.sqlite') ?: [];
        usort($files, static fn (string $a, string $b): int => strnatcmp($b, $a));

        return $files;
    }

    private function failurePath(): string
    {
        return $this->storageDir . '/upgrade-failed.json';
    }

    /** @return array{at: int, message: string, backup: ?string}|null */
    private function readFailure(): ?array
    {
        if (!is_file($this->failurePath())) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->failurePath()), true);

        return is_array($data) ? $data : null;
    }

    private function writeFailure(string $message, ?string $backup): void
    {
        $wrote = @file_put_contents(
            $this->failurePath(),
            json_encode(['at' => time(), 'message' => $message, 'backup' => $backup], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        if ($wrote === false) {
            ($this->log)('[schema-upgrade] 실패 표식을 쓸 수 없습니다: ' . $this->failurePath());
        }
    }

    private function setting(string $key): ?string
    {
        try {
            $row = $this->db->selectOne(
                'SELECT setting_value FROM ' . $this->db->q('site_settings') . ' WHERE setting_key = ?',
                [$key]
            );
        } catch (DomainError $e) {
            return null;
        }

        return $row === null ? null : (string) $row['setting_value'];
    }

    private function upsertSetting(string $key, string $value): void
    {
        $table = $this->db->q('site_settings');
        $now = Clock::now();
        if ($this->setting($key) === null) {
            $this->db->execute(
                'INSERT INTO ' . $table . ' (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
                [$key, $value, $now]
            );
            return;
        }
        $this->db->execute(
            'UPDATE ' . $table . ' SET setting_value = ?, updated_at = ? WHERE setting_key = ?',
            [$value, $now, $key]
        );
    }
}
