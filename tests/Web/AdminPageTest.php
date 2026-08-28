<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
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
    public function testStudioThemeUsesItsOwnAdminLayout(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['theme' => 'studio']);
        $id = $app->users()->create(
            'studio-admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT),
            'Studio 관리자',
            true
        );
        $app->users()->verifyEmail($id);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'studio-admin@example.com',
            'password' => 'admin-password-123',
        ]);
        $dashboard = $this->get($app, '/admin');
        $body = $this->body($dashboard);

        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('<body class="admin-page">', $body);
        self::assertStringContainsString('/themes/studio/theme.css', $body);
        self::assertStringContainsString('class="admin-sidebar"', $body);
        self::assertStringNotContainsString('<header class="site-header">', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testHarborThemeUsesResponsiveSvgAdminConsole(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['theme' => 'harbor']);
        $id = $app->users()->create(
            'harbor-admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT),
            'Harbor 관리자',
            true
        );
        $app->users()->verifyEmail($id);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'harbor-admin@example.com',
            'password' => 'admin-password-123',
        ]);
        $body = $this->body($this->get($app, '/admin'));

        self::assertStringContainsString('<body class="theme-harbor admin-page">', $body);
        self::assertStringContainsString('/themes/harbor/theme.css', $body);
        self::assertStringContainsString('class="admin-sidebar harbor-admin-sidebar"', $body);
        self::assertStringContainsString('class="harbor-svg', $body);
        self::assertStringNotContainsString('<header class="site-header', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testCodexBloomThemeUsesDaisyUiAdminConsole(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['theme' => 'codex-bloom']);
        $id = $app->users()->create(
            'codex-bloom-admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT),
            'Codex Bloom 관리자',
            true
        );
        $app->users()->verifyEmail($id);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'codex-bloom-admin@example.com',
            'password' => 'admin-password-123',
        ]);
        $body = $this->body($this->get($app, '/admin'));

        self::assertStringContainsString('<body class="theme-codex-bloom admin-page">', $body);
        self::assertStringContainsString('/vendor/daisyui/daisyui.css', $body);
        self::assertStringContainsString('/themes/codex-bloom/theme.css', $body);
        self::assertStringContainsString('class="admin-sidebar codex-bloom-admin-sidebar"', $body);
        self::assertStringContainsString('aria-controls="admin-sidebar"', $body);
        self::assertStringContainsString('class="codex-bloom-svg', $body);
        self::assertStringNotContainsString('<header class="site-header', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testAdminEmailLogsInAndRendersDashboard(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['theme' => 'modern']);
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
        self::assertStringContainsString('class="admin-page"', $this->body($dashboard));
        self::assertStringNotContainsString('<body class="theme-modern">', $this->body($dashboard));
        self::assertStringContainsString('<h1>사이트 관리</h1>', $this->body($dashboard));
        self::assertStringNotContainsString('<header class="site-header">', $this->body($dashboard));
        self::assertStringContainsString('class="admin-sidebar"', $this->body($dashboard));
        self::assertStringContainsString('>관리 콘솔</strong>', $this->body($dashboard));
        self::assertStringContainsString('admin-fold', $this->body($dashboard));
        self::assertStringContainsString('for="admin-drawer"', $this->body($dashboard));
        self::assertStringContainsString('class="admin-user"', $this->body($dashboard));
        self::assertStringContainsString('href="/admin" class="menu-active"', $this->body($dashboard));
        self::assertStringContainsString('회원 관리', $this->body($dashboard));
        self::assertStringContainsString('메일 설정', $this->body($dashboard));

        $mail = $this->get($app, '/admin/mail');
        self::assertSame(200, $mail->getStatusCode());
        self::assertStringContainsString('href="/admin/mail" class="menu-active"', $this->body($mail));
        self::assertStringContainsString('smtp.gmail.com', $this->body($mail));
        $saved = $this->post($app, '/admin/mail', [
            'csrf_token' => $_SESSION['csrf_token'], 'enabled' => '1', 'provider' => 'gmail',
            'host' => 'smtp.gmail.com', 'port' => '465', 'encryption' => 'ssl',
            'username' => 'owner@gmail.com', 'password' => 'google-app-password',
            'from_email' => 'owner@gmail.com', 'from_name' => 'gnucms.com',
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
        self::assertStringContainsString('href="/admin/boards" class="menu-active"', $this->body($boardsPage));
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
        self::assertStringContainsString('>회원 수정</h1>', $this->body($form));

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
