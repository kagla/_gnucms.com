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
     * 없거나 스칼라가 아니면 null — 부르는 쪽이 "없다"를 한 가지 모양으로만 보게 한다.
     */
    public static function context(array $vars, string $name): ?string
    {
        $value = $vars[self::CONTEXT_PREFIX . $name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
