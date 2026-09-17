<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MessageHistoryTest extends WebTestCase
{
    /** 전역 관리자로 로그인한 앱. csrf_token 은 /login 을 한 번 거쳐야 세션에 생긴다. */
    private function adminApp(array $dbConfig): App
    {
        $app = $this->makeApp($dbConfig);
        $adminId = $app->users()->create(
            'history-admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true
        );
        $this->get($app, '/login');
        session_start();
        $_SESSION['user_id'] = $adminId;
        $_SESSION['session_epoch'] = 0;
        session_write_close();

        return $app;
    }

    private function seed(App $app): int
    {
        $db = $app->db();
        $jobId = (int) $db->insert('message_jobs', ['channel' => 'sms', 'sender' => '0212345678',
            'body' => '안녕하세요', 'failover' => 0, 'total' => 1, 'success' => 1, 'failure' => 0,
            'status' => 'sent', 'test_mode' => 0, 'created_at' => '2026-09-17 10:00:00']);
        $db->insert('message_recipients', ['job_id' => $jobId, 'mid' => 'M1', 'phone' => '01012345678',
            'body' => '안녕하세요', 'status' => 'accepted', 'requested_at' => '2026-09-17 10:00:00']);

        return $jobId;
    }

    #[DataProvider('connectionProvider')]
    public function testListMasksTheMiddleOfEveryNumber(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seed($app);

        $html = $this->body($this->get($app, '/admin/messages/history'));

        self::assertStringNotContainsString('010-1234-5678', $html);
        self::assertStringContainsString('결과를 기다리는 중', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testDetailShowsTheWholeNumber(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $jobId = $this->seed($app);

        $html = $this->body($this->get($app, '/admin/messages/history/' . $jobId));

        self::assertStringContainsString('010-1234-5678', $html);
        self::assertStringContainsString('안녕하세요', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCannotOpenTheHistory(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->assertLoginRedirect($this->get($app, '/admin/messages/history'), '/admin/messages/history');
    }

    #[DataProvider('connectionProvider')]
    public function testRefreshRouteIsNotCapturedAsAnId(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $response = $this->post($app, '/admin/messages/history/refresh', [
            'csrf_token' => $_SESSION['csrf_token'],
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('/admin/messages/history', $response->getHeaderLine('Location'));
    }

    #[DataProvider('connectionProvider')]
    public function testJobStatusesAllRenderDistinctLabels(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $db = $app->db();
        foreach (['sending', 'sent', 'failed', 'partial'] as $status) {
            $db->insert('message_jobs', ['channel' => 'sms', 'sender' => '0212345678',
                'body' => '본문 ' . $status, 'failover' => 0, 'total' => 1, 'success' => 0, 'failure' => 0,
                'status' => $status, 'test_mode' => 0, 'created_at' => '2026-09-17 10:00:00']);
        }

        $html = $this->body($this->get($app, '/admin/messages/history'));

        // 각 상태 배지는 서로 다른 색(class)과 문구 조합으로 정확히 한 번 나타나야 한다.
        // "실패"·"성공" 같은 낱말은 표 머리글에도 나오므로, 배지 마크업 전체를 맞춰
        // 머리글과 절대 혼동되지 않게 한다 — partial 이 sent 처럼 보이면 이 assert 가 깨진다.
        foreach ([
            'badge-ghost badge-soft">결과를 기다리는 중</span>',
            'badge-success badge-soft">성공</span>',
            'badge-error badge-soft">실패</span>',
            'badge-warning badge-soft">일부 실패</span>',
        ] as $badgeMarkup) {
            self::assertSame(1, substr_count($html, $badgeMarkup), $badgeMarkup . ' 배지 개수');
        }
    }

    #[DataProvider('connectionProvider')]
    public function testRecipientStatusesAllRenderDistinctLabels(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $db = $app->db();
        $jobId = (int) $db->insert('message_jobs', ['channel' => 'sms', 'sender' => '0212345678',
            'body' => '안녕하세요', 'failover' => 0, 'total' => 5, 'success' => 2, 'failure' => 2,
            'status' => 'partial', 'test_mode' => 0, 'created_at' => '2026-09-17 10:00:00']);
        $phones = ['01011110001', '01011110002', '01011110003', '01011110004', '01011110005'];
        foreach (['queued', 'accepted', 'sent', 'failed', 'unknown'] as $i => $status) {
            $db->insert('message_recipients', ['job_id' => $jobId, 'phone' => $phones[$i],
                'body' => '안녕하세요', 'status' => $status, 'requested_at' => '2026-09-17 10:00:00']);
        }

        $html = $this->body($this->get($app, '/admin/messages/history/' . $jobId));

        // 다섯 수신자 상태 각각 서로 다른 배지(색+문구)로 정확히 한 번씩 나타나야 한다.
        foreach ([
            'badge-sm badge-ghost badge-soft">대기 중</span>',
            'badge-sm badge-info badge-soft">결과를 기다리는 중</span>',
            'badge-sm badge-success badge-soft">전송 성공</span>',
            'badge-sm badge-error badge-soft">전송 실패</span>',
            'badge-sm badge-warning badge-soft">결과를 알 수 없음</span>',
        ] as $badgeMarkup) {
            self::assertSame(1, substr_count($html, $badgeMarkup), $badgeMarkup . ' 배지 개수');
        }
    }
}
