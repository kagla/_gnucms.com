<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Error\DomainError;

/**
 * 이벤트마다 기본 메일 제목·본문을 만든다. 관리자 편집 전에는 기존 코어 문구를 쓰고,
 * 편집 시에는 같은 문구의 변수 표기를 MailEditor에 제공한다.
 *
 * **다섯 통은 옮겨 온 것이다.** 이 브랜치 이전에 signup_attempt 는
 * AccountService::register() 가, password_reset 은 requestPasswordReset() 이,
 * password_changed 는 notifyPasswordChanged() 가, email_verify 는 sendVerification() 이,
 * social_email_verify 는 SocialAuthService::sendPendingEmail() 이 직접 메일러로
 * 보내고 있었다. 그 문구를 **한 글자도 바꾸지 않고** 여기로 옮겼고, 그 자리들은 이제
 * Notifier 를 부른다. 링크·유효시간·일시처럼 호출 자리에서 만들던 값만 카탈로그
 * 변수로 빼냈으므로, 같은 값을 넣으면 결과 문자열은 옮겨 오기 전과 바이트 단위로 같다.
 * MailBodiesTest 가 그 문자열을 그대로 박아 두고 지킨다.
 *
 * 그 값들을 어떤 모양으로 넣어야 옮겨 오기 전과 같아지는지는 이렇다 — 호출 자리는
 * 지금 이 모양으로 넣고 있다:
 *   - 유효시간: '1시간'·'24시간'·'30분'처럼 **단위까지 포함한** 문자열. 본문은
 *     "이 링크는 {유효시간} 동안 유효합니다"로 쓴다.
 *   - 일시: DateTimeDisplay의 공통 형식 뒤에 ' (UTC)'까지 붙인 **화면에 보일 그대로**의 문자열.
 *     본문은 "변경 시각: {일시}"로 끝난다 — 시간대 표기를 본문이 아니라 값이 들고
 *     있게 한 이유는, 같은 값이 문자·알림톡으로도 나갈 때 "03:04:05"만 덩그러니
 *     남으면 어느 시간대인지 알 수 없기 때문이다.
 *   - 링크: 이벤트마다 지금 코드가 만들던 주소 그대로(재설정 링크, /forgot-password,
 *     인증 링크, /login, /auth/complete).
 *
 * **두 통은 새로 썼다.** welcome 은 코어에 없던 알림이고, comment_new 는 지금까지
 * 알림함으로만 나가던 알림이라 메일 문구 자체가 없었다. 관리자가 그 둘의 메일을 켤 수
 * 있으므로(NotifySettings 는 메일을 막지 않는다) 본문이 반드시 있어야 한다 — 일곱
 * 이벤트 전부에 본문이 있다는 것을 테스트가 지킨다.
 *
 * comment_new 은 알림함의 두 종류(내 글의 댓글 / 내 댓글의 답글)를 구분하지 않는다.
 * 그 구분은 채널 문맥(_kind)이지 카탈로그 변수가 아니고, 메일에는 어느 쪽이든 어색하지
 * 않게 읽히는 한 가지 문구를 쓴다.
 */
final class MailBodies
{
    /** 기존 문구에 #{변수}를 채워 편집 화면의 기본 템플릿을 만든다. */
    public static function defaults(string $event): array
    {
        $markers = [];
        foreach (Events::variables($event) as $name) {
            $markers[$name] = '#{' . $name . '}';
        }
        return self::render($event, $markers);
    }

    /** 이 이벤트로 메일을 보낼 수 있는가. MailChannel::available() 이 먼저 묻는다. */
    public static function has(string $event): bool
    {
        return isset(self::templates()[$event]);
    }

    /**
     * @return array{subject:string,body:string}
     */
    public static function render(string $event, array $vars): array
    {
        $template = self::templates()[$event] ?? null;
        if ($template === null) {
            throw DomainError::validation(['event' => '메일 본문이 없는 알림입니다: ' . $event]);
        }

        // 카탈로그에 선언된 변수만 본문에 닿는다(MessageVars 주석 참고). 빠진 값은
        // 빈 문자열로 채운다 — 값 하나가 없다고 비밀번호 재설정 메일을 통째로
        // 못 보내는 것이 더 나쁘다. 문자·알림톡은 반대로 시끄럽게 거절한다.
        $v = MessageVars::forBody($event, $vars) + array_fill_keys(Events::variables($event), '');

        return $template($v);
    }

    /**
     * 이벤트 하나당 닫힌 함수 하나. has() 와 render() 가 같은 목록을 보게 해서 "본문은
     * 있는데 has() 가 모른다"는 어긋남이 생길 수 없게 한다.
     *
     * @return array<string,callable(array<string,string>):array{subject:string,body:string}>
     */
    private static function templates(): array
    {
        return [
            'password_reset' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 비밀번호 재설정',
                'body' => "아래 링크에서 비밀번호를 다시 설정해 주세요.\n\n{$v['링크']}\n\n"
                    . "이 링크는 {$v['유효시간']} 동안 유효합니다.",
            ],
            'password_changed' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 비밀번호가 변경되었습니다',
                'body' => "회원님의 비밀번호가 방금 변경되었습니다.\n\n"
                    . "본인이 바꾼 것이 아니라면 아래에서 즉시 비밀번호를 다시 설정하세요.\n\n"
                    . "{$v['링크']}\n\n변경 시각: {$v['일시']}",
            ],
            'order_pending' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 주문 접수 안내',
                'body' => "{$v['이름']}님, 주문 {$v['주문번호']}가 접수되었습니다. 주문금액 {$v['주문금액']}\n\n{$v['링크']}",
            ],
            'order_paid' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 결제 확인 안내',
                'body' => "{$v['이름']}님, 주문 {$v['주문번호']}의 결제 {$v['결제금액']}이 확인되었습니다.\n\n{$v['링크']}",
            ],
            'order_cancelled' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 주문 취소 안내',
                'body' => "{$v['이름']}님, 주문 {$v['주문번호']}가 취소되었습니다. 환불 내역은 주문 조회에서 확인해 주세요.\n\n{$v['링크']}",
            ],
            'order_refunded' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 환불 처리 안내',
                'body' => "{$v['이름']}님, 주문 {$v['주문번호']}의 환불 {$v['환불금액']}이 처리되었습니다.\n\n{$v['링크']}",
            ],
            'inquiry_replied' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 상품문의 답변 안내',
                'body' => "{$v['이름']}님, 「{$v['상품명']}」 상품문의에 답변이 등록되었습니다.\n\n{$v['링크']}",
            ],
            'order_confirmed' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 주문 상품 준비 안내',
                'body' => "{$v['이름']}님, 주문 {$v['주문번호']}의 상품을 준비 중입니다.\n\n{$v['링크']}",
            ],
            'order_shipped' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 주문 배송 안내',
                'body' => "{$v['이름']}님, 주문 {$v['주문번호']}의 상품이 배송 중입니다.\n\n{$v['링크']}",
            ],
            'order_completed' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 주문 배송 완료 안내',
                'body' => "{$v['이름']}님, 주문 {$v['주문번호']}의 배송이 완료되었습니다.\n\n{$v['링크']}",
            ],
            'order_returning' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 반품 요청 접수',
                'body' => "{$v['이름']}님, 주문 {$v['주문번호']}의 반품 요청이 접수되었습니다.\n{$v['사유']}\n수거 일정은 상점에서 안내합니다.\n\n{$v['링크']}",
            ],
            'order_returned' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 반품 완료 안내',
                'body' => "{$v['이름']}님, 주문 {$v['주문번호']}의 반품이 완료되었습니다. 환불금액 {$v['환불금액']}\n결제수단에 따라 실제 환급까지 시간이 소요될 수 있습니다.\n\n{$v['링크']}",
            ],
            'order_return_closed' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 반품 요청 종료',
                'body' => "{$v['이름']}님, 주문 {$v['주문번호']}의 반품 요청이 종료되었습니다.\n{$v['사유']}\n\n{$v['링크']}",
            ],
            'email_verify' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 이메일 인증',
                'body' => "{$v['사이트명']} 가입을 완료하려면 아래 링크를 열어 주세요.\n\n"
                    . "{$v['링크']}\n\n이 링크는 {$v['유효시간']} 동안 유효합니다.",
            ],
            'signup_attempt' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 가입 시도 안내',
                'body' => "이미 가입된 계정입니다.\n\n로그인: {$v['링크']}",
            ],
            'social_email_verify' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 소셜 로그인 이메일 확인',
                'body' => "소셜 로그인을 완료하려면 아래 링크를 열어 주세요.\n\n"
                    . "{$v['링크']}\n\n이 링크는 {$v['유효시간']} 동안 유효합니다.",
            ],
            'welcome' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 가입을 환영합니다',
                'body' => $v['이름'] . "님, " . $v['사이트명'] . " 가입이 끝났습니다.\n\n"
                    . "이제 로그인해서 이용하실 수 있습니다.",
            ],
            'comment_new' => static fn (array $v): array => [
                'subject' => '[' . $v['사이트명'] . '] 새 댓글이 달렸습니다',
                'body' => $v['이름'] . "님, {$v['작성자']}님이 「{$v['글제목']}」 글에 댓글을 남겼습니다.\n\n"
                    . "{$v['링크']}",
            ],
        ];
    }
}
