<?php

declare(strict_types=1);

namespace StandardBoard\Db;

use PDO;
use PDOException;
use StandardBoard\Db\Dialect\DialectInterface;
use StandardBoard\Http\ApiError;
use Throwable;

/**
 * PDO 얇은 래퍼. 여기서 쓰는 SQL 은 세 DB 공통 문법이어야 하며,
 * 방언 차이는 전부 DialectInterface 를 통해서만 표현한다.
 */
final class Connection
{
    /** @var PDO */
    private $pdo;

    /** @var DialectInterface */
    private $dialect;

    private function __construct(PDO $pdo, DialectInterface $dialect)
    {
        $this->pdo = $pdo;
        $this->dialect = $dialect;
    }

    public static function create(array $dbConfig): self
    {
        $dsn = (string) ($dbConfig['dsn'] ?? '');
        if ($dsn === '') {
            throw ApiError::internal('db.dsn 설정이 비어 있습니다.');
        }

        $dialect = DialectFactory::fromDsn($dsn);

        try {
            $pdo = new PDO(
                $dsn,
                $dbConfig['username'] ?? null,
                $dbConfig['password'] ?? null,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            throw ApiError::internal('DB 접속에 실패했습니다: ' . $e->getMessage());
        }

        $dialect->afterConnect($pdo);

        return new self($pdo, $dialect);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function dialect(): DialectInterface
    {
        return $this->dialect;
    }

    public function q(string $identifier): string
    {
        return $this->dialect->quoteIdentifier($identifier);
    }

    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** @return string 생성된 기본키 */
    public function insert(string $table, array $data): string
    {
        $columns = array_keys($data);
        $quoted = array_map([$this, 'q'], $columns);
        $placeholders = array_map(static function (string $c): string {
            return ':' . $c;
        }, $columns);

        $sql = 'INSERT INTO ' . $this->q($table)
            . ' (' . implode(', ', $quoted) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $this->run($sql, $data);

        return $this->dialect->lastInsertId($this->pdo, $table);
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $assignments = [];
        $params = [];
        foreach ($data as $column => $value) {
            $assignments[] = $this->q($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }

        $sql = 'UPDATE ' . $this->q($table)
            . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . $where;

        // PDO requires positional parameters to be 1-indexed when mixed with named parameters
        $adjustedWhereParams = [];
        $positionalIndex = 1;
        foreach ($whereParams as $key => $value) {
            if (is_int($key)) {
                $adjustedWhereParams[$positionalIndex] = $value;
                $positionalIndex++;
            } else {
                $adjustedWhereParams[$key] = $value;
            }
        }

        return $this->execute($sql, $params + $adjustedWhereParams);
    }

    public function delete(string $table, string $where, array $whereParams = []): int
    {
        return $this->execute('DELETE FROM ' . $this->q($table) . ' WHERE ' . $where, $whereParams);
    }

    /**
     * @return mixed 콜백의 반환값
     */
    public function transaction(callable $fn)
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }

        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function run(string $sql, array $params): \PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params === [] ? null : $params);

            return $statement;
        } catch (PDOException $e) {
            throw ApiError::internal('쿼리 실행에 실패했습니다: ' . $e->getMessage() . ' | SQL: ' . $sql);
        }
    }
}
