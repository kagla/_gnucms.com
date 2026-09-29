<?php

declare(strict_types=1);

namespace GnuCms\Tests\Db;

use PHPUnit\Framework\TestCase;
use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;

final class ConnectionTest extends TestCase
{
    /** @var Connection */
    private $db;

    protected function setUp(): void
    {
        $this->db = Connection::create(\GnuCms\Tests\Support\DatabaseTestCase::mysqlConfig());
        $this->db->execute('DROP TABLE IF EXISTS widgets');
        $types = $this->db->dialect()->typeMap();
        $this->db->execute(
            'CREATE TABLE widgets (id ' . $types['{AUTO_PK}'] . ', name VARCHAR(50) NOT NULL, qty INTEGER NOT NULL DEFAULT 0)'
        );
    }

    public function testRejectsNonMysqlDsn(): void
    {
        $this->expectException(DomainError::class);
        Connection::create(['dsn' => 'unsupported:host=localhost']);
    }

    public function testInsertReturnsGeneratedId(): void
    {
        $id = $this->db->insert('widgets', ['name' => '가', 'qty' => 1]);

        $this->assertSame('1', $id);
    }

    public function testSelectOneReturnsAssociativeRow(): void
    {
        $this->db->insert('widgets', ['name' => '나', 'qty' => 7]);

        $row = $this->db->selectOne('SELECT * FROM widgets WHERE name = ?', ['나']);

        $this->assertSame('나', $row['name']);
        $this->assertSame(7, (int) $row['qty']);
    }

    public function testSelectOneReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->db->selectOne('SELECT * FROM widgets WHERE id = ?', [999]));
    }

    public function testNamedParametersWork(): void
    {
        $this->db->insert('widgets', ['name' => '다', 'qty' => 3]);

        $row = $this->db->selectOne('SELECT * FROM widgets WHERE name = :name', ['name' => '다']);

        $this->assertSame('다', $row['name']);
    }

    public function testUpdateReturnsAffectedRowCount(): void
    {
        $this->db->insert('widgets', ['name' => '라', 'qty' => 1]);
        $this->db->insert('widgets', ['name' => '마', 'qty' => 1]);

        $affected = $this->db->update('widgets', ['qty' => 9], 'qty = :qty', ['qty' => 1]);

        $this->assertSame(2, $affected);
    }

    public function testUpdateRejectsPositionalWhereParameters(): void
    {
        // PDO 는 한 문장에서 이름 파라미터와 위치 파라미터를 섞는 것을 금지한다.
        // 혼용하면 MySQL에서 SQLSTATE[HY093]으로 실패하므로 즉시 거부한다.
        $this->expectException(DomainError::class);
        $this->db->update('widgets', ['qty' => 9], 'qty = ?', [1]);
    }

    public function testNullValuesRoundTrip(): void
    {
        $this->db->execute('ALTER TABLE widgets ADD COLUMN note VARCHAR(20) NULL');
        $this->db->insert('widgets', ['name' => '바', 'qty' => 0, 'note' => null]);

        $row = $this->db->selectOne('SELECT note FROM widgets WHERE name = ?', ['바']);

        $this->assertNull($row['note']);
    }

    public function testTransactionCommitsOnSuccess(): void
    {
        $this->db->transaction(function (Connection $db): void {
            $db->insert('widgets', ['name' => '사', 'qty' => 1]);
        });

        $this->assertNotNull($this->db->selectOne('SELECT id FROM widgets WHERE name = ?', ['사']));
    }

    public function testTransactionRollsBackOnException(): void
    {
        try {
            $this->db->transaction(function (Connection $db): void {
                $db->insert('widgets', ['name' => '아', 'qty' => 1]);
                throw DomainError::internal('일부러 실패');
            });
            $this->fail('예외가 전파되어야 한다');
        } catch (DomainError $e) {
            // 기대한 경로
        }

        $this->assertNull($this->db->selectOne('SELECT id FROM widgets WHERE name = ?', ['아']));
    }

    public function testSyntaxErrorBecomesInternalDomainError(): void
    {
        $this->expectException(DomainError::class);
        $this->db->select('SELECT * FROM no_such_table');
    }
}
