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
 * 사이트 안 알림함, 그리고 댓글 알림이 채널로 나가는 자리.
 *
 * 회원에게만 알린다. 비회원 글·댓글은 받을 사람을 특정할 수 없기 때문이다.
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

    private UserRepository $users;

    private CmsService $cms;

    private string $appUrl;

    /** @var \Closure(): Notifier */
    private \Closure $notifier;

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
     */
    public function __construct(
        NotificationRepository $notifications,
        PostRepository $posts,
        CommentRepository $comments,
        UserRepository $users,
        CmsService $cms,
        string $appUrl,
        callable $notifier
    ) {
        $this->notifications = $notifications;
        $this->posts = $posts;
        $this->comments = $comments;
        $this->users = $users;
        $this->cms = $cms;
        $this->appUrl = rtrim($appUrl, '/');
        $this->notifier = \Closure::fromCallable($notifier);
    }

    /**
     * 새 댓글이 달렸을 때 알린다.
     *
     * 받을 사람은 두 갈래다. 글쓴이에게는 "내 글에 댓글", 답글이면 부모 댓글
     * 작성자에게 "내 댓글에 답글". 둘이 같은 사람이면 한 번만 보낸다.
     *
     * **한 사람에 한 번씩 부른다.** notify() 는 수신자 하나를 받으므로 받을 사람이
     * 둘이면 두 번이다. 그래서 둘은 서로의 결과를 모른다 — 한 사람에게 실패해도 다른
     * 사람은 받아야 하고(그 사람 잘못이 아니다), 문자·알림톡이 켜져 있으면 관리자
     * 발송 내역(message_jobs)에도 사람마다 한 건씩 남는다. 번호도 내용의 '이름'도
     * 사람마다 다르니 그것이 맞는 모양이다.
     *
     * **무엇이 실패해도 댓글 등록은 막지 않는다.** 이 메서드는 댓글이 이미 저장된
     * 뒤에 불린다(CommentService::create). 여기서 예외가 올라가면 화면은 오류가 되는데
     * 댓글은 남아 있어, 사람은 같은 댓글을 한 번 더 쓴다. 발송기가 전부 실패로 올리는
     * 예외(Notifier 주석)도 여기서 멈춘다 — 대신 로그에 남는다.
     */
    public function notifyComment(int $postId, int $commentId): void
    {
        $comment = $this->comments->find($commentId);
        $post = $this->posts->find($postId);
        if ($comment === null || $post === null) {
            return;
        }

        $targets = $this->targetsFor($post, $comment);
        if ($targets === []) {
            return;
        }

        $siteName = (string) $this->cms->settings()['site_name'];
        // 알림함이 알림을 눌렀을 때 보내는 곳과 같은 자리다(NotificationController::open).
        $link = $this->appUrl . '/posts/' . $postId . '#comment-' . $commentId;

        foreach ($targets as $userId => $kind) {
            $user = $this->users->findById((int) $userId);
            // 차단·탈퇴 회원은 없는 회원과 같게 다룬다 — 이 저장소의 원칙이다
            // (CommentService::listByAuthor, PostService, UserRepository::searchActive).
            // 로그인을 막아 둔 사람의 알림함에 쌓아 봐야 읽을 사람이 없고, 메일·문자로
            // 보내면 내보낸 사람에게 사이트가 계속 말을 거는 꼴이 된다. 탈퇴 회원은
            // 이름·주소가 이미 익명으로 바뀌어 있어 보낼 곳도 없다.
            if ($user === null || (string) $user['status'] !== 'active') {
                continue;
            }
            try {
                ($this->notifier)()->notify('comment_new', Recipient::forUser($user), [
                    '사이트명' => $siteName,
                    '이름'    => (string) $user['display_name'],
                    '글제목'   => (string) $post['title'],
                    '작성자'   => (string) $comment['author_name'],
                    '링크'    => $link,
                    // 채널만 읽는 문맥. 알림함이 두 종류를 구분해 적으려면 이 셋이
                    // 필요한데 notify() 의 서명에는 들어갈 자리가 없다. 밑줄로 시작하는
                    // 이름이라 어떤 본문에도 치환되지 않는다(MessageVars 주석).
                    MessageVars::CONTEXT_PREFIX . 'kind'       => $kind,
                    MessageVars::CONTEXT_PREFIX . 'post_id'    => $postId,
                    MessageVars::CONTEXT_PREFIX . 'comment_id' => $commentId,
                ]);
            } catch (\Throwable $e) {
                // 사람 하나에서 멈추지 않는다. 이 자리에서 바로 적는다 — 뒤 사람을
                // 보내다 프로세스가 죽으면 모아 둔 기록은 함께 사라진다(Notifier 와 같은 이유).
                error_log('[' . GNUCMS_ID . '] 댓글 알림(글 ' . $postId . ')을 보내지 못했습니다 — '
                    . get_class($e) . ': ' . $e->getMessage());
            }
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
