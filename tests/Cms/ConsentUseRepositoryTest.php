<?php

declare(strict_types=1);

namespace GnuCms\Tests\Cms;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConsentUseRepositoryTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testAttachDetachAndListForScope(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $uses = $app->consentUses();

        $terms = $app->cms()->createPage([
            'slug' => 'terms', 'title' => '이용약관', 'content' => '본문', 'seo_description' => null,
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'is_consent' => 1,
        ]);
        $draft = $app->cms()->createPage([
            'slug' => 'location', 'title' => '위치기반 약관', 'content' => '본문', 'seo_description' => null,
            'status' => 'draft', 'show_in_menu' => 0, 'sort_order' => 0, 'is_consent' => 1,
        ]);

        $uses->attach('signup', $terms, true, 10);
        $uses->attach('signup', $draft, false, 20);

        $all = $uses->listForScope('signup');
        self::assertCount(2, $all);
        self::assertSame('이용약관', $all[0]['title']);
        self::assertSame(1, (int) $all[0]['required']);
        self::assertSame(10, (int) $all[0]['use_sort_order'], '붙임의 차례는 내용 칸과 이름이 겹치지 않게 따로 준다');

        // 공개된 것만 걸러 읽을 수 있다. 초안은 가입 화면에 붙으면 안 된다.
        self::assertCount(1, $uses->listForScope('signup', true));

        // 같은 자리에 다시 붙이면 덮어쓴다. 줄이 늘지 않는다.
        $uses->attach('signup', $terms, false, 5);
        $again = $uses->listForScope('signup');
        self::assertCount(2, $again);
        self::assertSame(0, (int) $again[0]['required']);
        self::assertSame(5, (int) $again[0]['use_sort_order']);

        // 같은 약관을 다른 자리에 다른 규칙으로 붙일 수 있다.
        $uses->attach('form:event', $terms, true, 1);
        self::assertCount(2, $uses->listForContent($terms));

        $uses->detach('signup', $terms);
        self::assertCount(1, $uses->listForScope('signup'));
        self::assertCount(1, $uses->listForContent($terms));
    }
}
