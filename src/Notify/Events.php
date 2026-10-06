<?php

declare(strict_types=1);

namespace GnuCms\Notify;

/** 코어 알림 카탈로그. 알림함 대응 이벤트는 필수이며 외부 채널은 관리자 선택이다.
 * 인증·계정 복구처럼 로그인 전에 받는 링크는 알림함에 넣지 않는다.
 * secret 변수는 전화 발송 이력에서 가려야 할 일회용 비밀이다.
 */
final class Events
{
    public const ALL = [
        'password_reset' => ['label' => '비밀번호 재설정',
            'vars' => ['사이트명', '이름', '링크', '유효시간', '문의처', '사이트주소'], 'phone' => true, 'inbox' => false,
            'secret' => ['링크'],
            'sms_body' => '[#{사이트명}] 비밀번호 재설정 (#{유효시간})
#{링크}'],
        'password_changed' => ['label' => '비밀번호 변경 안내',
            'vars' => ['사이트명', '이름', '일시', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true,
            // 이 링크는 /forgot-password 다 — 누구나 열 수 있는 화면이고 토큰이 없다.
            'secret' => [],
            'sms_body' => '[#{사이트명}] 비밀번호 변경. 본인이 아니면 재설정
#{링크}'],
        'welcome' => ['label' => '회원가입 완료 안내',
            'vars' => ['사이트명', '이름', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true, 'secret' => [],
            'sms_body' => '[#{사이트명}] 회원가입 완료. 환영합니다.'],
        'comment_new' => ['label' => '새 댓글·답글',
            'vars' => ['사이트명', '이름', '글제목', '작성자', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true,
            'secret' => [],
            'sms_body' => '[#{사이트명}] 새 댓글·답글이 등록됐습니다.
#{링크}'],
        'order_pending' => ['label' => '주문 접수',
            'vars' => ['사이트명', '이름', '주문번호', '주문금액', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true, 'secret' => [],
            'sms_body' => '[#{사이트명}] #{주문번호} 주문접수
#{링크}'],
        'order_paid' => ['label' => '결제 완료·입금 확인',
            'vars' => ['사이트명', '이름', '주문번호', '결제금액', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true, 'secret' => [],
            'sms_body' => '[#{사이트명}] #{주문번호} 결제완료
#{링크}'],
        'order_cancelled' => ['label' => '주문 취소',
            'vars' => ['사이트명', '이름', '주문번호', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true, 'secret' => [],
            'sms_body' => '[#{사이트명}] #{주문번호} 주문취소
#{링크}'],
        'order_refunded' => ['label' => '환불 처리',
            'vars' => ['사이트명', '이름', '주문번호', '환불금액', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true, 'secret' => [],
            'sms_body' => '[#{사이트명}] #{주문번호} 환불처리
#{링크}'],
        'inquiry_replied' => ['label' => '상품문의 답변',
            'vars' => ['사이트명', '이름', '상품명', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true, 'secret' => [],
            'sms_body' => '[#{사이트명}] 상품문의 답변이 등록됐습니다.
#{링크}'],
        'order_confirmed' => ['label' => '주문 상품 준비',
            'vars' => ['사이트명', '이름', '주문번호', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true, 'secret' => [],
            'sms_body' => '[#{사이트명}] #{주문번호} 상품준비
#{링크}'],
        'order_shipped' => ['label' => '주문 배송 중',
            'vars' => ['사이트명', '이름', '주문번호', '택배사', '운송장번호', '배송정보', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true, 'secret' => [],
            'sms_body' => '[#{사이트명}] #{주문번호} 배송중
#{링크}'],
        'order_completed' => ['label' => '주문 배송 완료',
            'vars' => ['사이트명', '이름', '주문번호', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true, 'secret' => [],
            'sms_body' => '[#{사이트명}] #{주문번호} 배송완료
#{링크}'],
        'order_returning' => ['label' => '반품 요청',
            'vars' => ['사이트명', '이름', '주문번호', '사유', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true, 'secret' => [],
            'sms_body' => '[#{사이트명}] #{주문번호} 반품접수
#{링크}'],
        'order_returned' => ['label' => '반품 완료·환불',
            'vars' => ['사이트명', '이름', '주문번호', '환불금액', '링크', '문의처', '사이트주소'], 'phone' => true, 'inbox' => true, 'secret' => [],
            'sms_body' => '[#{사이트명}] #{주문번호} 반품완료
#{링크}'],
        'order_return_closed' => ['label' => '반품 요청 종료',
            'vars' => ['사이트명', '이름', '주문번호', '사유', '링크'], 'phone' => false, 'inbox' => true, 'secret' => []],
        'email_verify' => ['label' => '이메일 인증',
            'vars' => ['사이트명', '이름', '링크', '유효시간'], 'phone' => false, 'inbox' => false,
            'secret' => ['링크']],
        'signup_attempt' => ['label' => '회원가입 시도 안내',
            'vars' => ['사이트명', '링크'], 'phone' => false, 'inbox' => false, 'secret' => []],
        'social_email_verify' => ['label' => '소셜 로그인 이메일 확인',
            'vars' => ['사이트명', '링크', '유효시간'], 'phone' => false, 'inbox' => false,
            'secret' => ['링크']],
    ];

    /** 관리자 검색에서 회원 기능 분류와 화면 제목의 다른 표현도 찾는다. */
    public static function searchKeywords(string $key): string
    {
        $aliases = match ($key) {
            'password_reset' => '비밀번호 찾기 비번 찾기 비밀번호 분실 비밀번호 재설정 암호 찾기 계정 복구 password reset forgot password',
            'password_changed' => '비밀번호 변경 비번 변경 암호 변경 계정 보안 password changed',
            'welcome' => '회원 가입 가입 완료 환영 신규 회원 welcome signup',
            'email_verify' => '가입 인증 회원 인증 이메일 인증 메일 인증 email verify',
            'signup_attempt' => '가입 시도 중복 가입 이미 가입 signup attempt',
            'social_email_verify' => '소셜 로그인 간편 로그인 SNS 로그인 이메일 확인 OAuth social email verify',
            default => '',
        };

        return $aliases === '' ? '' : '회원 회원가입 회원정보 계정 member account ' . $aliases;
    }

    /** 관리자에게 실제 발생 시점과 수신 대상을 설명한다. */
    public static function guidance(string $key): array
    {
        $trigger = match ($key) {
            'password_reset' => '등록된 계정의 비밀번호 재설정을 요청할 때',
            'password_changed' => '회원이 비밀번호를 변경하거나 재설정을 완료할 때',
            'welcome' => '가입 또는 이메일 인증을 마쳐 활성 회원이 될 때',
            'comment_new' => '다른 회원이 내 글에 댓글 또는 내 댓글에 답글을 저장할 때',
            'order_pending' => '새 주문이 처음 접수될 때. 같은 주문서 재요청에는 발송하지 않습니다.',
            'order_paid' => '결제 승인 또는 무통장 입금 확인을 처음 기록할 때',
            'order_confirmed' => '주문 상태가 상품 준비로 변경될 때',
            'order_shipped' => '주문 상태가 배송 중으로 변경될 때. 배송 CSV도 같은 규칙을 사용합니다.',
            'order_completed' => '주문 상태가 배송 완료로 변경될 때',
            'order_cancelled' => '고객·관리자 취소 또는 결제 기한 만료로 주문 취소가 확정될 때',
            'order_returning' => '배송 중·완료 주문의 전체 반품을 요청할 때',
            'order_returned' => '상품 회수 확인 뒤 잔액 환불과 반품을 완료할 때. 환불 안내는 이 알림에 함께 보냅니다.',
            'order_return_closed' => '회원이 요청을 취소하거나 관리자가 반품 요청을 종료할 때',
            'order_refunded' => '새 환불 기록이 저장될 때. 부분 환불은 건별로 안내하며 같은 환불 키는 중복 발송하지 않습니다.',
            'inquiry_replied' => '상품문의에 첫 답변을 등록하거나 답변 내용을 변경할 때. 동일 내용 저장·답변 삭제에는 발송하지 않습니다.',
            'email_verify' => '이메일 가입 인증 링크를 요청할 때',
            'signup_attempt' => '이미 가입된 이메일로 가입을 시도할 때',
            'social_email_verify' => '소셜 로그인 이메일 확인이 필요할 때',
            default => '',
        };
        $target = str_starts_with($key, 'order_') ? '활성 주문 회원의 주문자 연락처. 배송지 수령인에게는 보내지 않습니다.'
            : ($key === 'inquiry_replied' ? '상품문의를 작성한 활성 회원의 계정 연락처'
            : ($key === 'comment_new' ? '글 작성자·부모 댓글 작성자, 최대 두 명. 작성자 본인은 제외합니다.'
            : (self::phoneCapable($key) ? '해당 회원의 계정 연락처' : '해당 요청에서 확인한 이메일 주소')));
        return ['trigger' => $trigger, 'target' => $target];
    }

    /** 직접 요청한 계정 인증·복구 메일 이외의 이메일 알림은 회원이 수신거부할 수 있다. */
    public static function subscriptionMail(string $key): bool
    {
        return self::exists($key) && !in_array($key,
            ['password_reset', 'email_verify', 'social_email_verify', 'signup_attempt'], true);
    }

    public static function exists(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    /** @return array<string,string> */
    public static function labels(): array
    {
        return array_map(static fn (array $event): string => $event['label'], self::ALL);
    }

    /** @return list<string> */
    public static function variables(string $key): array
    {
        return self::ALL[$key]['vars'] ?? [];
    }

    public static function phoneCapable(string $key): bool
    {
        return (bool) (self::ALL[$key]['phone'] ?? false);
    }

    /** 관리자가 아직 문자 본문을 저장하지 않았을 때 보여 줄 알림별 기본 문구. */
    public static function defaultSmsBody(string $key): string
    {
        return (string) (self::ALL[$key]['sms_body'] ?? '');
    }

    /** 이 알림을 사이트 내 알림함에 쌓을 수 있는가. 무엇을 뜻하는지는 클래스 주석에 있다. */
    public static function inboxCapable(string $key): bool
    {
        return (bool) (self::ALL[$key]['inbox'] ?? false);
    }

    /**
     * 이 알림의 변수 가운데 **한 번 쓰는 비밀**을 담은 것. 지금은 토큰이 박힌 링크 셋
     * (비밀번호 재설정·이메일 인증·소셜 이메일 확인)뿐이다. 무엇을 뜻하고 무엇을 하지
     * 않는지는 클래스 주석에 있다.
     *
     * @return list<string>
     */
    public static function secretVars(string $key): array
    {
        return self::ALL[$key]['secret'] ?? [];
    }
}
