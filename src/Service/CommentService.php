<?php

declare(strict_types=1);

namespace StandardBoard\Service;

use StandardBoard\Auth\Acl;
use StandardBoard\Comment\TreeBuilder;
use StandardBoard\Http\ApiError;
use StandardBoard\Repository\CommentRepository;
use StandardBoard\Repository\PostRepository;
use StandardBoard\Validation\Validator;

final class CommentService
{
    public const SECRET_PLACEHOLDER = '비밀 댓글입니다.';

    /** @var PostService */
    private $postService;

    /** @var PostRepository */
    private $postRepo;

    /** @var CommentRepository */
    private $comments;

    public function __construct(PostService $postService, PostRepository $postRepo, CommentRepository $comments)
    {
        $this->postService = $postService;
        $this->postRepo = $postRepo;
        $this->comments = $comments;
    }

    public function listComments(Acl $acl, int $postId, ?string $password): array
    {
        $loaded = $this->postService->loadForRead($acl, $postId, $password);

        $rows = $this->comments->findByPost($postId);
        $rows = $this->maskSecrets($rows, $acl, $loaded['post'], $loaded['board']);

        return TreeBuilder::build($rows);
    }

    public function create(Acl $acl, int $postId, array $input): array
    {
        $loaded = $this->postService->loadForRead($acl, $postId, $input['post_password'] ?? null);
        $post = $loaded['post'];
        $board = $loaded['board'];

        $acl->assertCanComment($board);

        if ($post['deleted_at'] !== null) {
            throw ApiError::forbidden('삭제된 글에는 댓글을 쓸 수 없습니다.');
        }

        $v = new Validator($input);
        $data = [
            'board_id' => (int) $post['board_id'],
            'post_id'  => $postId,
            'content'  => $v->requiredString('content'),
        ];

        $parentId = $v->int('parent_id', 0, 0, PHP_INT_MAX);
        if ($parentId > 0) {
            $parent = $this->comments->find($parentId);
            if ($parent === null || (int) $parent['post_id'] !== $postId) {
                $v->fail('parent_id', '이 글의 댓글이 아닙니다.');
            }
            $data['parent_id'] = $parentId;
        } else {
            $data['parent_id'] = null;
        }

        $identity = $acl->identity();
        if ($identity->isGuest()) {
            $data['author_id'] = null;
            $data['author_name'] = $v->requiredString('author_name', 100);
            $password = $v->requiredPassword('password');
            $data['guest_password'] = $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);
        } else {
            $data['author_id'] = $identity->sub();
            $data['author_name'] = (string) $identity->name();
            $data['guest_password'] = null;
        }

        $data['is_secret'] = $v->bool('is_secret', false) ? 1 : 0;

        $v->check();

        $id = $this->comments->create($data);
        $this->postRepo->adjustCommentCount($postId, 1);

        return $this->present($this->comments->find($id));
    }

    public function update(Acl $acl, int $id, array $input): array
    {
        $comment = $this->comments->findWithSecret($id);
        if ($comment === null || $comment['deleted_at'] !== null) {
            throw ApiError::notFound('댓글을 찾을 수 없습니다.');
        }
        $board = $this->boardOf($comment);

        $v = new Validator($input);
        $password = $v->optionalPassword('password');
        $acl->assertCanModify($board, $comment, $password);

        $data = ['content' => $v->requiredString('content')];
        if (array_key_exists('is_secret', $input)) {
            $data['is_secret'] = $v->bool('is_secret', false) ? 1 : 0;
        }
        $v->check();

        $this->comments->update($id, $data);

        return $this->present($this->comments->find($id));
    }

    public function delete(Acl $acl, int $id, ?string $password): void
    {
        $comment = $this->comments->findWithSecret($id);
        if ($comment === null || $comment['deleted_at'] !== null) {
            throw ApiError::notFound('댓글을 찾을 수 없습니다.');
        }
        $board = $this->boardOf($comment);

        $acl->assertCanModify($board, $comment, $password);

        $this->comments->softDelete($id);
        $this->postRepo->adjustCommentCount((int) $comment['post_id'], -1);
    }

    private function boardOf(array $comment): array
    {
        $board = $this->postService->boardById((int) $comment['board_id']);
        if ($board === null) {
            throw ApiError::notFound('게시판을 찾을 수 없습니다.');
        }

        return $board;
    }

    /**
     * 비밀 댓글의 내용을 트리 조립 전에 가린다. 트리를 만든 뒤 순회하는 것보다
     * 단순하고, 구조(누가 누구에게 달았는지)는 그대로 남는다.
     */
    private function maskSecrets(array $rows, Acl $acl, array $post, array $board): array
    {
        $sub = $acl->identity()->sub();
        $isPostAuthor = $sub !== null && $post['author_id'] !== null && (string) $post['author_id'] === $sub;
        $isAdmin = $acl->isAdminFor($board);

        foreach ($rows as $index => $row) {
            if ((int) $row['is_secret'] !== 1) {
                continue;
            }
            $isOwn = $sub !== null && $row['author_id'] !== null && (string) $row['author_id'] === $sub;
            if ($isOwn || $isPostAuthor || $isAdmin) {
                continue;
            }
            $rows[$index]['content'] = self::SECRET_PLACEHOLDER;
        }

        return $rows;
    }

    private function present(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'post_id'     => (int) $row['post_id'],
            'parent_id'   => $row['parent_id'],
            'depth'       => (int) $row['depth'],
            'content'     => $row['content'],
            'author_id'   => $row['author_id'],
            'author_name' => $row['author_name'],
            'is_secret'   => (bool) $row['is_secret'],
            'deleted'     => $row['deleted_at'] !== null,
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ];
    }
}
