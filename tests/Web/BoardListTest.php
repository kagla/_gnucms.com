<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;

final class BoardListTest extends WebTestCase
{
    /** @dataProvider connectionProvider */
    public function testAdminSeesRestrictedBoardsInHeaderImmediatelyAfterLogin(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'members', 'name' => '회원메뉴', 'perm_read' => 'member', 'show_in_header' => 1,
        ]);
        $app->boardService()->create($acl, [
            'board_key' => 'admins', 'name' => '관리자메뉴', 'perm_read' => 'admin', 'show_in_header' => 1,
        ]);
        $adminId = $app->users()->create(
            'admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true
        );
        $app->users()->verifyEmail($adminId);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'admin@example.com',
            'password' => 'admin-password-123',
        ]);

        $body = $this->body($this->get($app, '/'));
        preg_match('#<nav class="tabs tabs-border"[^>]*>(.*?)</nav>#s', $body, $headerTabs);
        self::assertStringContainsString('href="/boards/members">회원메뉴</a>', $headerTabs[1] ?? '');
        self::assertStringContainsString('href="/boards/admins">관리자메뉴</a>', $headerTabs[1] ?? '');
    }

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
        self::assertStringContainsString(GNUCMS_ID . '-theme', $body);
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
        self::assertStringContainsString(GNUCMS . ' · 가볍게 시작하는 기초 커뮤니티', $body);
    }
}
