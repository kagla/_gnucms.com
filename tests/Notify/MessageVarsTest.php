<?php

declare(strict_types=1);

namespace GnuCms\Tests\Notify;

use GnuCms\Notify\Events;
use GnuCms\Notify\MessageVars;
use PHPUnit\Framework\TestCase;

final class MessageVarsTest extends TestCase
{
    /** 카탈로그에 선언된 이름만 본문으로 간다. 채널 문맥은 그 문 앞에서 멈춘다. */
    public function testOnlyCatalogueVariablesReachTheBody(): void
    {
        $vars = MessageVars::forBody('comment_new', [
            '사이트명' => '우리 커뮤니티', '이름' => '홍길동', '글제목' => '공지', '작성자' => '김',
            '링크' => 'https://example.com/p/7',
            '_post_id' => '7', '_comment_id' => '11', '_kind' => 'comment',
        ]);

        self::assertSame(['사이트명', '이름', '글제목', '작성자', '링크'], array_keys($vars));
        self::assertArrayNotHasKey('_post_id', $vars);
        self::assertArrayNotHasKey('_comment_id', $vars);
        self::assertArrayNotHasKey('_kind', $vars);
    }

    /**
     * 화이트리스트라는 점을 못박는다. 밑줄 접두사를 잊은 문맥 키가 새로 생겨도
     * 카탈로그에 없으면 본문에 닿지 못한다 — 접두사만 걸러내는 구현은 여기서 깨진다.
     */
    public function testAnyNameOutsideTheCatalogueIsDroppedEvenWithoutThePrefix(): void
    {
        $vars = MessageVars::forBody('password_reset',
            ['이름' => '홍길동', 'post_id' => '7', 'kind' => 'comment', '글제목' => '남의 이벤트 변수']);

        self::assertSame(['이름' => '홍길동'], $vars);
    }

    /** 스칼라가 아닌 값은 'Array' 로 캐스팅하지 않고 없는 값으로 접는다. */
    public function testNonScalarValuesAreTreatedAsAbsent(): void
    {
        $vars = MessageVars::forBody('password_reset', ['이름' => ['홍', '길동'], '링크' => 'https://a']);

        self::assertSame(['링크' => 'https://a'], $vars);
    }

    public function testUnknownEventHasNoBodyVariables(): void
    {
        self::assertSame([], MessageVars::forBody('no_such_event', ['이름' => '홍길동']));
    }

    public function testContextIsReadWithThePrefix(): void
    {
        $vars = ['_post_id' => 7, '_kind' => 'reply', '이름' => '홍길동'];

        self::assertSame('7', MessageVars::context($vars, 'post_id'));
        self::assertSame('reply', MessageVars::context($vars, 'kind'));
        self::assertNull(MessageVars::context($vars, 'comment_id'));
        // 접두사 없이 같은 이름을 넣어도 문맥으로 읽지 않는다.
        self::assertNull(MessageVars::context(['post_id' => 7], 'post_id'));
    }

    /**
     * **없는 것과 망가진 것은 다른 사실이다.** 둘을 같은 null 로 접으면, "없어도
     * 되는" 값을 읽는 쪽(알림함의 댓글번호)이 배열 하나를 "안 왔구나"로 읽고 조용히
     * NULL 을 적는다 — 실제로 그렇게 새어 나갔다. 그래서 문맥을 읽는 두 메서드 모두
     * 세 가지로 답한다: null(없음) · false(있는데 쓸 수 없음) · 값.
     */
    public function testContextTellsAbsentApartFromUnusable(): void
    {
        self::assertNull(MessageVars::context([], 'post_id'), '없음');
        self::assertFalse(MessageVars::context(['_post_id' => ['7']], 'post_id'), '배열');
        self::assertFalse(MessageVars::context(['_post_id' => null], 'post_id'),
            '키는 있는데 값이 null 이면 "쓸 수 없음"이다 — 문맥 키를 넣었다는 것 자체가 뜻이 있다');
        self::assertSame('7', MessageVars::context(['_post_id' => '7'], 'post_id'));
    }

    /**
     * 글번호·댓글번호가 무엇이어야 하는지는 여기서 한 번만 정한다. 1 이상의 정수를
     * 가리키는 값(정수 자체이거나, 앞자리 0 없는 숫자 문자열)만 번호다. 0·'03'·'9번'·
     * true·배열은 전부 "있는데 쓸 수 없음"이고, 어떤 PHP 타입으로 왔는지에 따라
     * 답이 달라지지 않는다.
     */
    public function testContextIdAcceptsOnlyARealRowNumber(): void
    {
        self::assertNull(MessageVars::contextId([], 'post_id'), '없음');
        self::assertSame(7, MessageVars::contextId(['_post_id' => 7], 'post_id'));
        self::assertSame(7, MessageVars::contextId(['_post_id' => '7'], 'post_id'));

        foreach ([
            '문자열 0' => '0', '숫자 0' => 0, '앞자리 0' => '03', '음수' => -1, '음수 문자열' => '-3',
            '숫자가 아님' => '9번', '빈 문자열' => '', '공백' => ' 3 ', '참' => true,
            '거짓' => false, '실수' => 3.5, '정수 같은 실수' => 3.0, '배열' => ['3'],
            'null' => null,
        ] as $why => $value) {
            self::assertFalse(MessageVars::contextId(['_post_id' => $value], 'post_id'), (string) $why);
        }
    }

    /** 카탈로그 이름과 문맥 접두사는 부딪히지 않는다 — 한글 이름에는 밑줄이 없다. */
    public function testNoCatalogueVariableStartsWithTheContextPrefix(): void
    {
        foreach (array_keys(Events::ALL) as $event) {
            foreach (Events::variables($event) as $name) {
                self::assertStringStartsNotWith(MessageVars::CONTEXT_PREFIX, $name,
                    $event . ' 의 변수 ' . $name . ' 이 문맥 접두사와 부딪힙니다');
            }
        }
    }
}
