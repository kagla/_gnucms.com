<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MessageSendTest extends WebTestCase
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

    /** 발송이 가능한 상태까지 만든 앱 */
    private function ready(array $dbConfig): App
    {
        $app = $this->adminApp($dbConfig);
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $app->aligo()->settings->setEnabled('sms', true);

        return $app;
    }

    private function member(App $app, string $email, string $name, ?string $phone): string
    {
        $id = $app->users()->create($email, password_hash('member-password-123', PASSWORD_DEFAULT), $name);
        if ($phone !== null) {
            $app->db()->update('users', ['phone' => $phone], 'id = :id', ['id' => $id]);
        }

        return (string) $id;
    }

    #[DataProvider('connectionProvider')]
    public function testPreviewShowsTheFirstRecipientBodyWithoutSending(array $dbConfig): void
    {
        $app = $this->ready($dbConfig);

        $html = $this->body($this->post($app, '/admin/messages/send/preview', [
            'csrf_token' => $_SESSION['csrf_token'],
            'channel' => 'sms', 'body' => '#{이름}님 안녕하세요',
            'numbers' => "010-1111-2222\n010-3333-4444", 'var_이름' => '홍길동',
        ]));

        self::assertStringContainsString('홍길동님 안녕하세요', $html);
        self::assertStringContainsString('2명', $html);
        self::assertSame(0, (int) $app->db()->selectOne('SELECT COUNT(*) AS c FROM '
            . $app->db()->table('message_jobs'))['c'], '미리보기는 보내지 않는다');
    }

    #[DataProvider('connectionProvider')]
    public function testDispatchIsRefusedWhenTheChannelIsOff(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        $response = $this->post($app, '/admin/messages/send/dispatch', [
            'csrf_token' => $_SESSION['csrf_token'],
            'channel' => 'sms', 'body' => '안녕하세요', 'numbers' => '010-1111-2222',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('허용', $this->body($response));
    }

    #[DataProvider('connectionProvider')]
    public function testMembersWithoutAPhoneAreReportedAsSkipped(array $dbConfig): void
    {
        $app = $this->ready($dbConfig);
        $withPhone = $this->member($app, 'a@example.com', '있음', '01011112222');
        $withoutPhone = $this->member($app, 'b@example.com', '없음', null);

        $html = $this->body($this->post($app, '/admin/messages/send/preview', [
            'csrf_token' => $_SESSION['csrf_token'],
            'channel' => 'sms', 'body' => '안녕하세요',
            'members' => [$withPhone, $withoutPhone],
        ]));

        self::assertStringContainsString('1명', $html);
        self::assertStringContainsString('번호가 없어 제외', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCannotOpenTheSendTab(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->assertLoginRedirect($this->get($app, '/admin/messages/send'), '/admin/messages/send');
    }
}
