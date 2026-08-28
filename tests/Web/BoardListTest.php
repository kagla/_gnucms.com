<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\Tests\Support\WebTestCase;

final class BoardListTest extends WebTestCase
{
    /** @dataProvider connectionProvider */
    public function testReadableBoardsAreListed(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free',
            'name'      => '자유게시판',
        ]);

        $response = $this->get($app, '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('자유게시판', $this->body($response));
        self::assertStringContainsString('/boards/free', $this->body($response));
        self::assertStringContainsString('/themes/default/theme.css', $this->body($response));
    }

    /** @dataProvider connectionProvider */
    public function testUnreadableBoardIsHidden(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'secret',
            'name'      => '관리자전용',
            'perm_read' => 'admin',
        ]);

        $body = $this->body($this->get($app, '/'));

        self::assertStringNotContainsString('관리자전용', $body);
    }

    /** @dataProvider connectionProvider */
    public function testEmptyStateIsShown(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        self::assertStringContainsString('게시판이 없습니다', $this->body($this->get($app, '/')));
    }

    /** @dataProvider connectionProvider */
    public function testHomeExplainsFoundationCommunityAndOffersThemeToggle(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $body = $this->body($this->get($app, '/'));

        self::assertStringContainsString('가볍게 시작하고, 오래 이어지는 공간', $body);
        self::assertStringContainsString('기초 커뮤니티', $body);
        self::assertStringContainsString('theme-toggle', $body);
        self::assertStringContainsString('aboard-theme', $body);
    }

    /** @dataProvider connectionProvider */
    public function testSelectedModernThemeUsesItsOwnPublicLayoutAndHomeTemplate(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['theme' => 'modern']);

        $response = $this->get($app, '/');
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('class="theme-modern"', $body);
        self::assertStringContainsString('/themes/modern/theme.css', $body);
        self::assertStringContainsString('class="site-header modern-header"', $body);
        self::assertStringContainsString('class="modern-directory"', $body);
        self::assertStringContainsString('가볍게 시작하고, 오래 이어지는 공간', $body);
    }

    /** @dataProvider connectionProvider */
    public function testStudioThemeOwnsTheCompletePublicExperience(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['theme' => 'studio']);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free',
            'name' => '자유게시판',
            'description' => '편하게 이야기를 나누는 공간',
        ]);

        $home = $this->body($this->get($app, '/'));
        $board = $this->body($this->get($app, '/boards/free'));

        self::assertStringContainsString('<body class="studio-page">', $home);
        self::assertStringContainsString('/themes/studio/theme.css', $home);
        self::assertStringContainsString('class="studio-hero discovery-hero"', $home);
        self::assertStringContainsString('class="studio-board-grid"', $home);
        self::assertStringContainsString('자유게시판', $board);
        self::assertStringContainsString('제목이나 내용 검색', $board);
    }

    /** @dataProvider connectionProvider */
    public function testDaylightThemeOwnsEveryPublicViewWithoutDefaultFallback(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['theme' => 'daylight']);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'talk',
            'name' => '이야기',
        ]);

        $home = $this->body($this->get($app, '/'));
        $board = $this->body($this->get($app, '/boards/talk'));
        $login = $this->body($this->get($app, '/login'));

        self::assertStringContainsString('<body class="daylight-page">', $home);
        self::assertStringContainsString('/themes/daylight/theme.css', $home);
        self::assertStringContainsString('오늘 발견한 이야기', $home);
        self::assertStringContainsString('class="studio-quick-nav"', $home);
        self::assertStringContainsString('class="studio-board-hero"', $board);
        self::assertStringContainsString('class="studio-auth-layout"', $login);
    }

    /** @dataProvider connectionProvider */
    public function testHarborThemeProvidesResponsiveSvgComponentExperience(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['theme' => 'harbor']);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'living', 'name' => '생활 이야기']);
        $app->postService()->create($acl, 'living', ['title' => '오늘의 새로운 이야기', 'content' => '내용']);

        $home = $this->body($this->get($app, '/'));
        $board = $this->body($this->get($app, '/boards/living'));
        $login = $this->body($this->get($app, '/login'));

        self::assertStringContainsString('<body class="theme-harbor">', $home);
        self::assertStringContainsString('/themes/harbor/theme.css', $home);
        self::assertStringContainsString('class="harbor-mobile-toggle harbor-icon-btn"', $home);
        self::assertStringContainsString('aria-controls="harbor-mobile-nav"', $home);
        self::assertStringContainsString('class="harbor-svg', $home);
        self::assertStringContainsString('오늘의 새로운 이야기', $home);
        self::assertStringContainsString('class="harbor-card-grid harbor-post-grid"', $board);
        self::assertStringContainsString('class="harbor-auth-card', $login);
    }

    /** @dataProvider connectionProvider */
    public function testCodexBloomThemeUsesLocalDaisyUiAcrossPublicViews(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['theme' => 'codex-bloom']);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'living', 'name' => '생활 이야기']);
        $app->postService()->create($acl, 'living', ['title' => '취향을 나누는 오늘', 'content' => '내용']);

        $home = $this->body($this->get($app, '/'));
        $board = $this->body($this->get($app, '/boards/living'));
        $login = $this->body($this->get($app, '/login'));

        self::assertStringContainsString('<html lang="ko" data-theme="light">', $home);
        self::assertStringContainsString('<body class="theme-codex-bloom">', $home);
        self::assertStringContainsString('/vendor/daisyui/daisyui.css', $home);
        self::assertStringContainsString('/themes/codex-bloom/theme.css', $home);
        self::assertStringContainsString('aria-controls="codex-bloom-mobile-nav"', $home);
        self::assertStringContainsString('class="codex-bloom-svg', $home);
        self::assertStringContainsString('취향을 나누는 오늘', $home);
        self::assertStringContainsString('card bg-base-100 codex-bloom-feed-card', $board);
        self::assertStringContainsString('input input-bordered', $board);
        self::assertStringContainsString('card bg-base-100 codex-bloom-auth-card', $login);
    }

    /** @dataProvider connectionProvider */
    public function testLatestFivePostsAreShownOnHome(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);

        for ($i = 1; $i <= 6; $i++) {
            $app->postService()->create($acl, 'free', ['title' => '홈 최신글 ' . $i, 'content' => '내용']);
        }

        $body = $this->body($this->get($app, '/'));

        self::assertStringContainsString('홈 최신글 6', $body);
        self::assertStringContainsString('홈 최신글 2', $body);
        self::assertStringNotContainsString('홈 최신글 1', $body);
        self::assertStringContainsString('gnucms.com · 가볍게 시작하는 기초 커뮤니티', $body);
    }
}
