<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminPageTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testGuestCannotOpenAdminPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        self::assertSame(401, $this->get($app, '/admin')->getStatusCode());
        self::assertSame(401, $this->get($app, '/admin/boards')->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testAdminEmailLogsInAndRendersDashboard(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);

        $this->get($app, '/login');
        $login = $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'admin@example.com',
            'password' => 'admin-password-123',
        ]);
        $dashboard = $this->get($app, '/admin');

        self::assertSame(303, $login->getStatusCode());
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('<h1>사이트 관리</h1>', $this->body($dashboard));
        self::assertStringNotContainsString('<header class="site-header">', $this->body($dashboard));
        self::assertStringContainsString('class="admin-sidebar"', $this->body($dashboard));
        self::assertStringContainsString('<strong>관리 콘솔</strong></a>', $this->body($dashboard));
        self::assertStringContainsString('class="admin-sidebar-toggle"', $this->body($dashboard));
        self::assertStringContainsString('aria-controls="admin-sidebar"', $this->body($dashboard));
        self::assertStringContainsString('class="admin-account"', $this->body($dashboard));
        self::assertStringContainsString('data-admin-page="dashboard"', $this->body($dashboard));
        self::assertStringContainsString('회원 관리', $this->body($dashboard));
        self::assertStringContainsString('메일 설정', $this->body($dashboard));

        $mail = $this->get($app, '/admin/mail');
        self::assertSame(200, $mail->getStatusCode());
        self::assertStringContainsString('data-admin-page="mail"', $this->body($mail));
        self::assertStringContainsString('smtp.gmail.com', $this->body($mail));
        $saved = $this->post($app, '/admin/mail', [
            'csrf_token' => $_SESSION['csrf_token'], 'enabled' => '1', 'provider' => 'gmail',
            'host' => 'smtp.gmail.com', 'port' => '465', 'encryption' => 'ssl',
            'username' => 'owner@gmail.com', 'password' => 'google-app-password',
            'from_email' => 'owner@gmail.com', 'from_name' => 'aboard',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/admin/mail?saved=1', $saved->getHeaderLine('Location'));
        self::assertStringNotContainsString('google-app-password', $this->body($this->get($app, '/admin/mail')));
    }

    #[DataProvider('connectionProvider')]
    public function testAdminCanCreateBoardFromWebForm(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com', 'password' => 'admin-password-123',
        ]);

        $boardsPage = $this->get($app, '/admin/boards');
        self::assertSame(200, $boardsPage->getStatusCode());
        self::assertStringContainsString('<h1>게시판 관리</h1>', $this->body($boardsPage));
        self::assertStringContainsString('data-admin-page="boards"', $this->body($boardsPage));
        self::assertStringContainsString('/admin/boards/new', $this->body($boardsPage));

        $response = $this->post($app, '/admin/boards/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'board_key' => 'notice', 'name' => '공지사항', 'description' => '중요한 소식',
            'categories_text' => "안내, 업데이트\n새 소식", 'managers_text' => '',
            'perm_read' => 'guest', 'perm_write' => 'admin', 'perm_comment' => 'member',
            'per_page' => '20', 'sort_order' => '-10', 'use_category' => '1',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/boards?saved=1', $response->getHeaderLine('Location'));
        $board = $app->boards()->findByKey('notice');
        self::assertSame('공지사항', $board['name']);
        self::assertSame(['안내, 업데이트', '새 소식'], $board['categories']);
        self::assertSame('admin', $board['perm_write']);
        $savedPage = $this->get($app, '/admin/boards?saved=1');
        self::assertStringContainsString('공지사항', $this->body($savedPage));
        self::assertStringContainsString('게시판 설정을 저장했습니다.', $this->body($savedPage));
    }

    #[DataProvider('connectionProvider')]
    public function testAdminCanEditMemberInformation(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $adminId = $app->users()->create(
            'admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT),
            '관리자',
            true
        );
        $app->users()->verifyEmail($adminId);
        $memberId = $app->users()->create(
            'member@example.com',
            password_hash('member-password-123', PASSWORD_DEFAULT),
            'member',
            false
        );
        $app->users()->verifyEmail($memberId);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'admin@example.com',
            'password' => 'admin-password-123',
        ]);

        $members = $this->get($app, '/admin/members');
        self::assertSame(200, $members->getStatusCode());
        self::assertStringContainsString('/admin/members/' . $memberId . '/edit', $this->body($members));
        $form = $this->get($app, '/admin/members/' . $memberId . '/edit');
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('<h1>회원 수정</h1>', $this->body($form));

        $saved = $this->post($app, '/admin/members/' . $memberId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'new-member@example.com',
            'display_name' => '새 표시 이름',
            'status' => 'blocked',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/admin/members?saved=1', $saved->getHeaderLine('Location'));
        $updated = $app->users()->findById($memberId);
        self::assertSame('new-member@example.com', $updated['email']);
        self::assertSame('새 표시 이름', $updated['display_name']);
        self::assertSame('blocked', $updated['status']);

        $blockedOwner = $this->post($app, '/admin/members/' . $adminId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'admin@example.com',
            'display_name' => '관리자',
            'status' => 'blocked',
        ]);
        self::assertSame(422, $blockedOwner->getStatusCode());
        self::assertStringContainsString('현재 로그인한 관리자 계정은 차단할 수 없습니다.', $this->body($blockedOwner));
        self::assertSame('active', $app->users()->findById($adminId)['status']);
    }
}
