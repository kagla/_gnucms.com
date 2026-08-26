<?php

declare(strict_types=1);

namespace StandardBoard\Tests\Db;

use StandardBoard\Db\Connection;
use StandardBoard\Db\Schema;
use StandardBoard\Tests\Support\DatabaseTestCase;

final class SchemaTest extends DatabaseTestCase
{
    /** @dataProvider connectionProvider */
    public function testCreatesAllThreeTables(array $config): void
    {
        $db = $this->freshDatabase($config);

        foreach (Schema::TABLES as $table) {
            $this->assertSame(
                0,
                (int) $db->selectOne('SELECT COUNT(*) AS c FROM ' . $db->q($table))['c'],
                $table . ' 테이블이 비어 있는 채로 존재해야 한다'
            );
        }
    }

    /** @dataProvider connectionProvider */
    public function testCreateIsIdempotent(array $config): void
    {
        $db = $this->freshDatabase($config);
        $schema = new Schema($db);

        $schema->create();
        $schema->create();

        $this->assertTrue($schema->exists());
    }

    /** @dataProvider connectionProvider */
    public function testDropRemovesEverything(array $config): void
    {
        $db = $this->freshDatabase($config);
        $schema = new Schema($db);

        $schema->drop();

        $this->assertFalse($schema->exists());
    }

    /** @dataProvider connectionProvider */
    public function testAutoIncrementPrimaryKeyWorks(array $config): void
    {
        $db = $this->freshDatabase($config);

        $first = $db->insert('boards', $this->boardRow('a'));
        $second = $db->insert('boards', $this->boardRow('b'));

        $this->assertGreaterThan((int) $first, (int) $second);
    }

    /** @dataProvider connectionProvider */
    public function testDatetimeColumnRoundTripsUtcString(array $config): void
    {
        $db = $this->freshDatabase($config);
        $db->insert('boards', $this->boardRow('c'));

        $row = $db->selectOne('SELECT created_at FROM boards WHERE board_key = ?', ['c']);

        $this->assertSame('2026-08-26 01:02:03', substr((string) $row['created_at'], 0, 19));
    }

    /** @dataProvider connectionProvider */
    public function testBoardKeyIsUnique(array $config): void
    {
        $db = $this->freshDatabase($config);
        $db->insert('boards', $this->boardRow('dup'));

        $this->expectException(\StandardBoard\Http\ApiError::class);
        $db->insert('boards', $this->boardRow('dup'));
    }

    private function boardRow(string $key): array
    {
        return [
            'board_key'    => $key,
            'name'         => '게시판 ' . $key,
            'description'  => null,
            'categories'   => '[]',
            'managers'     => '[]',
            'perm_read'    => 'guest',
            'perm_write'   => 'member',
            'perm_comment' => 'member',
            'use_secret'   => 0,
            'use_file'     => 0,
            'use_category' => 0,
            'per_page'     => 20,
            'sort_order'   => 0,
            'created_at'   => '2026-08-26 01:02:03',
            'updated_at'   => '2026-08-26 01:02:03',
        ];
    }
}
