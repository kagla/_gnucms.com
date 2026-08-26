<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Repository;

use ApiBoard\Repository\BoardRepository;
use ApiBoard\Support\Clock;
use PHPUnit\Framework\Attributes\DataProvider;
use ApiBoard\Tests\Support\DatabaseTestCase;

final class BoardRepositoryTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        Clock::freeze('2026-08-26 01:02:03');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    #[DataProvider('connectionProvider')]
    public function testCreateAndFindByKey(array $config): void
    {
        $repo = new BoardRepository($this->freshDatabase($config));
        $repo->create(['board_key' => 'free', 'name' => '자유게시판']);

        $board = $repo->findByKey('free');

        $this->assertSame('자유게시판', $board['name']);
        $this->assertSame('guest', $board['perm_read']);
        $this->assertSame('member', $board['perm_write']);
        $this->assertSame(20, (int) $board['per_page']);
        $this->assertSame('2026-08-26 01:02:03', substr((string) $board['created_at'], 0, 19));
    }

    #[DataProvider('connectionProvider')]
    public function testJsonColumnsComeBackAsArrays(array $config): void
    {
        $repo = new BoardRepository($this->freshDatabase($config));
        $repo->create([
            'board_key'  => 'qna',
            'name'       => '질문',
            'categories' => ['공지', '질문'],
            'managers'   => ['user-1', 'user-2'],
        ]);

        $board = $repo->findByKey('qna');

        $this->assertSame(['공지', '질문'], $board['categories']);
        $this->assertSame(['user-1', 'user-2'], $board['managers']);
    }

    #[DataProvider('connectionProvider')]
    public function testEmptyJsonColumnsBecomeEmptyArrays(array $config): void
    {
        $repo = new BoardRepository($this->freshDatabase($config));
        $repo->create(['board_key' => 'free', 'name' => '자유']);

        $board = $repo->findByKey('free');

        $this->assertSame([], $board['categories']);
        $this->assertSame([], $board['managers']);
    }

    #[DataProvider('connectionProvider')]
    public function testFindByKeyReturnsNullWhenMissing(array $config): void
    {
        $repo = new BoardRepository($this->freshDatabase($config));

        $this->assertNull($repo->findByKey('nope'));
    }

    #[DataProvider('connectionProvider')]
    public function testUpdateTouchesUpdatedAtAndKeepsOtherFields(array $config): void
    {
        $repo = new BoardRepository($this->freshDatabase($config));
        $id = $repo->create(['board_key' => 'free', 'name' => '자유']);

        Clock::freeze('2026-08-27 10:00:00');
        $repo->update($id, ['name' => '자유게시판', 'managers' => ['admin-1']]);

        $board = $repo->findById($id);
        $this->assertSame('자유게시판', $board['name']);
        $this->assertSame(['admin-1'], $board['managers']);
        $this->assertSame('free', $board['board_key']);
        $this->assertSame('2026-08-27 10:00:00', substr((string) $board['updated_at'], 0, 19));
        $this->assertSame('2026-08-26 01:02:03', substr((string) $board['created_at'], 0, 19));
    }

    #[DataProvider('connectionProvider')]
    public function testFindAllOrdersBySortOrderThenId(array $config): void
    {
        $repo = new BoardRepository($this->freshDatabase($config));
        $repo->create(['board_key' => 'c', 'name' => 'C', 'sort_order' => 2]);
        $repo->create(['board_key' => 'a', 'name' => 'A', 'sort_order' => 1]);
        $repo->create(['board_key' => 'b', 'name' => 'B', 'sort_order' => 1]);

        $keys = array_column($repo->findAll(), 'board_key');

        $this->assertSame(['a', 'b', 'c'], $keys);
    }

    #[DataProvider('connectionProvider')]
    public function testDeleteRemovesRow(array $config): void
    {
        $repo = new BoardRepository($this->freshDatabase($config));
        $id = $repo->create(['board_key' => 'temp', 'name' => '임시']);

        $repo->delete($id);

        $this->assertNull($repo->findById($id));
    }
}
