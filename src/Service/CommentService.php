<?php

declare(strict_types=1);

namespace ApiBoard\Service;

use ApiBoard\Auth\Acl;
use ApiBoard\Cms\ContentImageService;
use ApiBoard\Cms\HtmlSanitizer;
use ApiBoard\Comment\TreeBuilder;
use ApiBoard\Error\DomainError;
use ApiBoard\Repository\CommentRepository;
use ApiBoard\Repository\PostRepository;
use ApiBoard\Validation\Validator;

final class CommentService
{
    public const SECRET_PLACEHOLDER = '비밀 댓글입니다.';

    /** @var PostService */
    private $postService;

    /** @var PostRepository */
    private $postRepo;

    /** @var CommentRepository */
    private $comments;

    /** @var HtmlSanitizer */
    private $sanitizer;

    /** @var ContentImageService */
    private $images;

    /** @var NotificationService|null */
    private $notifications;

    public function __construct(
        PostService $postService,
        PostRepository $postRepo,
        CommentRepository $comments,
        HtmlSanitizer $sanitizer,
        ContentImageService $images,
        ?NotificationService $notifications = null
    ) {
        $this->postService = $postService;
        $this->postRepo = $postRepo;
        $this->comments = $comments;
        $this->sanitizer = $sanitizer;
        $this->images = $images;
        $this->notifications = $notifications;
    }

    /** 댓글 본문도 편집기 HTML 이다. 저장과 출력 두 곳에서 정화한다. */
    private function cleanContent(string $raw): string
    {
        return $this->sanitizer->clean($raw);
    }

    /** 편집기가 올린 이미지를 묶는 폴더 이름. */
    private function editorImageKey(Validator $v, array $input): ?string
    {
        if (!array_key_exists('image_key', $input)) {
            return null;
        }
        $key = strtolower((string) $v->optionalString('image_key', 32));
        if ($key === '') {
            return null;
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
            $v->fail('image_key', '이미지 저장 정보를 확인할 수 없습니다.');

            return null;
        }

        return $key;
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
            throw DomainError::forbidden('삭제된 글에는 댓글을 쓸 수 없습니다.');
        }

        $v = new Validator($input);
        $data = [
            'board_id' => (int) $post['board_id'],
            'post_id'  => $postId,
            'content'  => $this->cleanContent($v->requiredString('content')),
        ];
        $data['image_key'] = $this->editorImageKey($v, $input);

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
            $data['author_name'] = (string) $identity->displayName();
            $data['guest_password'] = null;
        }

        $data['is_secret'] = $v->bool('is_secret', false) ? 1 : 0;

        $v->check();

        $id = $this->comments->create($data);
        $this->postRepo->adjustCommentCount($postId, 1);
        if ($data['image_key'] !== null) {
            $this->images->sync((string) $data['image_key'], (string) $data['content']);
        }
        if ($this->notifications !== null) {
            $this->notifications->notifyComment($postId, $id);
        }

        return $this->present($this->comments->find($id));
    }

    public function update(Acl $acl, int $id, array $input): array
    {
        $comment = $this->comments->findWithSecret($id);
        if ($comment === null || $comment['deleted_at'] !== null) {
            throw DomainError::notFound('댓글을 찾을 수 없습니다.');
        }
        $board = $this->boardOf($comment);

        $v = new Validator($input);
        $password = $v->optionalPassword('password');
        $acl->assertCanModify($board, $comment, $password);

        $data = ['content' => $this->cleanContent($v->requiredString('content'))];
        if (array_key_exists('is_secret', $input)) {
            $data['is_secret'] = $v->bool('is_secret', false) ? 1 : 0;
        }
        $imageKey = $this->editorImageKey($v, $input);
        if ($imageKey !== null) {
            $data['image_key'] = $imageKey;
        }
        $v->check();

        $this->comments->update($id, $data);
        // 고치면서 뺀 사진은 더 둘 이유가 없다.
        if ($imageKey !== null) {
            $this->images->sync($imageKey, (string) $data['content']);
        }

        return $this->present($this->comments->find($id));
    }

    public function delete(Acl $acl, int $id, ?string $password): void
    {
        $comment = $this->comments->findWithSecret($id);
        if ($comment === null || $comment['deleted_at'] !== null) {
            throw DomainError::notFound('댓글을 찾을 수 없습니다.');
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
            throw DomainError::notFound('게시판을 찾을 수 없습니다.');
        }

        return $board;
    }

    /**
     * 비밀 댓글의 내용을 트리 조립 전에 가린다. 트리를 만든 뒤 순회하는 것보다
     * 단순하고, 구조(누가 누구에게 달았는지)는 그대로 남는다.
     */
    /**
     * 수정 화면에 쓸 댓글 하나를 내준다.
     *
     * 글을 읽을 수 있어야 하고, 비밀 댓글이면 목록과 같은 기준으로 가려진다.
     * 가려진 댓글은 내용을 보여 줄 수 없으므로 아예 없는 것으로 다룬다.
     */
    public function getForEdit(Acl $acl, int $id): array
    {
        $comment = $this->comments->find($id);
        if ($comment === null || $comment['deleted_at'] !== null) {
            throw DomainError::notFound('댓글을 찾을 수 없습니다.');
        }

        $loaded = $this->postService->loadForRead($acl, (int) $comment['post_id'], null);
        $masked = $this->maskSecrets([$comment], $acl, $loaded['post'], $loaded['board'])[0];
        if ($masked['content'] === self::SECRET_PLACEHOLDER && (int) $comment['is_secret'] === 1) {
            throw DomainError::notFound('댓글을 찾을 수 없습니다.');
        }

        // 편집기가 올린 사진을 이어서 관리하려면 수정 화면도 같은 키를 알아야 한다.
        return $this->present($comment) + ['image_key' => $comment['image_key']];
    }

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
