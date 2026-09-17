<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Aligo\AligoService;
use GnuCms\App;
use GnuCms\Mail\SecretCipher;
use GnuCms\Support\Clock;
use GnuCms\Tests\Support\AdminViewFixture;
use GnuCms\Tests\Support\FakeAligoTransport;
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

    /**
     * 화면의 끄기 버튼(=/admin/aligo/toggle)이 실제로 예약을 취소하는지 확인한다.
     * AdminAligoController::toggle() 이 AligoService::setChannelEnabled() 가 아니라
     * 예전처럼 Settings::setEnabled() 를 직접 부르는 채로 남아 있으면, 이 테스트는
     * 스위치는 꺼져도 작업이 여전히 'scheduled'로 남아 실패한다 — 관리자가 채널을 끄는
     * 유일한 통로가 새 조율 경로를 실제로 타는지는 이 경로로만 검증할 수 있다.
     */
    #[DataProvider('connectionProvider')]
    public function testTurningAChannelOffThroughTheScreenCancelsItsSchedules(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $transport = new FakeAligoTransport();
        $app->setAligo(new AligoService($app->db(), $transport,
            new SecretCipher('web-test-secret-that-is-long-enough')));
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $app->aligo()->settings->setEnabled('sms', true);

        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600);
        $transport->queue(200, '{"result_code":1,"msg_id":"M1","success_cnt":1,"error_cnt":0}');
        $jobId = $app->aligo()->send(['channel' => 'sms', 'body' => '안녕하세요', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01012345678']]]);

        $transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $response = $this->post($app, '/admin/aligo/toggle', ['csrf_token' => $_SESSION['csrf_token'],
            'channel' => 'sms', 'action' => 'disable']);

        self::assertSame(303, $response->getStatusCode());
        self::assertFalse($app->aligo()->settings->isEnabled('sms'));

        $job = $app->db()->selectOne('SELECT status FROM ' . $app->db()->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
        self::assertSame('cancelled', $job['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCannotOpenTheSettings(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->assertLoginRedirect($this->get($app, '/admin/aligo'), '/admin/aligo');
    }

    #[DataProvider('connectionProvider')]
    public function testTurningAlimtalkOnRequiresAnAccount(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $response = $this->post($app, '/admin/aligo/toggle', ['csrf_token' => $_SESSION['csrf_token'],
            'channel' => 'at', 'action' => 'enable']);

        self::assertSame(422, $response->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testDeletingTheStoredKeyActuallyRemovesIt(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'SECRET-KEY',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        self::assertTrue($app->aligo()->settings->formValues()['api_key_set']);

        $response = $this->post($app, '/admin/aligo', ['csrf_token' => $_SESSION['csrf_token'],
            'user_id' => 'shop', 'sender' => '0212345678', 'senderkey' => 'SK1', 'api_key_delete' => '1']);

        self::assertSame(303, $response->getStatusCode());
        self::assertFalse($app->aligo()->settings->formValues()['api_key_set']);
    }

    /**
     * verify() 를 통하지 않고 템플릿만 직접 그린다. render()·verify 블록은 $verified 배열
     * 하나만으로 그려지므로(App::aligo() 를 건드리지 않으므로), 네트워크나 가짜 Transport
     * 없이 AdminViewFixture 로 바로 검증할 수 있다 — 컨트롤러·브라우저 검증에서 실제로
     * 이렇게 쓰라고 있는 도구다(AdminViewFixture 문서 주석 참고).
     */
    public function testVerifyBlockShowsAFailingChannelDistinctlyFromASucceedingOne(): void
    {
        $html = $this->renderAligoSettings(['verified' => [
            'alimtalk' => ['ok' => false, 'count' => 0, 'reason' => '알림톡 응답을 확인할 수 없습니다.'],
            'sms' => ['ok' => true, 'sms_count' => 42, 'lms_count' => 7, 'reason' => null],
        ]]);

        self::assertStringContainsString('alert-warning', $html);
        self::assertStringNotContainsString('alert-success', $html);
        self::assertStringContainsString('알림톡 확인 실패', $html);
        self::assertStringContainsString('알림톡 응답을 확인할 수 없습니다.', $html);
        self::assertStringContainsString('SMS 42건 · LMS 7건 남았습니다', $html);
        // 실패한 채널은 "0건" 같은 건수 모양 문자열을 보여주지 않는다 — 정상 조회한 0건과
        // 구별이 안 되기 때문이다.
        self::assertStringNotContainsString('알림톡 0건', $html);
        self::assertStringNotContainsString('문자 확인 실패', $html);
    }

    public function testVerifyBlockShowsRealCountsWhenBothChannelsSucceed(): void
    {
        $html = $this->renderAligoSettings(['verified' => [
            'alimtalk' => ['ok' => true, 'count' => 120, 'reason' => null],
            'sms' => ['ok' => true, 'sms_count' => 500, 'lms_count' => 100, 'reason' => null],
        ]]);

        self::assertStringContainsString('alert-success', $html);
        self::assertStringNotContainsString('alert-warning', $html);
        self::assertStringContainsString('알림톡 120건 남았습니다', $html);
        self::assertStringContainsString('SMS 500건 · LMS 100건 남았습니다', $html);
        self::assertStringNotContainsString('확인 실패', $html);
    }

    /** admin/aligo_settings 템플릿이 요구하는 변수를 전부 채운 기본값 위에 덮어쓴다. */
    private function renderAligoSettings(array $overrides = []): string
    {
        $data = array_replace([
            'values' => ['user_id' => 'shop', 'sender' => '0212345678', 'senderkey' => 'SK1',
                'channel_name' => '', 'api_key' => '', 'api_key_set' => true,
                'alimtalk_api_key' => '', 'alimtalk_api_key_set' => false,
                'test_mode' => false, 'sms_enabled' => true, 'alimtalk_enabled' => true],
            'status' => ['configured' => true, 'sms_enabled' => true, 'alimtalk_enabled' => true,
                'test_mode' => false, 'pending' => 0],
            'errors' => [], 'error' => null, 'verified' => null, 'profiles' => [], 'notice' => null,
            'query' => [],
        ], $overrides);

        return AdminViewFixture::view()->fetch('admin/aligo_settings', $data);
    }
}
