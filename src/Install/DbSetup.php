<?php

declare(strict_types=1);

namespace GnuCms\Install;

use GnuCms\Db\Connection;
use GnuCms\Db\Schema;
use GnuCms\Error\DomainError;

/**
 * 설치 2단계. 입력 칸을 MySQL DSN으로 조립하고 접속을 시험한다.
 * 사람이 DSN 문법을 알 필요가 없게 하는 것이 목적이다.
 */
final class DbSetup
{
    private const MYSQL_PORT = 3306;

    /** @return array{dsn: string, username: ?string, password: ?string, prefix: string} */
    public static function dsnFrom(array $input): array
    {
        $prefix = trim((string) ($input['prefix'] ?? ''));
        if ($prefix !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,28}_$/D', $prefix) !== 1) {
            throw DomainError::validation([
                'prefix' => '영문으로 시작하고 영문·숫자·밑줄만 사용해 밑줄로 끝내세요. 최대 30자입니다.',
            ]);
        }

        $errors = [];
        $host = trim((string) ($input['host'] ?? ''));
        $portRaw = trim((string) ($input['port'] ?? ''));
        $port = $portRaw === '' ? self::MYSQL_PORT : (int) $portRaw;
        $name = trim((string) ($input['name'] ?? ''));
        $user = trim((string) ($input['user'] ?? ''));

        if ($host === '' || preg_match('/^[A-Za-z0-9_.\-]+$/', $host) !== 1) {
            $errors['host'] = '호스트는 영문·숫자·점·하이픈만 씁니다.';
        }
        if ($port < 1 || $port > 65535 || ($portRaw !== '' && (string) $port !== $portRaw)) {
            $errors['port'] = '포트는 1~65535 사이의 숫자입니다.';
        }
        if ($name === '' || preg_match('/^[A-Za-z0-9_.\-]+$/', $name) !== 1) {
            $errors['name'] = 'DB 이름은 영문·숫자·밑줄·점·하이픈만 씁니다.';
        }
        if ($user === '') {
            $errors['user'] = 'DB 계정을 적어 주세요.';
        }
        if ($errors !== []) {
            throw DomainError::validation($errors);
        }

        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';

        return ['dsn' => $dsn, 'username' => $user, 'password' => (string) ($input['password'] ?? ''), 'prefix' => $prefix];
    }

    /**
     * 접속해 보고 무엇이 들어 있는지 알려 준다. 못 붙으면 422 ('_' 칸).
     *
     * @param array{dsn: string, username: ?string, password: ?string} $dbConfig
     * @return array{dialect: string, has_tables: bool, has_admin: bool}
     */
    public static function probe(array $dbConfig): array
    {
        try {
            $db = Connection::create($dbConfig);
        } catch (DomainError $e) {
            throw DomainError::validation(['_' => $e->getMessage()]);
        }

        $hasTables = (new Schema($db))->exists();
        $hasAdmin = false;
        if ($hasTables) {
            try {
                $row = $db->selectOne('SELECT COUNT(*) AS c FROM ' . $db->table('users') . ' WHERE is_admin = 1');
                $hasAdmin = (int) ($row['c'] ?? 0) > 0;
            } catch (DomainError $e) {
                // users 표가 없는 아주 오래된 설치. 관리자가 없다고 본다.
                $hasAdmin = false;
            }
        }

        return ['dialect' => 'mysql', 'has_tables' => $hasTables, 'has_admin' => $hasAdmin];
    }
}
