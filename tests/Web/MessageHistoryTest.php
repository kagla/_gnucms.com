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

    /**
     * 접수까지만 끝난 작업. Dispatch 가 접수 직후에 실제로 남기는 모양 그대로다 —
     * 수신자가 'accepted'(결과를 기다리는 중)인데 작업은 'sent'(전원 성공)인 조합은
     * 코드가 만들 수 없는 상태이므로 심지 않는다.
     */
    private function seed(App $app): int
    {
        $db = $app->db();
        $jobId = (int) $db->insert('message_jobs', ['channel' => 'sms', 'sender' => '0212345678',
            'body' => '안녕하세요', 'failover' => 0, 'total' => 1, 'success' => 1, 'failure' => 0,
            'status' => 'sending', 'test_mode' => 0, 'created_at' => '2026-09-17 10:00:00']);
        $db->insert('message_recipients', ['job_id' => $jobId, 'mid' => 'M1', 'phone' => '01012345678',
            'body' => '안녕하세요', 'status' => 'accepted', 'requested_at' => '2026-09-17 10:00:00']);

        return $jobId;
    }

    /**
     * 이력 목록은 작업 단위라 수신번호가 아예 실리지 않는다 — 가려서 보여주는 것이
     * 아니라 애초에 없다. 번호는 상세에서만 보인다(아래 testDetailShowsTheWholeNumber).
     * 그래서 표시용 하이픈 형태뿐 아니라 숫자 원문도 함께 없는지 본다.
     */
    #[DataProvider('connectionProvider')]
    public function testListCarriesNoRecipientNumbersAtAll(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seed($app);

        $html = $this->body($this->get($app, '/admin/messages/history'));

        self::assertStringNotContainsString('010-1234-5678', $html);
        self::assertStringNotContainsString('01012345678', $html);
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
        foreach (['sending', 'sent', 'failed', 'partial', 'unknown'] as $status) {
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
            'badge-warning badge-soft">결과를 알 수 없음</span>',
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

    /**
     * 조회가 계속 실패하면(키가 취소됐다든가) 관리자는 결과가 천천히 "결과를 알 수
     * 없음"으로 바뀌는 것만 볼 뿐 이유를 알 수 없었다. 사유를 화면에 적되, 목록 자체는
     * 저장된 값으로 그대로 보여준다.
     */
    #[DataProvider('connectionProvider')]
    public function testAFailingLookupIsShownAsAWarningWithoutBreakingTheList(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        // 알리고 계정을 저장하지 않은 채로 결과를 기다리는 건이 있으면 조회가 실패한다.
        $this->seed($app);

        $html = $this->body($this->get($app, '/admin/messages/history'));

        self::assertStringContainsString('결과를 물어보다 실패했습니다', $html);
        self::assertStringContainsString('알리고 계정을 먼저 저장해 주세요', $html);
        // 목록은 그대로 보인다 — 조회 실패가 화면을 깨뜨리지 않는다.
        self::assertStringContainsString('결과를 기다리는 중', $html);
    }

    /** 조회가 잘 되면 경고 띠는 나오지 않는다 — 있지도 않은 실패를 매번 보여주면 안 된다. */
    #[DataProvider('connectionProvider')]
    public function testNoWarningWhenThereIsNothingToLookUp(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $html = $this->body($this->get($app, '/admin/messages/history'));

        self::assertStringNotContainsString('결과를 물어보다 실패했습니다', $html);
    }

    /**
     * 상세 화면의 안내는 서버가 만든 문장만 보여준다. 예전에는 ?notice= 로 아무 문장이나
     * 성공 알림처럼 띄울 수 있었다.
     */
    #[DataProvider('connectionProvider')]
    public function testDetailDoesNotEchoANoticeFromTheUrl(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $jobId = $this->seed($app);

        $html = $this->body($this->get($app, '/admin/messages/history/' . $jobId,
            ['notice' => '계정이 만료되었습니다. 여기로 로그인하세요']));

        self::assertStringNotContainsString('계정이 만료되었습니다', $html);
    }

    /** 발송 안내는 그 발송의 상세에서만 보여준다 — 아무 작업에나 붙일 수 없다. */
    #[DataProvider('connectionProvider')]
    public function testTheSentNoticeOnlyShowsOnItsOwnJob(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $jobId = $this->seed($app);

        $mine = $this->body($this->get($app, '/admin/messages/history/' . $jobId, ['sent' => (string) $jobId]));
        $other = $this->body($this->get($app, '/admin/messages/history/' . $jobId, ['sent' => (string) ($jobId + 7)]));

        self::assertStringContainsString('발송을 시작했습니다', $mine);
        self::assertStringNotContainsString('발송을 시작했습니다', $other);
    }

    /**
     * 손으로 누른 갱신이 실패하면 목록으로 돌아가며 그 사실을 알려야 한다. 돌아간
     * 화면이 다시 조회하지는 않는다 — 방금 찍힌 재확인 표시(60초) 안이라 아무것도
     * 묻지 않고 지나가므로, 실패했다는 사실이 깃발로 따라오지 않으면 조용히 사라진다.
     * URL 에는 깃발만 싣고 문장은 서버가 만든다.
     */
    #[DataProvider('connectionProvider')]
    public function testAFailedManualRefreshSaysSoOnTheListItReturnsTo(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seed($app);

        $response = $this->post($app, '/admin/messages/history/refresh', [
            'csrf_token' => $_SESSION['csrf_token'],
        ]);

        self::assertSame(303, $response->getStatusCode());
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        self::assertSame('1', $query['failed'] ?? null);

        $html = $this->body($this->get($app, '/admin/messages/history', ['failed' => '1']));
        self::assertStringContainsString('결과 조회에 실패했습니다', $html);
    }
}
