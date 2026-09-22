<?php

declare(strict_types=1);

namespace GnuCms\Db;

use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;
use Throwable;

/**
 * 코드를 올린 뒤 첫 요청에서 스키마를 새 판으로 옮긴다. 관리 서버는 없다.
 *
 * 순서: 도장 비교 → 최근 실패면 건너뜀 → 파일 잠금 → migrateAll → 기록.
 * 배포 전에 관리자 전체 백업 또는 호스팅의 DB 백업을 수행한다.
 * 실패하면 도장을 찍지 않고 실패 표식을 남겨 재시도 간격을 제한한다.
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
                ($this->migrate)();
                $this->upsertSetting('system.schema_upgraded_at', Clock::now());
                $this->upsertSetting('system.schema_backup', $backup ?? '');
                @unlink($this->failurePath());
            } catch (Throwable $e) {
                ($this->log)('[schema-upgrade] ' . get_class($e) . ': ' . $e->getMessage());
                $this->writeFailure($e->getMessage(), $backup, $stored);
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
        return [
            'version'     => Schema::VERSION,
            'stamp'       => (new Schema($this->db))->stamp(),
            'upgraded_at' => $this->setting('system.schema_upgraded_at'),
            'backup'      => null,
            'can_backup'  => false,
            'keep'        => self::KEEP_BACKUPS,
            'backups'     => [],
        ];
    }

    private function failurePath(): string
    {
        return $this->storageDir . '/upgrade-failed.json';
    }

    /** @return array{at: int, message: string, backup: ?string, stamp: ?string}|null */
    private function readFailure(): ?array
    {
        if (!is_file($this->failurePath())) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->failurePath()), true);

        return is_array($data) ? $data : null;
    }

    /** $stamp 는 실패 당시 storedStamp() — 재시도 때 같은 판인지 맞춰 보는 도장이다. */
    private function writeFailure(string $message, ?string $backup, ?string $stamp): void
    {
        $wrote = @file_put_contents(
            $this->failurePath(),
            json_encode(['at' => time(), 'message' => $message, 'backup' => $backup, 'stamp' => $stamp], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
                'SELECT setting_value FROM ' . $this->db->table('site_settings') . ' WHERE setting_key = ?',
                [$key]
            );
        } catch (DomainError $e) {
            return null;
        }

        return $row === null ? null : (string) $row['setting_value'];
    }

    private function upsertSetting(string $key, string $value): void
    {
        $table = $this->db->table('site_settings');
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
