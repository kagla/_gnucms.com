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
 *
 * **secret 은 "이 변수의 값이 한 번 쓰는 비밀인가"다.** 비밀번호 재설정 링크에는
 * 그 링크를 가진 사람이면 누구나 계정을 가져갈 수 있는 토큰이 박혀 있다. 코어는 그
 * 토큰을 되돌릴 수 있는 형태로 **어디에도 저장하지 않기로** 한 지 오래다
 * (TokenService::issue() 는 sha256 만 남긴다). 그런데 그 링크를 문자·알림톡으로 보내면
 * 치환이 끝난 수신자 본문이 message_recipients.body 에 그대로 적혀, 백업마다 평문
 * 토큰이 따라다니게 된다 — 다른 층에서 내린 보안 결정을 발송 층이 조용히 되돌린 것이다.
 * 그래서 전화 채널은 이 목록을 발송 요청에 실어 보내고, Dispatch 는 **표에 남길 사본**
 * 에서만 그 값을 가린다(실제로 나가는 본문은 그대로다). 메일은 저장하지 않으므로
 * 해당이 없고, 알림함은 카탈로그 변수 중 작성자·글제목만 적는다.
 *
 * 새 알림을 더할 때: 값이 링크든 숫자든, **그것을 아는 사람이 무언가를 할 수 있게
 * 되는 값**이면 여기에 적는다. 적지 않으면 아무 경고 없이 그대로 저장된다.
 */
final class Events
{
    public const ALL = [
        'password_reset' => ['label' => '비밀번호 재설정',
            'vars' => ['사이트명', '이름', '링크', '유효시간'], 'phone' => true, 'inbox' => false,
            'secret' => ['링크']],
        'password_changed' => ['label' => '비밀번호 변경 안내',
            'vars' => ['사이트명', '이름', '일시', '링크'], 'phone' => true, 'inbox' => false,
            // 이 링크는 /forgot-password 다 — 누구나 열 수 있는 화면이고 토큰이 없다.
            'secret' => []],
        'welcome' => ['label' => '가입 완료 안내',
            'vars' => ['사이트명', '이름'], 'phone' => true, 'inbox' => false, 'secret' => []],
        'comment_new' => ['label' => '새 댓글·답글',
            'vars' => ['사이트명', '이름', '글제목', '작성자', '링크'], 'phone' => true, 'inbox' => true,
            'secret' => []],
        'email_verify' => ['label' => '이메일 인증',
            'vars' => ['사이트명', '이름', '링크', '유효시간'], 'phone' => false, 'inbox' => false,
            'secret' => ['링크']],
        'signup_attempt' => ['label' => '가입 시도 안내',
            'vars' => ['사이트명', '링크'], 'phone' => false, 'inbox' => false, 'secret' => []],
        'social_email_verify' => ['label' => '소셜 로그인 이메일 확인',
            'vars' => ['사이트명', '링크', '유효시간'], 'phone' => false, 'inbox' => false,
            'secret' => ['링크']],
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
