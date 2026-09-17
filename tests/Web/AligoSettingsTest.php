<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AligoSettingsTest extends WebTestCase
{
    /** 전역 관리자로 로그인한 앱. csrf_token 은 /login 을 한 번 거쳐야 세션에 생긴다. */
    private function adminApp(array $dbConfig): App
    {
        $app = $this->makeApp($dbConfig);
        $adminId = $app->users()->create(
            'aligo-admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true
        );
        $this->get($app, '/login');
        session_start();
        $_SESSION['user_id'] = $adminId;
        $_SESSION['session_epoch'] = 0;
        session_write_close();

        return $app;
    }

    #[DataProvider('connectionProvider')]
    public function testFormNeverEchoesTheStoredKey(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'SECRET-KEY',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        $html = $this->body($this->get($app, '/admin/aligo'));

        self::assertStringNotContainsString('SECRET-KEY', $html);
        self::assertStringContainsString('저장됨', $html);
        self::assertStringContainsString('02-1234-5678', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testSavingRejectsABadSenderAndKeepsTheInput(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $response = $this->post($app, '/admin/aligo', ['csrf_token' => $_SESSION['csrf_token'],
            'user_id' => 'shop', 'api_key' => 'K', 'sender' => '123', 'senderkey' => 'SK1']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('발신번호', $this->body($response));
    }

    #[DataProvider('connectionProvider')]
    public function testTurningSendingOnRequiresAnAccount(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $response = $this->post($app, '/admin/aligo/toggle', ['csrf_token' => $_SESSION['csrf_token'],
            'channel' => 'sms', 'action' => 'enable']);

        self::assertSame(422, $response->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCannotOpenTheSettings(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->assertLoginRedirect($this->get($app, '/admin/aligo'), '/admin/aligo');
    }
}
