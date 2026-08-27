<?php

declare(strict_types=1);

namespace ApiBoard\Service;

use ApiBoard\Auth\Acl;
use ApiBoard\Error\DomainError;
use ApiBoard\Repository\PostRepository;
use ApiBoard\Validation\Validator;

final class PostService
{
    /** @var BoardService */
    private $boards;

    /** @var PostRepository */
    private $posts;

    /** @var AttachmentService|null 순환 의존을 피하려고 나중에 주입한다 */
    private $attachments = null;

    public function __construct(BoardService $boards, PostRepository $posts)
    {
        $this->boards = $boards;
        $this->posts = $posts;
    }

    public function setAttachmentService(AttachmentService $attachments): void
    {
        $this->attachments = $attachments;
    }

    public function listPosts(Acl $acl, string $boardKey, array $query): array
    {
        $board = $this->boards->getEntity($acl, $boardKey);

        $v = new Validator($query);
        $page = $v->int('page', 1, 1, 100000);
        $perPage = $v->int('per_page', (int) $board['per_page'], 1, 100);
        $q = $v->optionalString('q', 100);
        $category = $v->optionalString('category', 50);
        $includeDeleted = $v->bool('include_deleted', false);
        $v->check();

        // 관리 권한이 없으면 조용히 무시한다. 오류로 만들 이유가 없다.
        $includeDeleted = $includeDeleted && $acl->isAdminFor($board);

        $result = $this->posts->paginate((int) $board['id'], $page, $perPage, $q, $category, $includeDeleted);

        $summaries = [];
        foreach ($result['rows'] as $row) {
            $summaries[] = $this->summary($row);
        }

        $notices = [];
        foreach ($this->posts->notices((int) $board['id']) as $row) {
            $notices[] = $this->summary($row);
        }

        return [
            'data'        => $summaries,
            'notices'     => $notices,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $result['total'],
            'total_pages' => $result['total'] === 0 ? 0 : (int) ceil($result['total'] / $perPage),
        ];
    }

    /** @return array{post: array, board: array} */
    public function loadForRead(Acl $acl, int $id, ?string $password): array
    {
        $post = $this->posts->findWithSecret($id);
        if ($post === null) {
            throw DomainError::notFound('글을 찾을 수 없습니다.');
        }

        $board = $this->boards->getEntity($acl, $this->boardKeyOf($post));

        if ($post['deleted_at'] !== null && !$acl->isAdminFor($board)) {
            throw DomainError::notFound('글을 찾을 수 없습니다.');
        }

        if ((int) $post['is_secret'] === 1 && !$acl->canModify($board, $post, $password)) {
            throw DomainError::forbidden('비밀글입니다.');
        }

        return ['post' => $post, 'board' => $board];
    }

    public function get(Acl $acl, int $id, ?string $password): array
    {
        $loaded = $this->loadForRead($acl, $id, $password);
        $post = $loaded['post'];

        $sub = $acl->identity()->sub();
        $isAuthor = $sub !== null && $post['author_id'] !== null && (string) $post['author_id'] === $sub;
        if (!$isAuthor) {
            $this->posts->incrementViews($id);
            $post = $this->posts->findWithSecret($id);
        }

        return $this->detail($post);
    }

    public function create(Acl $acl, string $boardKey, array $input): array
    {
        $board = $this->boards->getEntity($acl, $boardKey);
        $acl->assertCanWrite($board);

        $v = new Validator($input);
        $data = [
            'board_id' => (int) $board['id'],
            'title'    => $v->requiredString('title', 200),
            'content'  => $v->requiredString('content'),
        ];

        $identity = $acl->identity();
        if ($identity->isGuest()) {
            // 비회원 글: author_id 는 NULL 이고 비밀번호가 소유 증명 수단이 된다.
            $data['author_id'] = null;
            $data['author_name'] = $v->requiredString('author_name', 100);
            $password = $v->requiredPassword('password');
            $data['guest_password'] = $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);
        } else {
            // 로그인 사용자는 요청의 author_name 을 무시한다. 사칭 방지.
            $data['author_id'] = $identity->sub();
            $data['author_name'] = (string) $identity->name();
            $data['guest_password'] = null;
        }

        $data['category'] = $this->validateCategory($v, $board, $input);
        $data['is_secret'] = $this->validateSecret($v, $board, $v->bool('is_secret', false)) ? 1 : 0;
        $data['is_notice'] = 0;

        if (array_key_exists('is_notice', $input) && $v->bool('is_notice', false)) {
            $acl->assertAdminFor($board);
            $data['is_notice'] = 1;
        }

        if (array_key_exists('attachments', $input)) {
            $data['attachments'] = $this->verifyAttachments($board, $input['attachments']);
        }

        $v->check();

        $id = $this->posts->create($data);

        return $this->detail($this->posts->findWithSecret($id));
    }

    public function update(Acl $acl, int $id, array $input): array
    {
        $post = $this->posts->findWithSecret($id);
        if ($post === null) {
            throw DomainError::notFound('글을 찾을 수 없습니다.');
        }
        $board = $this->boards->getEntity($acl, $this->boardKeyOf($post));

        $v = new Validator($input);
        $password = $v->optionalPassword('password');
        $acl->assertCanModify($board, $post, $password);

        $data = [];
        if (array_key_exists('title', $input)) {
            $data['title'] = $v->requiredString('title', 200);
        }
        if (array_key_exists('content', $input)) {
            $data['content'] = $v->requiredString('content');
        }
        if (array_key_exists('category', $input)) {
            $data['category'] = $this->validateCategory($v, $board, $input);
        }
        if (array_key_exists('is_secret', $input)) {
            $data['is_secret'] = $this->validateSecret($v, $board, $v->bool('is_secret', false)) ? 1 : 0;
        }
        if (array_key_exists('is_notice', $input)) {
            $acl->assertAdminFor($board);
            $data['is_notice'] = $v->bool('is_notice', false) ? 1 : 0;
        }

        if (array_key_exists('attachments', $input)) {
            $data['attachments'] = $this->verifyAttachments($board, $input['attachments']);
        }

        $v->check();

        if ($data !== []) {
            $this->posts->update($id, $data);
        }

        return $this->detail($this->posts->findWithSecret($id));
    }

    public function delete(Acl $acl, int $id, ?string $password): void
    {
        $post = $this->posts->findWithSecret($id);
        if ($post === null) {
            throw DomainError::notFound('글을 찾을 수 없습니다.');
        }
        $board = $this->boards->getEntity($acl, $this->boardKeyOf($post));

        $acl->assertCanModify($board, $post, $password);

        $this->posts->softDelete($id);
    }

    public function restore(Acl $acl, int $id): array
    {
        $post = $this->posts->findWithSecret($id);
        if ($post === null) {
            throw DomainError::notFound('글을 찾을 수 없습니다.');
        }
        $board = $this->boards->getEntity($acl, $this->boardKeyOf($post));

        $acl->assertAdminFor($board);
        $this->posts->restore($id);

        return $this->detail($this->posts->findWithSecret($id));
    }

    private function boardKeyOf(array $post): string
    {
        $board = $this->boardsRepositoryLookup((int) $post['board_id']);

        return (string) $board['board_key'];
    }

    /** 댓글 서비스가 소속 게시판을 찾을 때 쓴다. */
    public function boardById(int $boardId): ?array
    {
        return $this->boards->findBoardById($boardId);
    }

    private function boardsRepositoryLookup(int $boardId): array
    {
        $board = $this->boards->findBoardById($boardId);
        if ($board === null) {
            throw DomainError::notFound('게시판을 찾을 수 없습니다.');
        }

        return $board;
    }

    private function verifyAttachments(array $board, $input): array
    {
        if (!is_array($input)) {
            throw DomainError::validation(['attachments' => '배열이어야 합니다.']);
        }
        if ($input !== [] && (int) $board['use_file'] !== 1) {
            throw DomainError::validation(['attachments' => '이 게시판은 첨부를 쓰지 않습니다.']);
        }
        if ($this->attachments === null) {
            throw DomainError::internal('첨부 서비스가 연결되지 않았습니다.');
        }

        $verified = [];
        foreach ($input as $descriptor) {
            $verified[] = $this->attachments->verify(is_array($descriptor) ? $descriptor : []);
        }

        return $verified;
    }

    private function validateCategory(Validator $v, array $board, array $input): ?string
    {
        $category = $v->optionalString('category', 50);
        if ($category === null) {
            return null;
        }
        if ((int) $board['use_category'] !== 1) {
            $v->fail('category', '이 게시판은 분류를 쓰지 않습니다.');

            return null;
        }
        if (!in_array($category, $board['categories'], true)) {
            $v->fail('category', '게시판에 없는 분류입니다.');

            return null;
        }

        return $category;
    }

    private function validateSecret(Validator $v, array $board, bool $requested): bool
    {
        if ($requested && (int) $board['use_secret'] !== 1) {
            $v->fail('is_secret', '이 게시판은 비밀글을 쓰지 않습니다.');

            return false;
        }

        return $requested;
    }

    private function summary(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'category'      => $row['category'],
            'title'         => $row['title'],
            'author_id'     => $row['author_id'],
            'author_name'   => $row['author_name'],
            'is_notice'     => (bool) $row['is_notice'],
            'is_secret'     => (bool) $row['is_secret'],
            'view_count'    => (int) $row['view_count'],
            'comment_count' => (int) $row['comment_count'],
            'file_count'    => count($row['attachments']),
            'deleted'       => $row['deleted_at'] !== null,
            'created_at'    => $row['created_at'],
        ];
    }

    private function detail(array $row): array
    {
        $view = $this->summary($row);
        $view['content'] = $row['content'];
        $view['updated_at'] = $row['updated_at'];
        $view['attachments'] = [];
        foreach ($row['attachments'] as $index => $file) {
            $view['attachments'][] = [
                'index' => $index,
                'name'  => $file['name'] ?? '',
                'size'  => (int) ($file['size'] ?? 0),
                'mime'  => $file['mime'] ?? 'application/octet-stream',
                'url'   => '/posts/' . (int) $row['id'] . '/files/' . $index,
            ];
        }

        return $view;
    }
}
