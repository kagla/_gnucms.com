<?php

declare(strict_types=1);

namespace GnuCms\Tests\Comment;

use PHPUnit\Framework\TestCase;
use GnuCms\Comment\TreeBuilder;

final class TreeBuilderTest extends TestCase
{
    public function testEmptyInputGivesEmptyTree(): void
    {
        $this->assertSame([], TreeBuilder::build([]));
    }

    public function testFlatCommentsBecomeRoots(): void
    {
        $tree = TreeBuilder::build([
            $this->row(1, null, 0),
            $this->row(2, null, 0),
        ]);

        $this->assertCount(2, $tree);
        $this->assertSame([1, 2], array_column($tree, 'id'));
        $this->assertSame([], $tree[0]['children']);
    }

    public function testChildrenNestUnderParent(): void
    {
        $tree = TreeBuilder::build([
            $this->row(1, null, 0),
            $this->row(2, 1, 1),
            $this->row(3, 1, 1),
        ]);

        $this->assertCount(1, $tree);
        $this->assertSame([2, 3], array_column($tree[0]['children'], 'id'));
    }

    public function testSiblingsKeepIdAscendingOrder(): void
    {
        $tree = TreeBuilder::build([
            $this->row(1, null, 0),
            $this->row(5, 1, 1),
            $this->row(9, 1, 1),
            $this->row(11, 1, 1),
        ]);

        $this->assertSame([5, 9, 11], array_column($tree[0]['children'], 'id'));
    }

    public function testDeepNestingHasNoDepthLimit(): void
    {
        $rows = [$this->row(1, null, 0)];
        for ($i = 2; $i <= 500; $i++) {
            $rows[] = $this->row($i, $i - 1, $i - 1);
        }

        $tree = TreeBuilder::build($rows);

        $node = $tree[0];
        $visited = 1;
        while ($node['children'] !== []) {
            $node = $node['children'][0];
            $visited++;
        }
        $this->assertSame(500, $visited);
    }

    public function testDeletedLeafIsRemoved(): void
    {
        $tree = TreeBuilder::build([
            $this->row(1, null, 0),
            $this->row(2, 1, 1, '2026-08-26 00:00:00'),
        ]);

        $this->assertSame([], $tree[0]['children']);
    }

    public function testDeletedNodeWithLivingChildBecomesPlaceholder(): void
    {
        $tree = TreeBuilder::build([
            $this->row(1, null, 0, '2026-08-26 00:00:00'),
            $this->row(2, 1, 1),
        ]);

        $this->assertCount(1, $tree);
        $this->assertTrue($tree[0]['deleted']);
        $this->assertSame('삭제된 댓글입니다.', $tree[0]['content']);
        $this->assertNull($tree[0]['author_name']);
        $this->assertSame([2], array_column($tree[0]['children'], 'id'));
    }

    public function testDeletedNodeWhoseChildrenAreAllDeletedIsRemoved(): void
    {
        $tree = TreeBuilder::build([
            $this->row(1, null, 0, '2026-08-26 00:00:00'),
            $this->row(2, 1, 1, '2026-08-26 00:00:00'),
        ]);

        $this->assertSame([], $tree);
    }

    public function testOrphanedRowIsDroppedNotPromoted(): void
    {
        $tree = TreeBuilder::build([
            $this->row(2, 99, 1),
        ]);

        $this->assertSame([], $tree);
    }

    private function row(int $id, ?int $parentId, int $depth, ?string $deletedAt = null): array
    {
        return [
            'id'          => $id,
            'post_id'     => 1,
            'parent_id'   => $parentId,
            'depth'       => $depth,
            'content'     => '댓글 ' . $id,
            'author_id'   => 'user-' . $id,
            'author_name' => '작성자 ' . $id,
            'is_secret'   => 0,
            'created_at'  => '2026-08-26 01:02:03',
            'updated_at'  => '2026-08-26 01:02:03',
            'deleted_at'  => $deletedAt,
        ];
    }
}
