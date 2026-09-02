<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;

final class BoardListTest extends WebTestCase
{
    /** @dataProvider connectionProvider */
    public function testReadableBoardsAreListed(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, [], 'gnucmscom');
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free',
            'name'      => '자유게시판',
        ]);

        $response = $this->get($app, '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('자유게시판', $this->body($response));
        self::assertStringContainsString('/boards/free', $this->body($response));
        self::assertMatchesRegularExpression(
            '#<nav class="site-primary-nav"[^>]*>.*href="/boards/free"[^>]*>자유게시판</a>#s',
            $this->body($response)
        );
        self::assertStringContainsString('/themes/gnucmscom/theme.css', $this->body($response));
    }

    /** @dataProvider connectionProvider */
    public function testFreeBoardIsActiveOnceInTopNavigation(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, [], 'gnucmscom');
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free',
            'name'      => '자유게시판',
        ]);

        $body = $this->body($this->get($app, '/boards/free'));
        preg_match('#<nav class="site-primary-nav"[^>]*>(.*?)</nav>#s', $body, $nav);

        self::assertSame(1, substr_count($nav[1] ?? '', '>자유게시판</a>'));
        self::assertMatchesRegularExpression(
            '#href="/boards/free" class="is-active" aria-current="page">자유게시판</a>#',
            $nav[1] ?? ''
        );
    }

    /** @dataProvider connectionProvider */
    public function testUnreadableBoardIsHidden(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, [], 'gnucmscom');
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'secret',
            'name'      => '관리자전용',
            'perm_read' => 'admin',
        ]);

        $body = $this->body($this->get($app, '/'));

        self::assertStringNotContainsString('관리자전용', $body);
    }

    /** @dataProvider connectionProvider */
    public function testProductHomeIsShownWithoutBoards(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, [], 'gnucmscom');

        $body = $this->body($this->get($app, '/'));
        self::assertStringContainsString('필요한 것만 담은', $body);
        self::assertStringContainsString('내려받기', $body);
    }

    /** @dataProvider connectionProvider */
    public function testHomeExplainsTheProductAndLinksToUpstream(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, [], 'gnucmscom');
        $body = $this->body($this->get($app, '/'));

        self::assertStringContainsString('가벼운 오픈소스 CMS', $body);
        self::assertStringContainsString('PHP 7.4+', $body);
        self::assertStringContainsString('SQLite', $body);
        self::assertStringContainsString('https://github.com/kagla/gnucms', $body);
        self::assertStringNotContainsString('class="btn btn-ghost btn-circle theme-toggle"', $body);
        self::assertStringContainsString(GNUCMS_ID . '-theme', $body);
    }

    /** @dataProvider connectionProvider */
    public function testLatestFivePostsAreShownOnHome(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, [], 'gnucmscom');
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);

        for ($i = 1; $i <= 6; $i++) {
            $app->postService()->create($acl, 'free', ['title' => '홈 최신글 ' . $i, 'content' => '내용']);
        }

        $body = $this->body($this->get($app, '/'));

        self::assertStringContainsString('홈 최신글 6', $body);
        self::assertStringContainsString('홈 최신글 2', $body);
        self::assertStringNotContainsString('홈 최신글 1', $body);
        self::assertStringContainsString('GNUCMS · 가벼운 PHP CMS', $body);
    }

    /** @dataProvider connectionProvider */
    public function testProductHomeShowsRecentActivityBeforeProductGuide(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, [], 'gnucmscom');
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        $app->boardService()->create($acl, ['board_key' => 'questions', 'name' => '질문답변']);

        $app->postService()->create($acl, 'free', ['title' => '활동 소식 하나', 'content' => '내용']);
        $app->postService()->create($acl, 'questions', ['title' => '활동 소식 둘', 'content' => '내용']);
        $app->postService()->create($acl, 'free', ['title' => '활동 소식 셋', 'content' => '내용']);

        $body = $this->body($this->get($app, '/'));
        self::assertStringContainsString('지금 GNUCMS에서', $body);
        self::assertSame(3, substr_count($body, 'class="product-activity-item is-fresh"'));
        self::assertStringContainsString('활동 소식 하나', $body);
        self::assertStringContainsString('활동 소식 둘', $body);
        self::assertStringContainsString('활동 소식 셋', $body);
        self::assertLessThan(strpos($body, 'id="about"'), strpos($body, 'class="product-activity"'));
        self::assertSame(1, substr_count($body, 'GNUCMS 사이트 갤러리'));
        self::assertStringNotContainsString('제작하고 운영 중인 사이트를 한곳에서', $body);
    }
}
