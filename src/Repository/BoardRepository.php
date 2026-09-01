<?php

declare(strict_types=1);

namespace GnuCms\Repository;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;
use GnuCms\Support\Json;

final class BoardRepository
{
    /** 게시판 생성 시 지정하지 않은 컬럼의 기본값 */
    public const DEFAULTS = [
        'description'  => null,
        'categories'   => [],
        'managers'     => [],
        'perm_read'    => 'guest',
        'perm_write'   => 'member',
        'perm_comment' => 'member',
        'use_secret'   => 0,
        'use_file'     => 0,
        'use_category' => 0,
        'list_type'    => 'list',
        'home_limit'   => 5,
        'show_in_header' => 0,
        'per_page'     => 20,
        'sort_order'   => 0,
    ];

    /** JSON 문자열로 저장하고 배열로 돌려주는 컬럼 */
    private const JSON_COLUMNS = ['categories', 'managers'];

    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function findAll(): array
    {
        $rows = $this->db->select('SELECT * FROM ' . $this->db->table('boards') . ' ORDER BY sort_order ASC, id ASC');

        return array_map([$this, 'hydrate'], $rows);
    }

    public function findByKey(string $key): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('boards') . ' WHERE board_key = ?', [$key]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('boards') . ' WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function create(array $data): int
    {
        $now = Clock::now();
        $row = array_merge(self::DEFAULTS, $data, ['created_at' => $now, 'updated_at' => $now]);

        return (int) $this->db->insert('boards', $this->dehydrate($row));
    }

    public function update(int $id, array $data): void
    {
        unset($data['id'], $data['created_at']);
        $data['updated_at'] = Clock::now();

        $this->db->update('boards', $this->dehydrate($data), 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('boards', 'id = :id', ['id' => $id]);
    }

    /** DB 행 -> PHP 값 (JSON 문자열을 배열로) */
    private function hydrate(array $row): array
    {
        foreach (self::JSON_COLUMNS as $column) {
            $raw = $row[$column];
            $row[$column] = ($raw === null || $raw === '') ? [] : Json::decode((string) $raw);
        }

        foreach (['id', 'use_secret', 'use_file', 'use_category', 'show_in_header', 'per_page', 'sort_order'] as $column) {
            $row[$column] = (int) ($row[$column] ?? 0);
        }

        return $row;
    }

    /** PHP 값 -> DB 행 (배열을 JSON 문자열로) */
    private function dehydrate(array $row): array
    {
        foreach (self::JSON_COLUMNS as $column) {
            if (array_key_exists($column, $row) && is_array($row[$column])) {
                $row[$column] = Json::encode(array_values($row[$column]));
            }
        }

        return $row;
    }
}
