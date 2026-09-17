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

    #[DataProvider('connectionProvider')]
    public function testWithdrawnMembersDoNotAppearInSearchResults(array $dbConfig): void
    {
        $app = $this->ready($dbConfig);
        $id = $this->member($app, 'leaving@example.com', '나가는이', '01055556666');
        $app->users()->withdraw((int) $id, null);
        $row = $app->db()->selectOne('SELECT display_name FROM '
            . $app->db()->table('users') . ' WHERE id = ?', [$id]);

        // 탈퇴하면 이름·이메일이 익명화된다. 그 익명화된 이름으로 검색해도(=검색어가
        // 여전히 일치해도) 탈퇴 회원은 결과에 나오지 않아야 한다.
        $html = $this->body($this->get($app, '/admin/messages/send', ['q' => '탈퇴']));

        self::assertStringNotContainsString((string) $row['display_name'], $html);
    }

    #[DataProvider('connectionProvider')]
    public function testWithdrawnMemberIdPostedDirectlyProducesNoRecipient(array $dbConfig): void
    {
        $app = $this->ready($dbConfig);
        // 탈퇴 처리는 번호를 지우지 않으므로, "번호가 없어 제외"와 뒤섞이지 않게
        // 번호가 있는 채로 탈퇴시킨다 — 탈퇴 자체가 제외 사유여야 한다.
        $id = $this->member($app, 'gone@example.com', '탈퇴예정', '01099998888');
        $app->users()->withdraw((int) $id, null);

        $previewHtml = $this->body($this->post($app, '/admin/messages/send/preview', [
            'csrf_token' => $_SESSION['csrf_token'],
            'channel' => 'sms', 'body' => '안녕하세요',
            'members' => [$id],
        ]));
        self::assertStringContainsString('0명', $previewHtml);
        self::assertStringContainsString('탈퇴', $previewHtml);

        $response = $this->post($app, '/admin/messages/send/dispatch', [
            'csrf_token' => $_SESSION['csrf_token'],
            'channel' => 'sms', 'body' => '안녕하세요',
            'members' => [$id],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('수신번호가 없습니다', $this->body($response));
        self::assertSame(0, (int) $app->db()->selectOne('SELECT COUNT(*) AS c FROM '
            . $app->db()->table('message_jobs'))['c'], '탈퇴 회원만 골랐으면 보낼 대상이 없어 작업이 만들어지지 않는다');
    }
}
