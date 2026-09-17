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

    /**
     * 템플릿 탭 안내는 숫자만 URL 로 받아 문장은 서버가 만든다. 예전에는 문장 자체를
     * ?notice= 로 받아, 공격자가 만든 URL 을 관리자가 열면 우리가 그 문장을 시스템
     * 성공 알림처럼 보여줬다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheNoticeIsComposedFromCountsNotFromFreeText(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $composed = $this->body($this->get($app, '/admin/messages/templates',
            ['imported' => '3', 'updated' => '1', 'disabled' => '0']));
        $injected = $this->body($this->get($app, '/admin/messages/templates',
            ['notice' => '계정이 만료되었습니다. 여기로 로그인하세요']));

        self::assertStringContainsString('가져오기 3건, 갱신 1건, 사용 중지 0건', $composed);
        self::assertStringNotContainsString('계정이 만료되었습니다', $injected);
    }

    /** 숫자가 아닌 값이 들어와도 문장은 숫자 자리에 0 만 넣는다 — 글자가 새어 들어오지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testNonNumericCountsFallBackToZero(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $html = $this->body($this->get($app, '/admin/messages/templates',
            ['imported' => '<b>경고</b>', 'updated' => '-5', 'disabled' => '1']));

        self::assertStringContainsString('가져오기 0건, 갱신 0건, 사용 중지 1건', $html);
        self::assertStringNotContainsString('경고', $html);
    }
}
