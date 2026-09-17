<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\Variables;
use GnuCms\Error\DomainError;
use PHPUnit\Framework\TestCase;

final class VariablesTest extends TestCase
{
    public function testFindsNamesInOrderWithoutDuplicates(): void
    {
        self::assertSame(['이름', '주문번호'], Variables::names('#{이름}님 #{주문번호} 건, #{이름}님 감사합니다'));
        self::assertSame([], Variables::names('변수 없는 본문'));
    }

    public function testReplacesEveryOccurrence(): void
    {
        self::assertSame(
            '홍길동님 A-1 건, 홍길동님 감사합니다',
            Variables::apply('#{이름}님 #{주문번호} 건, #{이름}님 감사합니다', ['이름' => '홍길동', '주문번호' => 'A-1'])
        );
    }

    public function testRefusesWhenAValueIsMissingOrBlank(): void
    {
        self::assertSame(['주문번호'], Variables::missing('#{이름} #{주문번호}', ['이름' => '홍길동']));
        self::assertSame(['주문번호'], Variables::missing('#{이름} #{주문번호}', ['이름' => '홍길동', '주문번호' => '  ']));

        try {
            Variables::apply('#{이름} #{주문번호}', ['이름' => '홍길동']);
            self::fail('빈 변수는 거절해야 한다');
        } catch (DomainError $e) {
            self::assertStringContainsString('주문번호', $e->details()['vars']);
        }
    }

    public function testRefusesBlankPlaceholdersWithoutWarning(): void
    {
        // 공백 전용 플레이스홀더는 silent hole을 방지하기 위해 거절해야 한다.
        // 이는 PHP warning 없이 정상 예외로 처리되어야 한다.
        try {
            Variables::apply('안내 #{   } 문구입니다', []);
            self::fail('공백만 있는 플레이스홀더는 거절해야 한다');
        } catch (DomainError $e) {
            self::assertStringContainsString('빈 변수 마커', $e->details()['vars']);
        }
    }
}
