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

    /**
     * 저장된 API 키는 화면 HTML 에 싣지 않는다 — 소스 보기에 키가 나오면 안 된다. 가려진
     * 자리표시자와 눈 버튼만 있고, 버튼을 누를 때 CSRF 를 확인한 POST 로만 내준다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheFormNeverCarriesTheKeyAndTheEyeFetchesIt(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'SECRET-KEY',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        $html = $this->body($this->get($app, '/admin/aligo'));
        self::assertStringNotContainsString('SECRET-KEY', $html);
        self::assertStringContainsString('placeholder="••••••••••••••••"', $html);
        self::assertStringContainsString('data-aligo-key-toggle', $html);
        self::assertStringNotContainsString('api_key_delete', $html);
        self::assertStringContainsString('02-1234-5678', $html);

        $revealed = $this->post($app, '/admin/aligo/key', ['csrf_token' => $_SESSION['csrf_token']]);
        self::assertSame(200, $revealed->getStatusCode());
        self::assertSame('no-store', $revealed->getHeaderLine('Cache-Control'));
        self::assertSame(['api_key' => 'SECRET-KEY'], json_decode($this->body($revealed), true));
    }

    /** 관리자가 아니면 키를 받을 수 없다. */
    #[DataProvider('connectionProvider')]
    public function testGuestCannotRevealTheKey(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'SECRET-KEY',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $this->get($app, '/login');

        $response = $this->post($app, '/admin/aligo/key', ['csrf_token' => $_SESSION['csrf_token']]);
        self::assertNotSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('SECRET-KEY', $this->body($response));
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

        // 'Z' 로 명시적 UTC 오프셋을 붙인다 — 오프셋 없이 넘기면 SendTime::parse() 가
        // KST 로 읽어 9시간 이르게 해석되므로 하한(10분)을 벗어난다(SendTimeTest 참고).
        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
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

    /**
     * 두 설정 화면이 한 방향으로만 가리키고 있었다. 알림 설정 화면은 채널 스위치가
     * 꺼져 있으면 이 화면으로 링크를 걸어 "여기서 켜 주세요"라고 말하는데, 이 화면의
     * "채널별 발송 허용"은 그 스위치를 끄면 코어 알림도 함께 멈춘다는 사실도, 알림
     * 설정 화면의 존재도 말하지 않았다 — 관리자는 이 화면만 보고 끄면서 무엇이 함께
     * 멈추는지 알 수 없었다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheChannelSwitchesSayThatCoreNotificationsRideOnThem(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $html = $this->body($this->get($app, '/admin/aligo'));

        self::assertStringContainsString('알림·발송 설정', $html);
        self::assertStringContainsString('/admin/settings/notifications', $html);
    }

    /**
     * 계정 저장 화면(=/admin/aligo)에서 API 키만 바꿔 저장하면 두 채널 스위치가 함께
     * 꺼진다. 그때 걸려 있던 예약이 취소되는지, 그리고 그 사실이 안내에 숫자로
     * 실리는지를 화면 경로로 확인한다 — 예전에는 303 과 초록 체크 「설정을
     * 저장했습니다」만 나오고 취소 호출은 0회였다.
     */
    #[DataProvider('connectionProvider')]
    public function testChangingTheKeyThroughTheScreenCancelsSchedulesAndSaysSo(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $transport = new FakeAligoTransport();
        $app->setAligo(new AligoService($app->db(), $transport,
            new SecretCipher('web-test-secret-that-is-long-enough')));
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'OLD-KEY',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $app->aligo()->settings->setEnabled('sms', true);

        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $transport->queue(200, '{"result_code":1,"msg_id":"M1","success_cnt":1,"error_cnt":0}');
        $jobId = $app->aligo()->send(['channel' => 'sms', 'body' => '안녕하세요', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01012345678']]]);

        $transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $response = $this->post($app, '/admin/aligo', ['csrf_token' => $_SESSION['csrf_token'],
            'user_id' => 'shop', 'api_key' => 'NEW-KEY', 'sender' => '0212345678', 'senderkey' => 'SK1']);

        self::assertSame(303, $response->getStatusCode());
        // 숫자는 쿼리로, 문장은 화면이 만든다(클래스 주석의 원칙).
        self::assertStringContainsString('cancel_ok=1', $response->getHeaderLine('Location'));
        self::assertStringContainsString('cancel_failed=0', $response->getHeaderLine('Location'));

        $job = $app->db()->selectOne('SELECT status FROM ' . $app->db()->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
        self::assertSame('cancelled', $job['status']);
        self::assertFalse($app->aligo()->settings->isEnabled('sms'));

        $html = $this->body($this->get($app, '/admin/aligo',
            ['saved' => '1', 'cancel_ok' => '1', 'cancel_failed' => '0']));
        self::assertStringContainsString('예약된 발송 1개를 함께 취소했습니다', $html);
    }

    /**
     * 취소하지 못한 예약이 남았다는 안내에 초록 체크가 붙으면, 문장을 끝까지 읽지 않은
     * 관리자는 다 끝난 줄 안다 — 이 기능이 낼 수 있는 가장 잘못된 신호다. 문장만이
     * 아니라 배지(색과 아이콘)까지 주의로 올라가야 하므로 마크업을 직접 본다.
     */
    #[DataProvider('connectionProvider')]
    public function testAFailedCancellationIsNotPaintedGreen(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $html = $this->body($this->get($app, '/admin/aligo',
            ['saved' => '1', 'cancel_ok' => '1', 'cancel_failed' => '1']));

        self::assertStringContainsString('1개는 취소하지 못했습니다', $html);
        // 가리키되 약속하지 않는다 — 가드절에 막힌 작업이면 적힌 사유도 재시도할 버튼도 없다.
        self::assertStringContainsString('이력 화면에서 그 작업의 상태와 사유를 확인해 주세요', $html);
        self::assertStringNotContainsString('거기서 다시 취소할 수 있습니다', $html);
        self::assertStringContainsString('<div class="alert alert-warning">', $html);
        self::assertStringNotContainsString('<div class="alert alert-success">', $html);
        // 알 수 없는 사유를 지어내지도 않는다.
        self::assertStringNotContainsString('발송 5분 전을 지나', $html);
    }

    /** 반대로 전부 취소된 평범한 저장은 그대로 성공이다 — 늘 주의로 칠하면 주의가 무의미해진다. */
    #[DataProvider('connectionProvider')]
    public function testASavedNoticeWithNothingLeftBehindStaysASuccess(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $html = $this->body($this->get($app, '/admin/aligo',
            ['saved' => '1', 'cancel_ok' => '2', 'cancel_failed' => '0']));

        self::assertStringContainsString('예약된 발송 2개를 함께 취소했습니다', $html);
        self::assertStringContainsString('<div class="alert alert-success">', $html);
        self::assertStringNotContainsString('<div class="alert alert-warning">', $html);
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

    /** 불러온 키를 비우고 저장하면 삭제, 불러오지 않은 빈 칸은 유지다. */
    #[DataProvider('connectionProvider')]
    public function testClearingTheLoadedKeyDeletesItButAnUntouchedBlankKeeps(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'SECRET-KEY',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $base = ['csrf_token' => $_SESSION['csrf_token'], 'user_id' => 'shop', 'sender' => '0212345678', 'senderkey' => 'SK1'];

        $this->post($app, '/admin/aligo', $base + ['api_key' => '']);
        self::assertSame('SECRET-KEY', $app->aligo()->settings->runtime()['api_key']);

        $response = $this->post($app, '/admin/aligo', $base + ['api_key' => '', 'api_key_loaded' => '1']);
        self::assertSame(303, $response->getStatusCode());
        self::assertNull($app->aligo()->settings->runtime());
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

        self::assertStringContainsString('alert alert-warning verify-result', $html);
        self::assertStringNotContainsString('alert alert-success verify-result', $html);
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

        self::assertStringContainsString('alert alert-success verify-result', $html);
        self::assertStringNotContainsString('alert alert-warning verify-result', $html);
        self::assertStringContainsString('알림톡 120건 남았습니다', $html);
        self::assertStringContainsString('SMS 500건 · LMS 100건 남았습니다', $html);
        self::assertStringNotContainsString('확인 실패', $html);
    }

    /**
     * 알리고는 키가 하나다. 없는 값을 묻는 칸을 화면에 두면 관리자가 그 값을 알리고에서
     * 찾아 헤맨다. 그래서 칸이 없어야 하고, 하나뿐인 "API 키" 칸이 양쪽에 쓰인다고 말해야 한다.
     */
    public function testTheScreenAsksForOneApiKeyThatServesBothChannels(): void
    {
        $html = $this->renderAligoSettings();

        self::assertStringNotContainsString('alimtalk_api_key', $html);
        self::assertStringNotContainsString('알림톡 전용', $html);
        self::assertStringContainsString('API Key 하나로 알림톡·문자 양쪽을 인증합니다', $html);
    }

    /**
     * 스위치 줄마다 켜기·끄기가 붙은 버튼 한 쌍으로 나오고, 저장된 상태의 버튼이 채운 색으로
     * 강조된다 — 켜기는 초록, 끄기는 진회색이라 색만 봐도 어느 쪽인지 안다. 강조된 버튼은 이미 그 상태라 제출하지 않고(눌린 상태), 반대쪽 버튼만 제출한다.
     * 버튼 하나만 있으면 "끄기"가 지금 꺼져 있다는 뜻인지 끌 수 있다는 뜻인지 읽히지 않았다.
     */
    public function testChannelModeHasOneCommonSettingsLink(): void
    {
        $html = $this->renderAligoSettings();
        self::assertStringContainsString('/admin/settings/messaging', $html);
        self::assertStringNotContainsString('channel-switch-name', $html);
    }

    /**
     * daisyUI 의 join 은 첫째·마지막 자식에만 바깥 모서리를 둥글게 한다. 폼 자체를 join 으로
     * 쓰면 hidden input 이 첫째 자식이 되어 왼쪽 버튼이 각진 채 남는다. 그래서 join 안에는
     * 버튼 둘만 있어야 한다.
     */
    public function testLocalChannelButtonsAreNotDuplicated(): void
    {
        $html = $this->renderAligoSettings();
        self::assertDoesNotMatchRegularExpression('/<input\b[^>]*name="channel"/', $html);
        self::assertStringContainsString('공통', $html);
    }

    /**
     * 발신프로필키는 채널 하나를 가리키는 식별자라 페이지에 실리지만, 키처럼 생긴 값이라 가려
     * 두고 눈 아이콘으로 본다. 값은 그대로 칸에 있어야 채널 고르기 스크립트와 저장이 돈다.
     */
    public function testTheSenderkeyIsMaskedButStillCarried(): void
    {
        $html = $this->renderAligoSettings();

        self::assertMatchesRegularExpression('~<input type="password" id="aligo-senderkey" name="senderkey" value="SK1"~', $html);
        self::assertStringContainsString('data-mask-toggle="발신프로필키"', $html);

        // 채널 목록도 키 전체를 글자로 찍지 않는다 — 앞뒤 넉 자만 보여 알아볼 수 있게 한다.
        $key = 'abcd1234efgh5678ijkl9012mnop3456qrst7890';
        $html = $this->renderAligoSettings(['profiles' => [['senderKey' => $key, 'name' => '우리상점', 'status' => 'A']]]);
        self::assertStringContainsString('우리상점 · abcd…7890 ', $html);
        self::assertStringNotContainsString('· ' . $key, $html);
    }

    /** 문자 줄이 먼저, 알림톡 줄이 그 아래다. */
    public function testAccountPageKeepsBothChannelConnectionInformation(): void
    {
        $html = $this->renderAligoSettings();
        self::assertStringContainsString('문자 (SMS·LMS)', $html);
        self::assertStringContainsString('알림톡 (카카오)', $html);
    }

    /** 계정을 저장하기 전에는 켤 수 없다 — 켜기 버튼이 잠긴다. 끄기는 이미 꺼진 상태라 눌린 채다. */
    public function testUnconfiguredAccountIsClearlyReported(): void
    {
        $html = $this->renderAligoSettings(['status' => ['configured' => false, 'sms_switch_on' => false, 'alimtalk_switch_on' => false, 'test_mode' => false, 'pending' => 0]]);
        self::assertStringContainsString('계정 연결 안 됨', $html);
        self::assertStringNotContainsString('channel-switch-name', $html);
    }

    /** 잔여 건수는 수천 단위가 보통이라 천 단위 구분 기호가 있어야 한눈에 읽힌다. */
    public function testVerifyBlockGroupsThousandsInTheCounts(): void
    {
        $html = $this->renderAligoSettings(['verified' => [
            'alimtalk' => ['ok' => true, 'count' => 7691, 'reason' => null],
            'sms' => ['ok' => true, 'sms_count' => 5951, 'lms_count' => 1930, 'reason' => null],
        ]]);

        self::assertStringContainsString('알림톡 7,691건 남았습니다', $html);
        self::assertStringContainsString('SMS 5,951건 · LMS 1,930건 남았습니다', $html);
    }

    /**
     * 폼을 보내면 새로 고침이 일어나는데, 예전에는 맨 위에 착지해서 방금 누른 버튼의 결과
     * (안내·잔여 건수·스위치 상태)를 보려면 다시 내려가야 했다. 저장·켜기·끄기는 안내가
     * 그려지는 자리로 착지한다. 리다이렉트는 Location 의 조각이 맡는다.
     */
    #[DataProvider('connectionProvider')]
    public function testSavingAndTogglingLandWhereTheNoticeShows(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $app->setAligo(new AligoService($app->db(), new FakeAligoTransport(),
            new SecretCipher('web-test-secret-that-is-long-enough')));

        $response = $this->post($app, '/admin/aligo', ['csrf_token' => $_SESSION['csrf_token'],
            'user_id' => 'shop', 'api_key' => 'K', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringEndsWith('#aligo-result', $response->getHeaderLine('Location'));

        $response = $this->post($app, '/admin/aligo/toggle', ['csrf_token' => $_SESSION['csrf_token'],
            'channel' => 'sms', 'action' => 'enable']);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringEndsWith('#aligo-result', $response->getHeaderLine('Location'));
    }

    /**
     * 재렌더하는 폼(연결 확인·채널 불러오기·켜기·끄기의 거절)은 action 의 조각이 착지를
     * 맡는다. 그래서 안내와 오류는 착지 지점 **뒤**에 그려져야 보인다 — 맨 위에 그리면
     * 조각으로 내려간 화면에서는 보이지 않는다.
     */
    public function testEachFormLandsWhereItsResultIsDrawn(): void
    {
        $html = $this->renderAligoSettings([
            'notice' => ['ok' => true, 'message' => '설정을 저장했습니다.'],
            'error' => '채널 목록을 불러오지 못했습니다.', 'error_at' => 'profiles',
        ]);

        self::assertStringContainsString('action="/admin/aligo/verify#aligo-verify"', $html);
        self::assertStringNotContainsString('action="/admin/aligo/toggle', $html);
        self::assertStringContainsString('action="/admin/aligo/profiles#aligo-profiles"', $html);

        $result = strpos($html, 'id="aligo-result"');
        self::assertNotFalse($result);
        $notice = strpos($html, '설정을 저장했습니다.');
        self::assertGreaterThan($result, $notice, '저장 안내는 착지 지점 뒤에 있어야 보인다');
        self::assertLessThan(strpos($html, '<h2 class="form-section-title">연결 확인</h2>'), $notice);

        $profiles = strpos($html, 'id="aligo-profiles"');
        self::assertNotFalse($profiles);
        $error = strpos($html, '채널 목록을 불러오지 못했습니다.');
        self::assertGreaterThan($profiles, $error, '채널 불러오기 오류는 그 버튼 곁에 있어야 보인다');
        self::assertLessThan(strpos($html, '<h2 class="form-section-title">문자 (SMS·LMS)</h2>'), $error);
    }

    /** admin/aligo_settings 템플릿이 요구하는 변수를 전부 채운 기본값 위에 덮어쓴다. */
    private function renderAligoSettings(array $overrides = []): string
    {
        $data = array_replace([
            'values' => ['user_id' => 'shop', 'sender' => '0212345678', 'senderkey' => 'SK1',
                'channel_name' => '', 'api_key' => '', 'api_key_set' => true,
                'test_mode' => false, 'sms_enabled' => true, 'alimtalk_enabled' => true],
            'status' => ['configured' => true, 'sms_switch_on' => true, 'alimtalk_switch_on' => true,
                'test_mode' => false, 'pending' => 0],
            'errors' => [], 'error' => null, 'error_at' => null, 'verified' => null, 'profiles' => [],
            'notice' => null, 'query' => [],
        ], $overrides);

        return AdminViewFixture::view()->fetch('admin/aligo_settings', $data);
    }
}
