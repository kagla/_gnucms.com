<?php

declare(strict_types=1);

namespace GnuCms\Service;

use GnuCms\Account\UserRepository;
use GnuCms\Auth\Acl;
use GnuCms\Cms\CmsService;
use GnuCms\Error\DomainError;
use GnuCms\Notify\MessageVars;
use GnuCms\Notify\Notifier;
use GnuCms\Notify\Recipient;
use GnuCms\Repository\CommentRepository;
use GnuCms\Repository\NotificationRepository;
use GnuCms\Repository\PostRepository;

/**
 * 사이트 내 알림함, 그리고 댓글 알림이 채널로 나가는 자리.
 *
 * 회원에게만 알린다. 비회원 글·댓글은 받을 사람을 특정할 수 없기 때문이다.
 * 댓글과 필수 알림함은 함께 저장하며, 외부 발송 실패는 이미 저장한 댓글을 되돌리지 않는다.
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

    private UserRepository $users;

    private CmsService $cms;

    private string $appUrl;

    /** @var \Closure(): Notifier */
    private \Closure $notifier;

    /** @var \Closure(string): void */
    private \Closure $log;

    /**
     * @param callable(): Notifier $notifier 발송 시점에 부른다 — 쥐고 있지 않는다.
     *
     * **왜 발송기를 만들어진 채로 받지 않는가.** 두 가지 이유가 같은 방향을 가리킨다.
     *   조립 — App 의 게터는 new 가 끝난 **뒤에** 메모이즈한다. 발송기를 조립하려면
     *          알림함 채널이 필요하고 그 채널은 이 서비스를 필요로 하므로, 만들어진
     *          발송기를 생성자에서 요구하면 두 게터가 서로를 돌아 부를 길이 열린다
     *          (InboxChannel 이 callable 을 쥐는 것과 같은 이유의 같은 해법이다).
     *   교체 — App::setMailer()·setAligo() 는 발송기를 끊어 다시 만들게 한다. 이 서비스가
     *          옛 사본을 쥐고 있으면 그 교체가 여기만 비껴가, 시험이 가짜로 바꿔 둔
     *          메일러·알리고 대신 진짜가 쓰인다. 발송 시점에 물으면 언제나 지금의 것이다.
     *
     * @param (callable(string): void)|null $log 기본은 PHP 오류 로그. 시험이 바꿔 낀다
     *   (Notifier·UnwiredNotifier 와 같은 자리, 같은 이유).
     */
    public function __construct(
        NotificationRepository $notifications,
        PostRepository $posts,
        CommentRepository $comments,
        UserRepository $users,
        CmsService $cms,
        string $appUrl,
        callable $notifier,
        ?callable $log = null
    ) {
        $this->notifications = $notifications;
        $this->posts = $posts;
        $this->comments = $comments;
        $this->users = $users;
        $this->cms = $cms;
        $this->appUrl = rtrim($appUrl, '/');
        $this->notifier = \Closure::fromCallable($notifier);
        $this->log = $log === null
            ? static function (string $line): void {
                error_log('[' . GNUCMS_ID . '] ' . $line);
            }
            : \Closure::fromCallable($log);
    }

    /** 댓글 저장과 같은 거래에서 필수 알림함을 기록하고 외부 채널은 커밋 뒤에 보낸다. */
    public function notifyComment(int $postId, int $commentId): void
    {
        $comment = $this->comments->find($commentId);
        $post = $this->posts->find($postId);
        if ($comment === null || $post === null) return;
        foreach ($this->targetsFor($post, $comment) as $userId => $kind) {
            $user = $this->users->findById((int) $userId);
            if ($user === null || $user['status'] !== 'active') continue;
            $this->recordInbox((string) $userId, $kind, $postId, $commentId,
                (string) $comment['author_name'], (string) $post['title']);
            $vars = [
                '사이트명' => (string) $this->cms->settings()['site_name'],
                '이름' => (string) $user['display_name'], '글제목' => (string) $post['title'],
                '작성자' => (string) $comment['author_name'],
                '링크' => $this->appUrl . '/posts/' . $postId . '#comment-' . $commentId,
            ];
            $this->notifications->afterCommit(fn () => $this->sendExternal('comment_new', $user, $vars));
        }
    }

    public function recordAccountInbox(string $userId, string $kind, string $siteName): void
    {
        if (!in_array($kind, ['welcome', 'password_changed'], true)) throw DomainError::internal('알 수 없는 계정 알림입니다.');
        $user = $this->users->findById((int) $userId);
        if ($user === null || $user['status'] !== 'active') throw DomainError::notFound('알림 수신자를 찾을 수 없습니다.');
        $this->notifications->create([
            'user_id' => $userId, 'kind' => $kind, 'post_id' => null, 'comment_id' => null,
            'order_id' => null, 'actor_name' => '', 'subject' => $siteName,
        ]);
    }

    public function recordOrderInbox(string $userId, int $orderId, string $status, string $number): void
    {
        $this->notifications->recordOrderStatus($orderId, (int) $userId, $status, $number);
    }

    /** Orders가 이미 필수 알림함을 저장했으므로 이메일·전화 채널만 실행한다. */
    public function notifyOrderChannels(int $userId, int $orderId, string $status, string $number, array $vars = []): void
    {
        if (!\GnuCms\Notify\Events::exists('order_' . $status)) return;
        $user = $this->users->findById($userId);
        if ($user === null || $user['status'] !== 'active') return;
        $this->sendExternal('order_' . $status, $user, [
            '사이트명' => (string) $this->cms->settings()['site_name'],
            '이름' => (string) $user['display_name'], '주문번호' => $number,
            '링크' => $this->appUrl . '/shop/order?number=' . rawurlencode($number),
        ] + $vars);
    }

    public function recordInquiryInbox(string $userId, int $feedbackId, string $productName): void
    {
        if ($this->notifications->ownedInquiry($feedbackId, $userId) === null) {
            throw DomainError::notFound('문의 수신자를 찾을 수 없습니다.');
        }
        $this->notifications->create([
            'user_id' => $userId, 'kind' => 'inquiry_replied', 'post_id' => null, 'comment_id' => null,
            'order_id' => null, 'feedback_id' => $feedbackId, 'actor_name' => '', 'subject' => mb_substr($productName, 0, 200),
        ]);
    }

    /** 문의 답변은 저장 거래 안에서 필수 알림을 적고 외부 채널은 커밋 뒤에 보낸다. */
    public function notifyInquiryReply(array $feedback, array $product): void
    {
        $user = $this->users->findById((int) $feedback['user_id']);
        if ($user === null || $user['status'] !== 'active') return;
        $this->recordInquiryInbox((string) $user['id'], (int) $feedback['id'], (string) $product['name']);
        $vars = ['사이트명' => (string) $this->cms->settings()['site_name'],
            '이름' => (string) $user['display_name'], '상품명' => (string) $product['name'],
            // 링크는 소유권 검사 뒤에 현재 상품과 문의 페이지로 안내한다. 비공개 답변 본문은 싣지 않는다.
            '링크' => $this->appUrl . '/shop/inquiry/' . (int) $feedback['id']];
        $this->notifications->afterCommit(fn () => $this->sendExternal('inquiry_replied', $user, $vars));
    }

    private function sendExternal(string $event, array $user, array $vars): void
    {
        try {
            ($this->notifier)()->notify($event, Recipient::forUser($user), $vars, false);
        } catch (\Throwable $e) {
            ($this->log)('외부 알림 ' . $event . ' 발송 실패: ' . get_class($e));
        }
    }

    /**
     * 이 댓글로 알림을 받을 회원. **회원 번호 => 알림 종류**다.
     *
     * 종류를 여기서 정하는 이유는 이벤트 키로는 알 수 없기 때문이다 — comment_new 하나에
     * 두 가지가 실려 나가고, 회원은 그 둘을 다르게 읽는다("내 글에 댓글" / "내 댓글에
     * 답글"). 한 사람이 글쓴이이면서 부모 댓글 작성자이면 답글 쪽이 이긴다: 번호를 열쇠로
     * 쓰므로 자리가 하나뿐이고, 먼저 담기는 답글이 그 자리를 차지한다. 알림도 한 번만 간다.
     *
     * @return array<string,string>
     */
    private function targetsFor(array $post, array $comment): array
    {
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

        // 내가 쓴 댓글로 나에게 알리지 않는다. 숫자로 된 열쇠는 PHP 가 정수로 접어
        // 두므로 '3' 으로 지워도 3 번 자리가 지워진다.
        $writer = $this->memberId($comment['author_id']);
        if ($writer !== null) {
            unset($targets[$writer]);
        }

        return $targets;
    }

    /**
     * 알림함에 한 줄 적는다. notifyComment() 안에 있던 insert 를 그대로 뗀 것이다.
     *
     * 따로 뗀 이유는 Notify\InboxChannel 이 이것만 부르게 하기 위해서다. 채널이
     * notifyComment() 를 부르면 서로를 돌아 부르게 된다 — 알림함 채널이
     * NotificationService 를, NotificationService 가 다시 Notifier 를(그 안에 알림함
     * 채널이 들어 있다) 부르는 고리다. 이 메서드는 표에 적기만 하므로 그 고리가 생기지
     * 않는다.
     */
    public function recordInbox(string $userId, string $kind, int $postId, ?int $commentId,
        string $actorName, string $subject): void
    {
        $this->notifications->create([
            'user_id'    => $userId,
            'kind'       => $kind,
            'post_id'    => $postId,
            'comment_id' => $commentId,
            'actor_name' => $actorName,
            'subject'    => $subject,
        ]);
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

        if (in_array($row['kind'], ['welcome', 'password_changed'], true)) {
            $this->notifications->markRead($id, $userId);
            return ['account' => true];
        }
        if ($row['feedback_id'] !== null) {
            $target = $this->notifications->ownedInquiry($row['feedback_id'], $userId);
            if ($target === null) throw DomainError::notFound('문의 알림을 찾을 수 없습니다.');
            $this->notifications->markRead($id, $userId);
            return ['inquiry' => $target];
        }
        if ($row['order_id'] !== null) {
            $number = $this->notifications->ownedOrderNumber($row['order_id'], $userId);
            if ($number === null) {
                throw DomainError::notFound('알림을 찾을 수 없습니다.');
            }
            $this->notifications->markRead($id, $userId);
            return ['order_number' => $number];
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
