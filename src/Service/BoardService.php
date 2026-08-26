<?php

declare(strict_types=1);

namespace StandardBoard\Service;

use StandardBoard\Auth\Acl;
use StandardBoard\Http\ApiError;
use StandardBoard\Repository\BoardRepository;
use StandardBoard\Repository\CommentRepository;
use StandardBoard\Repository\PostRepository;
use StandardBoard\Validation\Validator;

final class BoardService
{
    public const PERM_LEVELS = ['guest', 'member', 'admin'];

    /** @var BoardRepository */
    private $boards;

    /** @var PostRepository */
    private $posts;

    /** @var CommentRepository */
    private $comments;

    public function __construct(BoardRepository $boards, PostRepository $posts, CommentRepository $comments)
    {
        $this->boards = $boards;
        $this->posts = $posts;
        $this->comments = $comments;
    }

    public function listBoards(Acl $acl): array
    {
        $visible = [];
        foreach ($this->boards->findAll() as $board) {
            if ($acl->canRead($board)) {
                $visible[] = $this->present($board, $acl);
            }
        }

        return $visible;
    }

    /** 원본 행을 돌려준다. 다른 서비스가 권한 판정에 쓴다. */
    public function getEntity(Acl $acl, string $key): array
    {
        $board = $this->boards->findByKey($key);
        if ($board === null) {
            throw ApiError::notFound('게시판을 찾을 수 없습니다: ' . $key);
        }
        $acl->assertCanRead($board);

        return $board;
    }

    public function get(Acl $acl, string $key): array
    {
        return $this->present($this->getEntity($acl, $key), $acl);
    }

    public function create(Acl $acl, array $input): array
    {
        $acl->assertGlobalAdmin();

        $data = $this->validate($input, true);

        if ($this->boards->findByKey($data['board_key']) !== null) {
            throw ApiError::validation(['board_key' => '이미 사용 중인 게시판 키입니다.']);
        }

        $id = $this->boards->create($data);

        return $this->present($this->boards->findById($id), $acl);
    }

    public function update(Acl $acl, string $key, array $input): array
    {
        $acl->assertGlobalAdmin();

        $board = $this->boards->findByKey($key);
        if ($board === null) {
            throw ApiError::notFound('게시판을 찾을 수 없습니다: ' . $key);
        }

        // 부분 수정이므로 요청에 담긴 필드만 검증하고 반영한다.
        $data = $this->validate($input, false);
        unset($data['board_key']);

        if ($data !== []) {
            $this->boards->update((int) $board['id'], $data);
        }

        return $this->present($this->boards->findById((int) $board['id']), $acl);
    }

    public function delete(Acl $acl, string $key): void
    {
        $acl->assertGlobalAdmin();

        $board = $this->boards->findByKey($key);
        if ($board === null) {
            throw ApiError::notFound('게시판을 찾을 수 없습니다: ' . $key);
        }

        $boardId = (int) $board['id'];
        $this->comments->deleteByBoard($boardId);
        $this->posts->deleteByBoard($boardId);
        $this->boards->delete($boardId);
    }

    public function present(array $board, Acl $acl): array
    {
        $view = [
            'id'           => (int) $board['id'],
            'board_key'    => $board['board_key'],
            'name'         => $board['name'],
            'description'  => $board['description'],
            'categories'   => $board['categories'],
            'perm_read'    => $board['perm_read'],
            'perm_write'   => $board['perm_write'],
            'perm_comment' => $board['perm_comment'],
            'use_secret'   => (bool) $board['use_secret'],
            'use_file'     => (bool) $board['use_file'],
            'use_category' => (bool) $board['use_category'],
            'per_page'     => (int) $board['per_page'],
            'sort_order'   => (int) $board['sort_order'],
            'created_at'   => $board['created_at'],
        ];

        // 관리자 목록은 운영 정보다. 관리 권한이 있는 사람에게만 보인다.
        if ($acl->isAdminFor($board)) {
            $view['managers'] = $board['managers'];
        }

        return $view;
    }

    /**
     * @param bool $isCreate true 면 필수 항목을 요구하고, false 면 주어진 필드만 검증한다.
     */
    private function validate(array $input, bool $isCreate): array
    {
        $v = new Validator($input);
        $data = [];

        if ($isCreate) {
            $key = $v->requiredString('board_key', 50);
            if ($key !== '' && preg_match('/^[a-z0-9_-]+$/', $key) !== 1) {
                $v->fail('board_key', '소문자, 숫자, 밑줄, 하이픈만 쓸 수 있습니다.');
            }
            $data['board_key'] = $key;
            $data['name'] = $v->requiredString('name', 100);
        } else {
            if (array_key_exists('name', $input)) {
                $data['name'] = $v->requiredString('name', 100);
            }
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = $v->optionalString('description', 2000);
        }
        foreach (['perm_read' => 'guest', 'perm_write' => 'member', 'perm_comment' => 'member'] as $field => $default) {
            if ($isCreate || array_key_exists($field, $input)) {
                $data[$field] = $v->inList($field, self::PERM_LEVELS, $default);
            }
        }
        foreach (['use_secret', 'use_file', 'use_category'] as $field) {
            if ($isCreate || array_key_exists($field, $input)) {
                $data[$field] = $v->bool($field, false) ? 1 : 0;
            }
        }
        if ($isCreate || array_key_exists('per_page', $input)) {
            $data['per_page'] = $v->int('per_page', 20, 1, 100);
        }
        if ($isCreate || array_key_exists('sort_order', $input)) {
            $data['sort_order'] = $v->int('sort_order', 0, -9999, 9999);
        }

        foreach (['categories', 'managers'] as $field) {
            if ($isCreate || array_key_exists($field, $input)) {
                $data[$field] = $this->stringList($v, $input, $field);
            }
        }

        $v->check();

        return $data;
    }

    private function stringList(Validator $v, array $input, string $field): array
    {
        $value = $input[$field] ?? [];
        if (!is_array($value)) {
            $v->fail($field, '배열이어야 합니다.');

            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
