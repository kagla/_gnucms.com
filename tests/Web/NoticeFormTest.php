<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NoticeFormTest extends WebTestCase
{
    private function loginAsAdmin(\GnuCms\App $app): void
    {
        $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com', 'password' => 'admin-password-123',
        ]);
    }

    private function loginAsMember(\GnuCms\App $app): void
    {
        $id = $app->users()->create('member@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '회원사람');
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com', 'password' => 'member-password-123',
        ]);
    }

    #[DataProvider('connectionProvider')]
    public function testAdminSeesTheNoticeChoiceAndMemberDoesNot(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유']);

        $this->loginAsAdmin($app);
        $adminForm = $this->body($this->get($app, '/boards/free/new'));
        self::assertStringContainsString('name="notice"', $adminForm);
        self::assertStringContainsString('전체 게시판 공지', $adminForm);

        $app2 = $this->makeApp($dbConfig);
        $app2->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유']);
        $this->loginAsMember($app2);
        self::assertStringNotContainsString('name="notice"', $this->body($this->get($app2, '/boards/free/new')));
    }

    #[DataProvider('connectionProvider')]
    public function testAdminCanPinThroughTheForm(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유']);
        $this->loginAsAdmin($app);

        $created = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '폼으로 올린 전체 공지', 'content' => '본문입니다', 'notice' => 'global',
        ]);
        self::assertSame(303, $created->getStatusCode());

        $body = $this->body($this->get($app, '/boards/free'));
        self::assertStringContainsString('전체 공지', $body);
        self::assertStringContainsString('폼으로 올린 전체 공지', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testEditFormRemembersTheCurrentScope(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $post = $app->postService()->create($acl, 'free', [
            'title' => '전체 공지', 'content' => '본문입니다', 'notice' => 'global',
        ]);
        $this->loginAsAdmin($app);

        $body = $this->body($this->get($app, '/posts/' . $post['id'] . '/edit'));

        self::assertMatchesRegularExpression('/value="global"[^>]*checked/', $body);
    }
}
