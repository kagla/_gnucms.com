<?php

declare(strict_types=1);

namespace ApiBoard\Service;

use ApiBoard\Auth\Acl;
use ApiBoard\Error\DomainError;
use ApiBoard\Repository\CommentRepository;
use ApiBoard\Repository\NotificationRepository;
use ApiBoard\Repository\PostRepository;

/**
 * 사이트 안 알림함.
 *
 * 회원에게만 쌓는다. 비회원 글·댓글은 받을 사람을 특정할 수 없기 때문이다.
 * 알림을 만들다 실패해도 댓글 등록 자체는 막지 않는다 (부수적인 일이다).
 */
final class NotificationService
{
    public const PER_PAGE = 20;

    /** 알림 종류. 화면에서 문구를 고르는 데 쓴다. */
    public const KIND_COMMENT = 'comment';
    public const KIND_REPLY = 'reply';

    /** @var NotificationRepository */
    private $notifications;

    /** @var PostRepository */
    private $posts;

    /** @var CommentRepository */
    private $comments;

    public function __construct(
        NotificationRepository $notifications,
        PostRepository $posts,
        CommentRepository $comments
    ) {
        $this->notifications = $notifications;
        $this->posts = $posts;
        $this->comments = $comments;
    }

    /**
     * 새 댓글이 달렸을 때 알린다.
     *
     * 받을 사람은 두 갈래다. 글쓴이에게는 "내 글에 댓글", 답글이면 부모 댓글
     * 작성자에게 "내 댓글에 답글". 둘이 같은 사람이면 한 번만 보낸다.
     */
    public function notifyComment(int $postId, int $commentId): void
    {
        $comment = $this->comments->find($commentId);
        $post = $this->posts->find($postId);
        if ($comment === null || $post === null) {
            return;
        }

        $actor = (string) $comment['author_name'];
        $subject = (string) $post['title'];
        $writer = $this->memberId($comment['author_id']);

        $targets = [];

        $parentId = $comment['parent_id'] === null ? null : (int) $comment['parent_id'];
        if ($parentId !== null) {
            $parent = $this->comments->find($parentId);
            $owner = $parent === null ? null : $this->memberId($parent['author_id']);
            if ($owner !== null) {
                $targets[$owner] = self::KIND_REPLY;
            }
        }

        $author = $this->memberId($post['author_id']);
        if ($author !== null && !isset($targets[$author])) {
            $targets[$author] = self::KIND_COMMENT;
        }

        foreach ($targets as $userId => $kind) {
            // 내가 쓴 댓글로 나에게 알리지 않는다.
            if ((string) $userId === $writer) {
                continue;
            }

            $this->notifications->create([
                'user_id'    => (string) $userId,
                'kind'       => $kind,
                'post_id'    => $postId,
                'comment_id' => $commentId,
                'actor_name' => $actor,
                'subject'    => $subject,
            ]);
        }
    }

    public function unreadCount(Acl $acl): int
    {
        $userId = $this->currentUser($acl);

        return $userId === null ? 0 : $this->notifications->unreadCount($userId);
    }

    public function listFor(Acl $acl, int $page): array
    {
        $userId = $this->requireUser($acl);
        $page = max(1, $page);
        $result = $this->notifications->paginate($userId, $page, self::PER_PAGE);

        return [
            'items' => $result['items'],
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $result['total'],
            'total_pages' => (int) ceil($result['total'] / self::PER_PAGE),
        ];
    }

    /** 알림 하나를 읽음으로 바꾸고, 어디로 보내야 하는지 알려 준다. */
    public function open(Acl $acl, int $id): array
    {
        $userId = $this->requireUser($acl);
        $row = $this->notifications->find($id);
        // 남의 알림인지 없는 알림인지 구분해 알려 줄 이유가 없다. 둘 다 404 로 답한다.
        if ($row === null || $row['user_id'] !== $userId) {
            throw DomainError::notFound('알림을 찾을 수 없습니다.');
        }

        $this->notifications->markRead($id, $userId);

        return ['post_id' => $row['post_id'], 'comment_id' => $row['comment_id']];
    }

    public function markAllRead(Acl $acl): void
    {
        $this->notifications->markAllRead($this->requireUser($acl));
    }

    private function requireUser(Acl $acl): string
    {
        $userId = $this->currentUser($acl);
        if ($userId === null) {
            throw DomainError::unauthorized('로그인이 필요합니다.');
        }

        return $userId;
    }

    private function currentUser(Acl $acl): ?string
    {
        $identity = $acl->identity();
        if ($identity->isGuest()) {
            return null;
        }

        return $this->memberId($identity->sub());
    }

    /** 비회원이면 null. 그 밖에는 저장된 형태와 맞추기 위해 문자열로 돌려준다. */
    private function memberId($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
