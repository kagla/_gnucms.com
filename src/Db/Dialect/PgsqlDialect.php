<?php

declare(strict_types=1);

namespace ApiBoard\Db\Dialect;

use PDO;
use ApiBoard\Http\ApiError;

final class PgsqlDialect implements DialectInterface
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function quoteIdentifier(string $name): string
    {
        if (strpos($name, '"') !== false) {
            throw ApiError::internal('식별자에 인용 문자를 쓸 수 없습니다: ' . $name);
        }

        return '"' . $name . '"';
    }

    public function typeMap(): array
    {
        return [
            '{AUTO_PK}'  => 'BIGSERIAL PRIMARY KEY',
            '{DATETIME}' => 'TIMESTAMP',
            '{TEXT}'     => 'TEXT',
        ];
    }

    public function tableSuffix(): string
    {
        return '';
    }

    public function lastInsertId(PDO $pdo, string $table): string
    {
        return (string) $pdo->lastInsertId($table . '_id_seq');
    }

    public function afterConnect(PDO $pdo): void
    {
        $pdo->exec("SET TIME ZONE 'UTC'");
    }
}
