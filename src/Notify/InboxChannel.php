<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Error\DomainError;
use GnuCms\Service\NotificationService;

/**
 * 사이트 내 알림함 채널. 기록은 예전부터 그 일을 하던 NotificationService 가 그대로
 * 한다 — 이 채널은 알림 하나를 그 표의 한 줄로 옮길 뿐이다.
 *
 * **왜 만들어진 서비스가 아니라 callable 을 쥐는가.** App 의 게터들은 생성자 주입 +
 * 지연 메모이즈다(App::notifier() 가 만든 값을 대입하는 것은 new 가 끝난 **뒤**다).
 * 그래서 notifier() → new Notifier(..., [InboxChannel]) → InboxChannel 이
 * notificationService() 를 요구 → 그 게터가 다시 notifier() 를 요구 → 아직 null →
 * 무한 재귀가 된다. 발송 시점에야 서비스를 만들면 notifier() 가 먼저 끝나 메모이즈되고,
 * 그 뒤에 알림함 서비스가 만들어진다. 이 코드베이스가 늦은 배선에 이미 쓰는 방식
 * (setWriteRateLimiter 같은 세터)과 같은 이유의 같은 해법이다.
 *
 * **왜 종류(kind)를 문맥에서 받는가.** 알림함은 두 종류를 구분해 보여 준다 — 내 글에
 * 달린 댓글(KIND_COMMENT)과 내 댓글에 달린 답글(KIND_REPLY). 회원에게 다르게 읽히는,
 * 지금 살아 있는 기능이다. 그런데 이벤트는 comment_new 하나뿐이라 둘 중 무엇인지는
 * 이벤트 키로 알 수 없다. 채널이 한쪽으로 뭉개면 "리팩터링"을 가장한 기능 퇴화가 되므로,
 * 부르는 쪽이 _kind 로 알려 주고 채널은 그것을 그대로 받는다. 모르는 종류는 적지 않고
 * 거절한다 — 화면이 고르는 문구가 틀리는 것보다 낫다.
 *
 * $vars 에 실려 오는 문맥(_kind·_post_id·_comment_id)은 카탈로그 변수가 아니라서 어떤
 * 본문에도 치환되지 않는다(MessageVars 주석 참고).
 */
final class InboxChannel implements ChannelInterface
{
    /** @var callable(): NotificationService */
    private $notifications;

    /** @param callable(): NotificationService $notifications 발송 시점에 부른다 */
    public function __construct(callable $notifications)
    {
        $this->notifications = $notifications;
    }

    public function key(): string
    {
        return 'inbox';
    }

    /**
     * 알림함에 쌓을 수 있는 알림이 무엇인지는 카탈로그(Events 의 inbox)가 정한다. 여기에
     * 이벤트 이름을 따로 적어 두면 설정이 켤 수 있는 것과 채널이 받는 것이 어긋날 수
     * 있다 — 문자·알림톡이 Events::phoneCapable() 을 다시 확인하는 것과 같은 자리다.
     *
     * 알림함은 회원의 것이다 — 손님은 쌓아 둘 곳이 없다. 문맥(_kind 등)이 갖춰졌는지는
     * 여기서 보지 않는다: 문맥이 빠진 것은 "이 수신자에게 전할 수단이 없다"가 아니라
     * 부르는 쪽의 결함이고, 건너뛰기로 감추면 알림함만 조용히 비어 간다. 그 경우는
     * send() 가 시끄럽게 거절한다.
     */
    public function available(string $event, Recipient $to): bool
    {
        return $to->userId !== null && Events::inboxCapable($event);
    }

    public function send(string $event, Recipient $to, array $vars): void
    {
        if (!$this->available($event, $to)) {
            throw DomainError::validation(['inbox' => '알림함에 넣을 수 없는 알림입니다.']);
        }

        $kind = MessageVars::context($vars, 'kind');
        $postId = MessageVars::contextId($vars, 'post_id');
        $commentId = MessageVars::contextId($vars, 'comment_id');

        // 세 칸의 요구가 저마다 다르지만, "잘못 왔다"는 어느 칸에서든 똑같이 거절이다.
        //   종류    — 아는 둘 중 하나여야 한다. 없거나(null) 쓸 수 없으면(false) 둘 다 거절.
        //   글번호  — 반드시 있어야 하고 진짜 번호여야 한다(is_int 가 null·false 를 함께 막는다).
        //   댓글번호 — **없어도 된다**(글 전체에 대한 알림). 하지만 왔는데 번호가 아니면
        //             거절한다 — 없는 것과 망가진 것은 다른 사실이고, 망가진 것을 NULL 로
        //             적으면 "댓글 없는 알림"과 구별되지 않은 채 조용히 남는다.
        //             MessageVars 가 그 둘을 null·false 로 갈라 주기에 여기서 물어볼 수 있다.
        if (!in_array($kind, [NotificationService::KIND_COMMENT, NotificationService::KIND_REPLY], true)
            || !is_int($postId)
            || $commentId === false) {
            throw DomainError::validation(['inbox' =>
                '알림함에 적을 값이 모자라거나 잘못됐습니다(종류·글번호·댓글번호).']);
        }
        $values = MessageVars::forBody($event, $vars);

        ($this->notifications)()->recordInbox(
            (string) $to->userId,
            $kind,
            $postId,
            $commentId,
            $values['작성자'] ?? '',
            $values['글제목'] ?? ''
        );
    }
}
