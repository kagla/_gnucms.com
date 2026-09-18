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
        self::assertNull(MessageVars::context(['_post_id' => ['7']], 'post_id'));
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
