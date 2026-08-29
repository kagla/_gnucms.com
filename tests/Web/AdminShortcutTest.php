<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 관리자가 공개 화면을 보다가 바로 관리 화면으로 넘어갈 수 있어야 한다.
 * 반대로 일반 방문자에게는 그 링크가 새어 나가면 안 된다.
 */
final class AdminShortcutTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testAdminSeesEditLinkOnLegalPageAndGuestDoesNot(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->createPage([
            'slug' => 'privacy', 'title' => '개인정보 처리방침', 'content' => '내용',
            'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0,
        ]);

        // 옛 주소는 정식 주소로 보낸다.
        self::assertSame(301, $this->get($app, '/terms/privacy')->getStatusCode());
        self::assertSame('/content/privacy', $this->get($app, '/terms/privacy')->getHeaderLine('Location'));

        $page = $app->cms()->findBySlug('privacy');
        self::assertStringNotContainsString(
            '/admin/content/' . $page['id'] . '/edit',
            $this->body($this->get($app, '/content/privacy')),
            '게스트에게는 관리 링크가 보이면 안 된다'
        );

        $this->loginAsAdmin($app);
        $body = $this->body($this->get($app, '/content/privacy'));

        self::assertStringContainsString('/admin/content/' . $page['id'] . '/edit', $body);
        self::assertStringContainsString('관리자에게만 보입니다', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testAdminSeesEditLinkOnRegularContentPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->cms()->createPage([
            'slug' => 'about', 'title' => '소개', 'content' => '내용',
            'seo_description' => null, 'status' => 'published', 'show_in_menu' => 1, 'sort_order' => 0,
        ]);
        $this->loginAsAdmin($app);

        self::assertStringContainsString(
            '/admin/content/' . $id . '/edit',
            $this->body($this->get($app, '/content/about'))
        );
    }

    #[DataProvider('connectionProvider')]
    public function testAdminSeesBoardSettingsLinkOnBoardPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유게시판']);

        self::assertStringNotContainsString(
            '/admin/boards/free/edit',
            $this->body($this->get($app, '/boards/free'))
        );

        $this->loginAsAdmin($app);

        self::assertStringContainsString(
            '/admin/boards/free/edit',
            $this->body($this->get($app, '/boards/free'))
        );
    }

    /** 미리보기 화면에는 '수정으로 돌아가기' 안내가 이미 있으므로 중복해서 띄우지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testPreviewKeepsItsOwnBarWithoutTheAdminShortcut(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->cms()->createPage([
            'slug' => 'draft', 'title' => '초안', 'content' => '내용',
            'seo_description' => null, 'status' => 'draft', 'show_in_menu' => 0, 'sort_order' => 0,
        ]);
        $this->loginAsAdmin($app);

        $body = $this->body($this->get($app, '/admin/content/' . $id . '/preview'));

        self::assertStringContainsString('편집으로 돌아가기', $body);
        self::assertStringNotContainsString('관리자에게만 보입니다', $body);
    }

    private function loginAsAdmin(App $app): void
    {
        $id = $app->users()->create(
            'shortcut-admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT),
            '바로가기 관리자',
            true
        );
        $app->users()->verifyEmail($id);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'email'      => 'shortcut-admin@example.com',
            'password'   => 'admin-password-123',
        ]);
    }
}
