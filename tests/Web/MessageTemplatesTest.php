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

    /**
     * 화면의 "다시 가져오기" 버튼(=/admin/messages/templates/fetch)이 실제로 예약을
     * 취소하는지, 그리고 그 취소 결과가 화면에 보이는지 확인한다.
     * AdminMessageController::fetchTemplates() 가 AligoService::importTemplates() 가
     * 아니라 예전처럼 Templates::fetch() 를 직접 부르는 채로 남아 있으면, 이 테스트는
     * 스위치(사본이 꺼지는 것)는 그대로 일어나도 작업이 여전히 'scheduled'로 남아
     * 실패한다 — 관리자가 템플릿을 다시 가져오는 유일한 통로가 새 조율 경로를 실제로
     * 타는지는 이 경로로만 검증할 수 있다. 취소 결과가 리다이렉트 쿼리(cancel_ok)에
     * 실려 오는지, 그리고 그 숫자가 가져오기 안내 문장에 실제로 반영되는지까지
     * 함께 본다 — 설정 화면(AdminAligoController::toggle())은 이미 이 숫자를
     * 보여주므로, 여기서도 같은 사실을 침묵하면 안 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testRefetchingThroughTheScreenCancelsScheduleOfATemplateThatLostApproval(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $transport = new FakeAligoTransport();
        $app->setAligo(new AligoService($app->db(), $transport,
            new SecretCipher('web-test-secret-that-is-long-enough')));
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $app->aligo()->settings->setEnabled('at', true);

        $transport->queue(200, (string) json_encode(['code' => 0, 'list' => [[
            'templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '본문',
            'status' => 'A', 'inspStatus' => 'APR']]]));
        $app->aligo()->templates->fetch();
        $app->aligo()->templates->setEnabled('T1', true);

        // 'Z' 로 명시적 UTC 오프셋을 붙인다 — 오프셋 없이 넘기면 SendTime::parse() 가
        // KST 로 읽어 9시간 이르게 해석되므로 하한(10분)을 벗어난다(SendTimeTest 참고).
        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $transport->queue(200, (string) json_encode(['code' => 0, 'info' => ['mid' => 'A1', 'scnt' => 1, 'fcnt' => 0]]));
        $jobId = $app->aligo()->send(['channel' => 'at', 'tpl_code' => 'T1', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01012345678']]]);

        // 화면에서 다시 가져오면 T1 은 승인을 잃은 채(status=S)로 온다.
        $transport->queue(200, (string) json_encode(['code' => 0, 'list' => [[
            'templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '본문',
            'status' => 'S', 'inspStatus' => 'APR']]]));
        $transport->queue(200, '{"code":0}');

        $response = $this->post($app, '/admin/messages/templates/fetch',
            ['csrf_token' => $_SESSION['csrf_token']]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(0, (int) $app->aligo()->templates->find('T1')['enabled'], '승인을 잃었으므로 꺼져 있어야 한다');

        $job = $app->db()->selectOne('SELECT status FROM ' . $app->db()->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
        self::assertSame('cancelled', $job['status']);

        // 취소 결과가 리다이렉트에 숫자로 실려 오고, 그 숫자가 화면 문장에 그대로 반영된다.
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        self::assertSame('1', $query['cancel_ok'] ?? null);
        self::assertSame('0', $query['cancel_failed'] ?? null);

        $html = $this->body($this->get($app, '/admin/messages/templates', $query));
        self::assertStringContainsString('승인을 잃어 예약돼 있던 발송 1개를 함께 취소했습니다', $html);
    }

    /**
     * 취소가 일부만 성공하면(500명이 넘는 작업처럼 mid 가 여러 개면 일부 묶음은 취소가
     * 거절될 수 있다) 그 사실이 "취소했습니다"로 뭉개지지 않고 몇 개가 남았는지 그대로
     * 문장에 남아야 한다 — 부분 실패를 성공 문장으로 매끈하게 다듬으면 안 된다는 것이
     * 이 기능 전체의 요구사항이다. 다만 왜 남았는지는 단정하지 않는다: 이 화면은 그
     * 사유를 알지 못하고, 사유가 실제로 적혀 있는 곳은 이력 상세다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheTemplateTabNoticeStillShowsAPartialCancellationNotAsASuccess(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $html = $this->body($this->get($app, '/admin/messages/templates',
            ['imported' => '0', 'updated' => '1', 'disabled' => '1', 'cancel_ok' => '1', 'cancel_failed' => '1']));

        self::assertStringContainsString(
            '승인을 잃은 템플릿에 걸린 예약 2개 중 1개를 취소했고, 1개는 취소하지 못해 예정대로 나갑니다', $html
        );
        // "취소했습니다"만 있고 실패를 뭉개는 문장은 나오면 안 된다.
        self::assertStringNotContainsString('승인을 잃어 예약돼 있던 발송', $html);
        // 이 화면은 취소가 왜 실패했는지 알지 못한다 — 지어내지 않고 이력 상세로 보낸다.
        self::assertStringNotContainsString('발송 5분 전을 지나', $html);
        self::assertStringContainsString('사유는 이력 화면의 작업 상세에 적혀 있고', $html);
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
