<?php

declare(strict_types=1);

namespace GnuCms\Notify;

/**
 * 코어가 보내는 알림의 고정 목록. phone 이 false 인 것은 수신자가 이메일로만 식별되거나
 * 이메일 확인 자체가 목적이라 알림톡·문자로 보낼 수 없다.
 *
 * **inbox 는 "이 알림을 사이트 안 알림함에 쌓을 수 있는가"다.** 두 가지가 모두 참일 때만
 * 참이고, 새 알림을 더할 때는 반드시 이 차례로 따진다.
 *
 *  1. **받는 사람이 로그인할 수 있다고 기대할 수 있는가.** 알림함은 로그인해야 열린다.
 *     이메일 인증·비밀번호 재설정·소셜 로그인 확인·가입 시도 안내는 바로 그 로그인을
 *     **하지 못하는 사람**에게 가는 알림이므로, 알림함에 쌓아 봐야 읽을 사람이 없다.
 *     이 조건이 먼저인 이유는 뒤집을 수 없기 때문이다 — 알림함 채널이 나중에 무엇을 더
 *     적을 줄 알게 되더라도 이 넷은 영영 알림함에 넣어서는 안 된다.
 *  2. **알림함 채널이 그 알림을 적을 줄 아는가.** InboxChannel 은 알림 한 건을
 *     notifications 표의 한 줄로 옮기는데, 그러려면 글번호·댓글번호·종류라는 문맥이
 *     필요하다. 지금 그 문맥을 들고 오는 알림은 comment_new 하나뿐이다.
 *
 * 그래서 지금은 comment_new 하나만 참이다. 1 은 통과하지만 2 가 아직인 알림(welcome·
 * password_changed)이 있는데, 그 둘을 참으로 바꾸려면 **깃발만 뒤집어서는 안 되고**
 * InboxChannel 이 그 알림을 적을 줄 알게 만들어야 한다. 깃발만 뒤집으면 관리자 화면에는
 * 켤 수 있는 칸이 생기는데 발송 때마다 그 채널만 실패한다.
 *
 * 이 깃발은 phone 과 똑같이 쓰인다: NotifySettings::save() 가 거절하고, channelsFor() 가
 * 저장소에 남은 값을 걸러내고, 채널 자신이 available() 에서 다시 확인한다.
 */
final class Events
{
    public const ALL = [
        'password_reset' => ['label' => '비밀번호 재설정',
            'vars' => ['사이트명', '이름', '링크', '유효시간'], 'phone' => true, 'inbox' => false],
        'password_changed' => ['label' => '비밀번호 변경 안내',
            'vars' => ['사이트명', '이름', '일시', '링크'], 'phone' => true, 'inbox' => false],
        'welcome' => ['label' => '가입 완료 안내',
            'vars' => ['사이트명', '이름'], 'phone' => true, 'inbox' => false],
        'comment_new' => ['label' => '새 댓글·답글',
            'vars' => ['사이트명', '이름', '글제목', '작성자', '링크'], 'phone' => true, 'inbox' => true],
        'email_verify' => ['label' => '이메일 인증',
            'vars' => ['사이트명', '이름', '링크', '유효시간'], 'phone' => false, 'inbox' => false],
        'signup_attempt' => ['label' => '가입 시도 안내',
            'vars' => ['사이트명', '링크'], 'phone' => false, 'inbox' => false],
        'social_email_verify' => ['label' => '소셜 로그인 이메일 확인',
            'vars' => ['사이트명', '링크', '유효시간'], 'phone' => false, 'inbox' => false],
    ];

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

    /** 이 알림을 사이트 안 알림함에 쌓을 수 있는가. 무엇을 뜻하는지는 클래스 주석에 있다. */
    public static function inboxCapable(string $key): bool
    {
        return (bool) (self::ALL[$key]['inbox'] ?? false);
    }
}
