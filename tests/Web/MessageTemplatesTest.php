<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MessageTemplatesTest extends WebTestCase
{
    /** 전역 관리자로 로그인한 앱. csrf_token 은 /login 을 한 번 거쳐야 세션에 생긴다. */
    private function adminApp(array $dbConfig): App
    {
        $app = $this->makeApp($dbConfig);
        $adminId = $app->users()->create(
            'msg-admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true
        );
        $this->get($app, '/login');
        session_start();
        $_SESSION['user_id'] = $adminId;
        $_SESSION['session_epoch'] = 0;
        session_write_close();

        return $app;
    }

    #[DataProvider('connectionProvider')]
    public function testListShowsCopiesAndTheirApprovalState(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $db = $app->db();
        $db->insert('alimtalk_templates', ['tpl_code' => 'T1', 'senderkey' => 'SK1', 'name' => '주문 안내',
            'content' => '#{이름}님', 'status' => 'A', 'insp_status' => 'APR', 'enabled' => 0,
            'fetched_at' => '2026-09-17 10:00:00']);

        $html = $this->body($this->get($app, '/admin/messages/templates'));

        self::assertStringContainsString('주문 안내', $html);
        self::assertStringContainsString('T1', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testTurningOnATemplateThatIsNotApprovedFails(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $app->db()->insert('alimtalk_templates', ['tpl_code' => 'W1', 'senderkey' => 'SK1', 'name' => '대기',
            'content' => '본문', 'status' => 'R', 'insp_status' => 'REQ', 'enabled' => 0,
            'fetched_at' => '2026-09-17 10:00:00']);

        $response = $this->post($app, '/admin/messages/templates/toggle',
            ['csrf_token' => $_SESSION['csrf_token'], 'tpl_code' => 'W1', 'action' => 'enable']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('승인', $this->body($response));
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCannotOpenTheTemplateTab(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->assertLoginRedirect(
            $this->get($app, '/admin/messages/templates'), '/admin/messages/templates');
    }
}
