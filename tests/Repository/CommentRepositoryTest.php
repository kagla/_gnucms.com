<?php

declare(strict_types=1);

namespace GnuCms\Tests\Repository;

use GnuCms\Repository\BoardRepository;
use GnuCms\Repository\CommentRepository;
use GnuCms\Repository\PostRepository;
use GnuCms\Support\Clock;
use PHPUnit\Framework\Attributes\DataProvider;
use GnuCms\Tests\Support\DatabaseTestCase;

final class CommentRepositoryTest extends DatabaseTestCase
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
    public function testRootCommentHasDepthZero(array $config): void
    {
        [$repo, $postId, $boardId] = $this->setUpPost($config);
        $id = $repo->create($this->comment($boardId, $postId, null, '루트'));

        $this->assertSame(0, $repo->find($id)['depth']);
    }

    #[DataProvider('connectionProvider')]
    public function testDepthIsDerivedFromParent(array $config): void
    {
        [$repo, $postId, $boardId] = $this->setUpPost($config);
        $root = $repo->create($this->comment($boardId, $postId, null, '루트'));
        $child = $repo->create($this->comment($boardId, $postId, $root, '자식'));
        $grandChild = $repo->create($this->comment($boardId, $postId, $child, '손자'));

        $this->assertSame(1, $repo->find($child)['depth']);
        $this->assertSame(2, $repo->find($grandChild)['depth']);
    }

    #[DataProvider('connectionProvider')]
    public function testFindByPostReturnsIdAscendingIncludingDeleted(array $config): void
    {
        [$repo, $postId, $boardId] = $this->setUpPost($config);
        $a = $repo->create($this->comment($boardId, $postId, null, 'a'));
        $b = $repo->create($this->comment($boardId, $postId, null, 'b'));
        $repo->softDelete($a);

        $rows = $repo->findByPost($postId);

        $this->assertSame([$a, $b], array_column($rows, 'id'));
        $this->assertNotNull($rows[0]['deleted_at']);
    }

    #[DataProvider('connectionProvider')]
    public function testFindByPostNeverExposesGuestPassword(array $config): void
    {
        [$repo, $postId, $boardId] = $this->setUpPost($config);
        $repo->create($this->comment($boardId, $postId, null, '비회원') + [
            'author_id'      => null,
            'guest_password' => password_hash('1234', PASSWORD_DEFAULT),
        ]);

        $this->assertArrayNotHasKey('guest_password', $repo->findByPost($postId)[0]);
    }

    #[DataProvider('connectionProvider')]
    public function testHasChildren(array $config): void
    {
        [$repo, $postId, $boardId] = $this->setUpPost($config);
        $root = $repo->create($this->comment($boardId, $postId, null, '루트'));
        $leaf = $repo->create($this->comment($boardId, $postId, $root, '자식'));

        $this->assertTrue($repo->hasChildren($root));
        $this->assertFalse($repo->hasChildren($leaf));
    }

    #[DataProvider('connectionProvider')]
    public function testUpdateTouchesUpdatedAt(array $config): void
    {
        [$repo, $postId, $boardId] = $this->setUpPost($config);
        $id = $repo->create($this->comment($boardId, $postId, null, '원본'));

        Clock::freeze('2026-08-27 09:00:00');
        $repo->update($id, ['content' => '수정본']);

        $row = $repo->find($id);
        $this->assertSame('수정본', $row['content']);
        $this->assertSame('2026-08-27 09:00:00', substr((string) $row['updated_at'], 0, 19));
    }

    /** @return array{0: CommentRepository, 1: int, 2: int} */
    private function setUpPost(array $config): array
    {
        $db = $this->freshDatabase($config);
        $boardId = (new BoardRepository($db))->create(['board_key' => 'free', 'name' => '자유']);
        $postId = (new PostRepository($db))->create([
            'board_id'    => $boardId,
            'title'       => '글',
            'content'     => '본문',
            'author_id'   => 'user-1',
            'author_name' => '홍길동',
        ]);

        return [new CommentRepository($db), $postId, $boardId];
    }

    private function comment(int $boardId, int $postId, ?int $parentId, string $content): array
    {
        return [
            'board_id'    => $boardId,
            'post_id'     => $postId,
            'parent_id'   => $parentId,
            'content'     => $content,
            'author_id'   => 'user-1',
            'author_name' => '홍길동',
        ];
    }
}
