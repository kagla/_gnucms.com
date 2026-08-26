<?php

declare(strict_types=1);

namespace ApiBoard\Service;

use ApiBoard\Auth\Acl;
use ApiBoard\Db\Connection;
use ApiBoard\Http\ApiError;
use ApiBoard\Repository\BoardRepository;
use ApiBoard\Repository\CommentRepository;
use ApiBoard\Repository\PostRepository;
use ApiBoard\Validation\Validator;

final class BoardService
{
    public const PERM_LEVELS = ['guest', 'member', 'admin'];

    /** @var Connection */
    private $db;

    /** @var BoardRepository */
    private $boards;

    /** @var PostRepository */
    private $posts;

    /** @var CommentRepository */
    private $comments;

    public function __construct(
        Connection $db,
        BoardRepository $boards,
        PostRepository $posts,
        CommentRepository $comments
    ) {
        $this->db = $db;
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

    /** 권한 검사 없이 원본 행을 돌려준다. 글/댓글이 소속 게시판을 찾을 때 쓴다. */
    public function findBoardById(int $id): ?array
    {
        return $this->boards->findById($id);
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

        $renames = $this->validateRenames($board, $data, $input);
        $boardId = (int) $board['id'];

        // 설정 변경과 글 이동은 한 덩어리다. 중간에 끊기면 어느 쪽 이름이
        // 맞는지 알 수 없는 상태가 남는다.
        $this->db->transaction(function () use ($boardId, $data, $renames): void {
            if ($data !== []) {
                $this->boards->update($boardId, $data);
            }
            foreach ($renames as $rename) {
                $this->posts->renameCategory($boardId, $rename[0], $rename[1]);
            }
        });

        return $this->present($this->boards->findById($boardId), $acl);
    }

    /**
     * 분류 이름 변경을 확인한다.
     *
     * 서버가 옛 목록과 새 목록을 비교해 "이건 이름 변경이겠지" 하고 짐작하지
     * 않는다. ["질문","잡담"] 이 ["문의","잡담"] 이 되었을 때, 질문을 문의로
     * 고친 것인지 질문을 지우고 문의를 새로 만든 것인지 목록만 봐서는 알 수
     * 없다. 짐작이 틀리면 남의 글이 조용히 다른 분류로 옮겨 간다.
     * 그래서 옮길 뜻이 있을 때만 호출자가 명시적으로 짝을 보낸다.
     *
     * @return array<int, array{0: string, 1: string}> [옛 이름, 새 이름] 목록
     */
    private function validateRenames(array $board, array $data, array $input): array
    {
        if (!array_key_exists('category_renames', $input)) {
            return [];
        }

        $raw = $input['category_renames'];
        if (!is_array($raw)) {
            throw ApiError::validation(['category_renames' => '옛 이름과 새 이름을 짝지은 객체여야 합니다.']);
        }
        if ($raw === []) {
            return [];
        }
        if (!array_key_exists('categories', $data)) {
            throw ApiError::validation(['category_renames' => '분류 목록(categories)과 함께 보내야 합니다.']);
        }

        $current = isset($board['categories']) && is_array($board['categories']) ? $board['categories'] : [];
        $next = $data['categories'];

        $renames = [];
        foreach ($raw as $key => $value) {
            $from = trim((string) $key);
            $to = is_array($value) ? '' : trim((string) $value);

            if ($from === '' || $to === '') {
                throw ApiError::validation(['category_renames' => '옛 이름과 새 이름이 모두 있어야 합니다.']);
            }
            if ($from === $to) {
                continue;
            }
            if (!in_array($from, $current, true)) {
                throw ApiError::validation([
                    'category_renames' => '지금 쓰고 있는 분류가 아닙니다: ' . $from,
                ]);
            }
            if (in_array($from, $next, true)) {
                throw ApiError::validation([
                    'category_renames' => '이름을 바꾸려면 옛 이름이 새 목록에서 빠져야 합니다: ' . $from,
                ]);
            }
            if (!in_array($to, $next, true)) {
                throw ApiError::validation([
                    'category_renames' => '새 이름이 분류 목록에 없습니다: ' . $to,
                ]);
            }

            $renames[] = [$from, $to];
        }

        return $renames;
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
