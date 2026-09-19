<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Aligo\AligoService;
use GnuCms\App;
use GnuCms\Mail\SecretCipher;
use GnuCms\Support\Clock;
use GnuCms\Tests\Support\FakeAligoTransport;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 탈퇴·차단은 예약된 발송까지 멈춘다.
 *
 * 번호를 지우는 것만으로는 멈추지 않는다 — 예약은 거는 순간 알리고로 넘어가고
 * (docs/messaging.md §7), 수신자 행은 번호를 따로 들고 있으며, 발송 시각은 최대
 * 30일 뒤다. 그래서 탈퇴한 사람의 전화기가 며칠 뒤에 울렸다.
 *
 * 이 시험들이 **화면 경로**로 도는 이유: 취소를 부르는 자리는 AccountService·
 * AdminService 인데, App 이 그 둘에 알리고 서비스를 끼워 주지 않으면 기능은 조용히
 * 죽는다(계획 4 의 R43 이 같은 모양의 사고를 기록한다 — 새 조율 메서드는 만들었는데
 * 유일한 호출부가 옛 길을 그대로 쓰고 있었다). 배선까지 확인할 수 있는 길은 이것뿐이다.
 */
final class ScheduledSendOnLeavingTest extends WebTestCase
{
    /** 가짜 전송기를 끼운 앱과 그 전송기. setAligo() 는 이 서비스를 쥔 서비스들을 함께 끊는다. */
    private function appWithFakeAligo(array $dbConfig): array
    {
        $app = $this->makeApp($dbConfig);
        $transport = new FakeAligoTransport();
        $app->setAligo(new AligoService($app->db(), $transport,
            new SecretCipher('web-test-secret-that-is-long-enough')));
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $app->aligo()->settings->setEnabled('sms', true);

        return [$app, $transport];
    }

    /** 그 회원 한 사람에게 건 예약 하나. 접수(mid M1)까지 끝난 상태로 돌려준다. */
    private function bookFor(App $app, FakeAligoTransport $transport, int $userId, string $phone): int
    {
        // 'Z' 로 명시적 UTC 오프셋을 붙인다 — 오프셋 없이 넘기면 SendTime::parse() 가
        // KST 로 읽어 9시간 이르게 해석되므로 하한(10분)을 벗어난다(SendTimeTest 참고).
        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $transport->queue(200, '{"result_code":1,"msg_id":"M' . $userId . '","success_cnt":1,"error_cnt":0}');

        return $app->aligo()->send(['channel' => 'sms', 'body' => '예약 안내', 'scheduled_at' => $at,
            'recipients' => [['phone' => $phone, 'user_id' => (string) $userId]]]);
    }

    private function jobStatus(App $app, int $jobId): string
    {
        return (string) $app->db()->selectOne('SELECT status FROM ' . $app->db()->table('message_jobs')
            . ' WHERE id = ?', [$jobId])['status'];
    }

    #[DataProvider('connectionProvider')]
    public function testWithdrawingCancelsTheSchedulesBookedForThatMember(array $dbConfig): void
    {
        [$app, $transport] = $this->appWithFakeAligo($dbConfig);
        $id = $app->users()->create('leave@example.com',
            password_hash('leave-password-123', PASSWORD_DEFAULT), '떠날회원');
        $app->users()->verifyEmail($id);
        $app->users()->updatePhone($id, '01044445555');
        $other = $app->users()->create('stay@example.com',
            password_hash('stay-password-123', PASSWORD_DEFAULT), '남을회원');
        $app->users()->updatePhone($other, '01055556666');

        $mine = $this->bookFor($app, $transport, $id, '01044445555');
        $theirs = $this->bookFor($app, $transport, $other, '01055556666');

        $this->get($app, '/login');
        $this->post($app, '/login', ['csrf_token' => $_SESSION['csrf_token'],
            'email' => 'leave@example.com', 'password' => 'leave-password-123']);

        $transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $response = $this->post($app, '/account/withdraw', ['csrf_token' => $_SESSION['csrf_token'],
            'withdraw_current_password' => 'leave-password-123', 'confirm_withdrawal' => '1']);

        self::assertSame(303, $response->getStatusCode(), $this->body($response));
        $cancelRequest = end($transport->requests);
        self::assertStringContainsString('/cancel/', $cancelRequest['url']);
        self::assertSame('M' . $id, $cancelRequest['fields']['mid']);
        self::assertSame('cancelled', $this->jobStatus($app, $mine));
        // 남는 사람의 예약은 그대로다 — 탈퇴 한 번이 다른 사람의 발송까지 지우면 안 된다.
        self::assertSame('scheduled', $this->jobStatus($app, $theirs));

        $row = $app->db()->selectOne('SELECT status FROM ' . $app->db()->table('message_recipients')
            . ' WHERE job_id = ?', [$mine]);
        self::assertSame('cancelled', $row['status'], '이력에는 실패가 아니라 취소로 남아야 한다');
    }

    /**
     * 대조군. 예약이 없는 회원의 탈퇴는 알리고를 부르지 않는다 — 탈퇴할 때마다 바깥
     * 왕복이 한 번씩 생기면, 이 기능은 탈퇴 화면에 알리고를 매달아 둔 셈이 된다.
     * (탈퇴 자체가 되는지는 AccountPageTest 가 본다.)
     */
    #[DataProvider('connectionProvider')]
    public function testWithdrawingWithNothingBookedDoesNotCallAligoAtAll(array $dbConfig): void
    {
        [$app, $transport] = $this->appWithFakeAligo($dbConfig);
        $id = $app->users()->create('quiet@example.com',
            password_hash('quiet-password-123', PASSWORD_DEFAULT), '조용회원');
        $app->users()->verifyEmail($id);
        $app->users()->updatePhone($id, '01044445555');
        $requestsBefore = count($transport->requests);

        $this->get($app, '/login');
        $this->post($app, '/login', ['csrf_token' => $_SESSION['csrf_token'],
            'email' => 'quiet@example.com', 'password' => 'quiet-password-123']);
        $response = $this->post($app, '/account/withdraw', ['csrf_token' => $_SESSION['csrf_token'],
            'withdraw_current_password' => 'quiet-password-123', 'confirm_withdrawal' => '1']);

        self::assertSame(303, $response->getStatusCode(), $this->body($response));
        self::assertCount($requestsBefore, $transport->requests);
        self::assertSame('withdrawn', $app->users()->findById($id)['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testBlockingAMemberCancelsTheSchedulesBookedForThem(array $dbConfig): void
    {
        [$app, $transport] = $this->appWithFakeAligo($dbConfig);
        $adminId = $app->users()->create('boss@example.com',
            password_hash('boss-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($adminId);
        $memberId = $app->users()->create('member@example.com',
            password_hash('member-password-123', PASSWORD_DEFAULT), '회원');
        $app->users()->updatePhone($memberId, '01044445555');

        $jobId = $this->bookFor($app, $transport, $memberId, '01044445555');

        $this->get($app, '/login');
        $this->post($app, '/login', ['csrf_token' => $_SESSION['csrf_token'],
            'email' => 'boss@example.com', 'password' => 'boss-password-123']);

        $transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $response = $this->post($app, '/admin/members/' . $memberId . '/status',
            ['csrf_token' => $_SESSION['csrf_token']]);

        self::assertSame(303, $response->getStatusCode(), $this->body($response));
        self::assertSame('blocked', $app->users()->findById($memberId)['status']);
        $cancelRequest = end($transport->requests);
        self::assertStringContainsString('/cancel/', $cancelRequest['url']);
        self::assertSame('M' . $memberId, $cancelRequest['fields']['mid']);
        self::assertSame('cancelled', $this->jobStatus($app, $jobId));
    }

    /**
     * 차단은 회원 수정 화면에서도 일어난다(상태 칸). 두 자리는 서로 다른 코드라
     * 한쪽만 고치면 다른 쪽은 조용히 옛 동작으로 남는다 — 그래서 둘 다 확인한다.
     * 이미 차단된 회원의 다른 칸을 고치는 저장은 다시 취소를 부르지 않는다.
     */
    #[DataProvider('connectionProvider')]
    public function testBlockingThroughTheMemberEditFormCancelsToo(array $dbConfig): void
    {
        [$app, $transport] = $this->appWithFakeAligo($dbConfig);
        $adminId = $app->users()->create('boss@example.com',
            password_hash('boss-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($adminId);
        $memberId = $app->users()->create('member@example.com',
            password_hash('member-password-123', PASSWORD_DEFAULT), '회원');
        $app->users()->updatePhone($memberId, '01044445555');
        $jobId = $this->bookFor($app, $transport, $memberId, '01044445555');

        $this->get($app, '/login');
        $this->post($app, '/login', ['csrf_token' => $_SESSION['csrf_token'],
            'email' => 'boss@example.com', 'password' => 'boss-password-123']);

        $transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $response = $this->post($app, '/admin/members/' . $memberId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com',
            'display_name' => '회원', 'status' => 'blocked',
        ]);

        self::assertSame(303, $response->getStatusCode(), $this->body($response));
        self::assertSame('blocked', $app->users()->findById($memberId)['status']);
        self::assertSame('cancelled', $this->jobStatus($app, $jobId));

        // 이미 차단된 회원의 이름만 고치는 저장은 알리고를 다시 부르지 않는다.
        $requestsBefore = count($transport->requests);
        $this->post($app, '/admin/members/' . $memberId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com',
            'display_name' => '새이름', 'status' => 'blocked',
        ]);
        self::assertCount($requestsBefore, $transport->requests);
    }

    /**
     * 대조군. 차단을 **푸는** 쪽은 멈출 것이 없다 — 여기서도 취소를 부르면, 차단을
     * 풀 때마다 그 사람에게 걸린 예약이 사라진다.
     */
    #[DataProvider('connectionProvider')]
    public function testUnblockingCancelsNothing(array $dbConfig): void
    {
        [$app, $transport] = $this->appWithFakeAligo($dbConfig);
        $adminId = $app->users()->create('boss@example.com',
            password_hash('boss-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($adminId);
        $memberId = $app->users()->create('member@example.com',
            password_hash('member-password-123', PASSWORD_DEFAULT), '회원');
        $app->users()->updatePhone($memberId, '01044445555');
        $jobId = $this->bookFor($app, $transport, $memberId, '01044445555');
        $app->users()->setStatus($memberId, 'blocked');
        $requestsBefore = count($transport->requests);

        $this->get($app, '/login');
        $this->post($app, '/login', ['csrf_token' => $_SESSION['csrf_token'],
            'email' => 'boss@example.com', 'password' => 'boss-password-123']);
        $response = $this->post($app, '/admin/members/' . $memberId . '/status',
            ['csrf_token' => $_SESSION['csrf_token']]);

        self::assertSame(303, $response->getStatusCode(), $this->body($response));
        self::assertSame('active', $app->users()->findById($memberId)['status']);
        self::assertCount($requestsBefore, $transport->requests);
        self::assertSame('scheduled', $this->jobStatus($app, $jobId));
    }
}
