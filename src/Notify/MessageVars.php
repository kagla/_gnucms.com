<?php

declare(strict_types=1);

namespace GnuCms\Notify;

/**
 * 알림 하나가 들고 다니는 $vars 배열의 경계를 지킨다.
 *
 * 그 배열에는 두 가지가 섞여 있다.
 *
 *  1. **카탈로그 변수** — Events::variables() 가 이벤트마다 선언한 이름(사이트명·이름·
 *     링크 …). 관리자가 문자 본문에 쓰거나 알림톡 템플릿 변수에 이어 붙일 수 있는 값이고,
 *     그래서 실제 메시지 본문에 그대로 나간다.
 *  2. **채널 문맥** — 채널 하나가 일을 하려면 필요하지만 본문에는 절대 나가면 안 되는 값.
 *     알림함이 쓰는 post_id·comment_id·kind 가 그렇다. notify() 의 서명
 *     (event, Recipient, vars)에는 이 값들이 들어올 자리가 없어 $vars 에 얹어 보내되,
 *     밑줄로 시작하는 이름(_post_id)을 써서 카탈로그 변수와 구분한다.
 *
 * 둘이 한 배열에 있으므로, 본문을 만드는 쪽은 **반드시** forBody() 를 거쳐야 한다.
 * 카탈로그에 선언된 이름만 남기는 화이트리스트다 — 밑줄 접두사를 걸러내는 블랙리스트가
 * 아니다. 접두사를 빠뜨린 문맥 키가 새로 생기더라도(사람은 실수한다) 카탈로그에 없는
 * 이름은 어차피 통과하지 못한다. 그래서 #{_post_id} 같은 변수를 담은 본문은 값을 찾지
 * 못해 Variables::apply() 에서 시끄럽게 거절당하지, 조용히 글번호를 문자에 실어 보내지
 * 않는다.
 */
final class MessageVars
{
    /** 채널만 읽는 문맥 값임을 나타내는 접두사. 카탈로그 이름은 모두 한글이라 부딪히지 않는다. */
    public const CONTEXT_PREFIX = '_';

    /**
     * 본문 치환에 쓸 수 있는 값만 남긴다. 카탈로그에 선언된 이름이면서 값이 스칼라인
     * 것뿐이다 — 배열이 들어오면 (string) 캐스팅으로 'Array' 를 만들지 않고 없는 값으로
     * 접어 둔다(Recipient 가 같은 이유로 같은 선택을 한다).
     *
     * @return array<string,string>
     */
    public static function forBody(string $event, array $vars): array
    {
        $body = [];
        foreach (Events::variables($event) as $name) {
            $value = $vars[$name] ?? null;
            if (is_scalar($value)) {
                $body[$name] = (string) $value;
            }
        }

        return $body;
    }

    /**
     * 채널만 읽는 문맥 값 하나. $name 은 접두사를 뺀 이름('post_id')으로 준다.
     *
     * **없는 것과 망가진 것을 같은 값으로 접지 않는다.**
     *   null  — 그런 키가 아예 없다.
     *   false — 키는 있는데 쓸 수 없는 값이다(배열·객체·null 등).
     *   문자열 — 쓸 수 있는 값.
     *
     * 처음에는 둘 다 null 이었는데, 그것이 곧바로 결함이 됐다: 알림함의 댓글번호는
     * "없어도 되는" 값이라 부르는 쪽이 null 을 정상으로 받아들이고, 그래서 배열로 온
     * 망가진 번호가 "안 왔다"로 읽혀 comment_id 가 NULL 인 행이 조용히 쌓였다. 값을
     * 읽는 쪽마다 그 함정을 피해 가게 하는 대신, 여기서 두 사실을 갈라 놓는다 — 앞으로
     * 문맥을 읽는 채널이 늘어도 같은 함정을 물려받지 않는다.
     *
     * @return string|false|null
     */
    public static function context(array $vars, string $name): string|false|null
    {
        $key = self::CONTEXT_PREFIX . $name;
        if (!array_key_exists($key, $vars)) {
            return null;
        }

        return is_scalar($vars[$key]) ? (string) $vars[$key] : false;
    }

    /**
     * 문맥으로 온 행 번호(글번호·댓글번호) 하나. **무엇이 번호인지는 이 메서드가 한 번만
     * 정한다** — posts·comments 의 기본키는 1 부터 올라가는 정수이므로, 번호란 1 이상의
     * 정수이거나 앞자리 0 없는 숫자 문자열뿐이다. 0·'03'·'9번'·true·3.0·배열은 모두
     * "있는데 쓸 수 없음"이다: 어떤 것도 실제 행을 가리키지 못하고, 그런 값으로 알림을
     * 적으면 눌러도 열리지 않는 알림이 남는다.
     *
     * 판단을 값의 PHP 타입보다 앞에 두는 것이 핵심이다. (string) 으로 먼저 캐스팅해
     * 검사하면 true 가 '1'(=1번 글)이 되고 배열은 경고와 함께 뭉개진다 — 같은 "잘못된
     * 번호"가 어떤 타입으로 왔느냐에 따라 다른 결말을 맞는다. 그래서 원래 값을 그대로
     * 본다.
     *
     * @return int|false|null null=없음, false=있는데 번호가 아님, int=번호
     */
    public static function contextId(array $vars, string $name): int|false|null
    {
        $key = self::CONTEXT_PREFIX . $name;
        if (!array_key_exists($key, $vars)) {
            return null;
        }
        $value = $vars[$key];
        if (is_int($value)) {
            return $value >= 1 ? $value : false;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return false;
    }
}
