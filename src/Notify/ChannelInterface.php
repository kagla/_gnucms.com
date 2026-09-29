<?php

declare(strict_types=1);

namespace GnuCms\Notify;

/**
 * 알림 하나를 실제로 내보내는 경로. 메일·알림톡·문자·알림함 넷이 이 계약을 지킨다.
 *
 * available() 과 send() 가 답하는 질문이 다르다.
 *
 *  - **available()** — "이 채널이 지금 이 수신자에게 이 알림을 실제로 전달할 수 있는가."
 *    번호 없는 회원에게 문자, 주소 없는 수신자에게 메일, 손님에게 알림함처럼 **전달할
 *    수단이 없는 경우**와, 보낼 내용·설정이 갖춰지지 않은 경우(문자 본문 미작성, 알림톡
 *    템플릿이 죽음, 알리고 채널 스위치 꺼짐)가 모두 여기서 false 다. 거짓은 **실패가
 *    아니라 건너뛰기**다 — 번호가 없다는 이유로 메일까지 막히면 안 된다.
 *    이 채널을 관리자가 이 이벤트에 **켜 두었는지**는 여기서 묻지 않는다. 그것은
 *    NotifySettings::isOn() 의 질문이고, 채널을 고르는 Notifier 가 묻는다.
 *
 *  - **send()** — 실제 발송. 구현은 모두 맨 앞에서 available() 을 한 번 더 확인하고,
 *    거짓이면 아무것도 보내지 않고 거절한다. available() 을 묻지 않고 send() 를 부른
 *    호출자가 조용히 빈 메시지를 내보내는 일이 없게 하기 위해서다 — 건너뛰기는
 *    available() 을 물어본 쪽만 누릴 수 있고, 묻지 않았다면 그것은 결함이다.
 *
 * $vars 에는 카탈로그 변수(Events::variables())와 채널 문맥(_post_id 같은)이 섞여 들어
 * 온다. 본문을 만드는 채널은 반드시 MessageVars::forBody() 를 거쳐 카탈로그 변수만
 * 쓴다 — 그 이유와 규칙은 MessageVars 주석에 적혀 있다.
 */
interface ChannelInterface
{
    public function key(): string;

    /** 보낼 수 없으면 false. 실패가 아니라 건너뛰기다. */
    public function available(string $event, Recipient $to): bool;

    public function send(string $event, Recipient $to, array $vars): void;
}
