<?php

declare(strict_types=1);

namespace GnuCms\Comment;

/**
 * 평면 댓글 목록을 중첩 트리로 만든다.
 *
 * 재귀를 쓰지 않는다. 자식은 부모보다 나중에 삽입되므로 id 가 항상 부모보다 크다.
 * 따라서 id 내림차순으로 한 번 훑으면 어떤 노드에 도달하는 시점에 그 자식들은
 * 이미 완성되어 있다. 깊이에 상관없이 스택이 자라지 않는다.
 */
final class TreeBuilder
{
    public const DELETED_PLACEHOLDER = '삭제된 댓글입니다.';

    /**
     * @param array $rows id 오름차순으로 정렬된 댓글 행 목록
     */
    public static function build(array $rows): array
    {
        /** @var array<int, array> $pending 부모 id -> 완성된 자식 노드 목록(역순) */
        $pending = [];
        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['id']] = true;
        }

        foreach (array_reverse($rows) as $row) {
            $id = (int) $row['id'];

            $children = isset($pending[$id]) ? array_reverse($pending[$id]) : [];
            unset($pending[$id]);

            $isDeleted = $row['deleted_at'] !== null;
            if ($isDeleted && $children === []) {
                // 자식 없는 삭제 댓글은 아예 보이지 않는다.
                continue;
            }

            $node = $isDeleted ? self::placeholder($row) : self::visible($row);
            $node['children'] = $children;

            $parentId = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
            if ($parentId !== 0 && !isset($ids[$parentId])) {
                // 부모가 목록에 없는 고아 행은 버린다. 루트로 승격시키면
                // 다른 글의 댓글이 섞여 보일 수 있다.
                continue;
            }

            $pending[$parentId][] = $node;
        }

        return isset($pending[0]) ? array_reverse($pending[0]) : [];
    }

    private static function visible(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'parent_id'   => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'depth'       => (int) $row['depth'],
            'content'     => (string) $row['content'],
            'author_id'   => $row['author_id'],
            'author_name' => (string) $row['author_name'],
            'is_secret'   => (bool) $row['is_secret'],
            'secret_masked' => (bool) ($row['secret_masked'] ?? false),
            'secret_unlockable' => (bool) ($row['secret_unlockable'] ?? false),
            'can_edit'     => (bool) ($row['can_edit'] ?? false),
            'needs_edit_password' => (bool) ($row['needs_edit_password'] ?? false),
            'deleted'     => false,
            'created_at'  => (string) $row['created_at'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }

    private static function placeholder(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'parent_id'   => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'depth'       => (int) $row['depth'],
            'content'     => self::DELETED_PLACEHOLDER,
            'author_id'   => null,
            'author_name' => null,
            'is_secret'   => false,
            'secret_masked' => false,
            'secret_unlockable' => false,
            'can_edit'     => false,
            'needs_edit_password' => false,
            'deleted'     => true,
            'created_at'  => (string) $row['created_at'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }
}
