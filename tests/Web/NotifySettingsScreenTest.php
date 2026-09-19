<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Aligo\MessageText;
use GnuCms\App;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 알림 설정 화면. 이 화면의 규칙은 하나다 — **켤 수 없는 칸은 켤 수 없다고 적고,
 * 켜 두었는데 나가지 않는 칸은 왜 나가지 않는지 적는다.** 아래 테스트는 대부분 그
 * 규칙이 어느 한 자리에서 무너지는 것을 잡으려고 있다.
 */
final class NotifySettingsScreenTest extends WebTestCase
{
    /** 전역 관리자로 로그인한 앱. csrf_token 은 /login 을 한 번 거쳐야 세션에 생긴다. */
    private function adminApp(array $dbConfig): App
    {
        $app = $this->makeApp($dbConfig);
        $adminId = $app->users()->create(
            'notify-admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true
        );
        $this->get($app, '/login');
        session_start();
        $_SESSION['user_id'] = $adminId;
        $_SESSION['session_epoch'] = 0;
        session_write_close();

        return $app;
    }

    /** 승인 템플릿 하나. 본문 변수는 password_reset 이 실제로 가진 값으로만 잇는다. */
    private function seedTemplate(App $app, string $code = 'T1', int $enabled = 1): void
    {
        $app->db()->insert('alimtalk_templates', ['tpl_code' => $code, 'senderkey' => 'SK1',
            'name' => '재설정 안내', 'content' => '#{고객명}님 #{주소} 에서 재설정하세요',
            'status' => 'A', 'insp_status' => 'APR', 'enabled' => $enabled,
            'fetched_at' => '2026-09-17 10:00:00']);
    }

    /** 정확히 $bytes 바이트(EUC-KR)인 본문. 한글 한 자가 2바이트, ASCII 가 1바이트다. */
    private static function bodyOfBytes(int $bytes): string
    {
        return str_repeat('가', intdiv($bytes, 2)) . ($bytes % 2 === 1 ? 'a' : '');
    }

    /** 두 번째 승인 템플릿. 변수 이름이 T1 과 달라, 어느 쪽이 저장됐는지 헷갈릴 수 없다. */
    private function seedSecondTemplate(App $app): void
    {
        $app->db()->insert('alimtalk_templates', ['tpl_code' => 'T2', 'senderkey' => 'SK1',
            'name' => '두 번째 안내', 'content' => '#{받는분}께 #{주소지} 안내드립니다',
            'status' => 'A', 'insp_status' => 'APR', 'enabled' => 1,
            'fetched_at' => '2026-09-17 10:00:00']);
    }

    /** 알림톡을 켜고 T1 을 이은 다음, 그 템플릿이 죽은 상태로 만든다. */
    private function seedDeadTemplate(App $app, bool $leaveAlimtalkOn = true): void
    {
        $this->seedTemplate($app);
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크'],
        ]);
        if (!$leaveAlimtalkOn) {
            $this->post($app, '/admin/settings/notifications/save', [
                'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset', 'mail' => '1',
            ]);
        }
        $app->db()->update('alimtalk_templates', ['enabled' => 0], 'tpl_code = :c', ['c' => 'T1']);
    }

    /** 체크박스 하나의 마크업만 잘라 온다 — disabled·checked 를 그 칸에 대해서만 묻기 위해서다. */
    private static function checkbox(string $html, string $name): string
    {
        self::assertMatchesRegularExpression('/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/', $html);
        preg_match('/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/', $html, $m);

        return $m[0];
    }

    /** 이벤트 한 묶음의 <details> 안쪽만 잘라 온다. 묶음이 일곱이라 화면 전체를 물으면 옆 묶음의 값을 본다. */
    private static function section(string $html, string $event): string
    {
        $start = strpos($html, 'name="event" value="' . $event . '"');
        self::assertNotFalse($start, $event . ' 묶음이 화면에 없습니다.');
        $end = strpos($html, '</form>', $start);
        self::assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    #[DataProvider('connectionProvider')]
    public function testShowsEveryEventAndLocksPhoneColumnsWhereNotPossible(array $dbConfig): void
    {
        $html = $this->body($this->get($this->adminApp($dbConfig), '/admin/settings/notifications'));

        self::assertStringContainsString('비밀번호 재설정', $html);
        self::assertStringContainsString('이메일 인증', $html);
        self::assertStringContainsString('이메일로만', $html);

        // 전화로 보낼 수 없는 알림은 그 두 칸을 켤 수 없어야 한다 — 저장할 때만 거절하는
        // 것은 "제공했다가 거절한다"는 같은 결함의 다른 모습이다.
        $verify = self::section($html, 'email_verify');
        self::assertStringContainsString('disabled', self::checkbox($verify, 'sms'));
        self::assertStringContainsString('disabled', self::checkbox($verify, 'alimtalk'));
        // 대조군: 전화로 보낼 수 있는 알림은 잠기지 않는다.
        $reset = self::section($html, 'password_reset');
        self::assertStringNotContainsString('disabled', self::checkbox($reset, 'sms'));
    }

    #[DataProvider('connectionProvider')]
    public function testSavingATextBodyWithAnUnknownVariableFails(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'],
            'event' => 'password_reset', 'mail' => '1', 'sms' => '1', 'sms_body' => '#{주문번호}',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringContainsString('주문번호', $html);
        $section = self::section($html, 'password_reset');
        // 거절당한 입력이 화면에서 사라지면 관리자는 방금 쓴 본문을 다시 써야 한다.
        // textarea 안에 있는지를 묻는다 — 화면 어딘가에 그 글자가 있는지만 물으면
        // 되돌려 준 입력 자체가 단언을 채워, 오류 표시를 통째로 지워도 통과한다.
        self::assertMatchesRegularExpression(
            '/<textarea[^>]*name="sms_body"[^>]*>#\{주문번호\}<\/textarea>/', $section);
        // 그리고 어느 칸이 왜 거절됐는지 그 칸에 표시돼 있어야 한다.
        self::assertStringContainsString('이 알림이 제공하지 않는 변수가 있습니다', $section);
        self::assertStringContainsString('is-invalid', $section);
    }

    #[DataProvider('connectionProvider')]
    public function testCommentVolumeIsCalledOut(array $dbConfig): void
    {
        $html = $this->body($this->get($this->adminApp($dbConfig), '/admin/settings/notifications'));
        self::assertStringContainsString('발송량', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCannotOpenTheNotificationSettings(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->assertLoginRedirect(
            $this->get($app, '/admin/settings/notifications'), '/admin/settings/notifications');
    }

    /** 저장은 묶음 하나씩이다 — 한 묶음을 저장해도 다른 묶음의 설정은 그대로여야 한다. */
    #[DataProvider('connectionProvider')]
    public function testSavingOneEventLeavesTheOthersAlone(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'],
            'event' => 'password_reset', 'sms' => '1', 'sms_body' => '#{사이트명} 재설정 #{링크}',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(['sms'], $app->notifySettings()->channelsFor('password_reset'));
        // 손대지 않은 묶음은 기본값 그대로다.
        self::assertSame(['mail'], $app->notifySettings()->channelsFor('email_verify'));
        $html = $this->body($this->get($app, '/admin/settings/notifications', ['saved' => 'password_reset']));
        self::assertStringContainsString('「비밀번호 재설정」 알림 설정을 저장했습니다.', $html);
    }

    /**
     * 요구 1. 저장한 뒤 템플릿이 죽으면 channelsFor() 는 알림톡을 빼고 templateFor() 는
     * null 을 준다 — 화면이 그 사실만 그리면 관리자는 자기가 켠 채널이 이유 없이 스스로
     * 꺼진 것을 본다. 죽은 템플릿의 코드를 이름으로 불러 주어야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testADeadAlimtalkTemplateIsNamedInsteadOfSilentlyTurningTheChannelOff(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedTemplate($app);
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크'],
        ]);
        self::assertSame(['alimtalk'], $app->notifySettings()->channelsFor('password_reset'));

        // 카카오 승인이 풀렸다 — 관리자는 아무것도 다시 손대지 않았다.
        $app->db()->update('alimtalk_templates', ['enabled' => 0], 'tpl_code = :c', ['c' => 'T1']);

        $section = self::section(
            $this->body($this->get($app, '/admin/settings/notifications')), 'password_reset');
        self::assertSame([], $app->notifySettings()->channelsFor('password_reset'));
        // 코드를 부르는 곳이 **안내 문장**인지 묻는다. 같은 카드의 지우기 라벨도 코드를
        // 인쇄하므로, 카드 전체를 상대로 'T1' 만 물으면 둘 중 하나가 코드 부르기를
        // 그만두어도 통과한다 — 한 커밋에서 함께 태어난 두 기능이 서로를 가려 준다.
        self::assertStringContainsString('템플릿(T1)을 더는 쓸 수 없습니다', $section);
        // 체크는 관리자가 고른 그대로 남는다 — 꺼진 것으로 그려 두면 다른 칸만 고쳐
        // 저장하는 순간 "켜 두었다"는 사실이 조용히 지워진다.
        self::assertStringContainsString('checked', self::checkbox($section, 'alimtalk'));
    }

    /** 대조군. 관리자가 알림톡을 그냥 꺼 둔 경우에 멀쩡한 템플릿을 죽었다고 말하면 안 된다. */
    #[DataProvider('connectionProvider')]
    public function testALiveTemplateIsNotCalledDeadJustBecauseTheChannelIsOff(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedTemplate($app);
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크'],
        ]);
        // 알림톡만 끈다. 저장소의 tpl_code·var_map 은 그대로 남는다(save() 가 지우지 않는다).
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset', 'mail' => '1',
        ]);

        $section = self::section(
            $this->body($this->get($app, '/admin/settings/notifications')), 'password_reset');
        self::assertStringNotContainsString('더는 쓸 수 없습니다', $section);
        // 멀쩡한 템플릿이므로 「켜는 순간 이대로 나갑니다」는 참이고, 적혀 있어야 한다.
        self::assertStringContainsString('알림톡을 켜는 순간 이대로 나갑니다', $section);
        self::assertStringNotContainsString('checked', self::checkbox($section, 'alimtalk'));
        // 다시 켤 때 다시 고르지 않아도 되도록, 저장된 연결은 화면에 되살아나 있어야 한다.
        self::assertMatchesRegularExpression('/name="var_map\[고객명\]".*?<option value="이름" selected/s', $section);
    }

    /**
     * 요구 2. smsBody() 는 채널이 꺼지면 빈 문자열이다. 그 값을 그대로 칸에 넣으면
     * 문자를 껐다 돌아온 관리자는 자기 본문이 지워진 빈 칸을 본다.
     */
    #[DataProvider('connectionProvider')]
    public function testAStoredSmsBodyStaysVisibleWhileTheChannelIsOff(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'sms' => '1', 'sms_body' => '#{사이트명} 비밀번호를 #{링크} 에서 재설정하세요',
        ]);
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset', 'mail' => '1',
        ]);
        self::assertSame('', $app->notifySettings()->smsBody('password_reset'));

        $section = self::section(
            $this->body($this->get($app, '/admin/settings/notifications')), 'password_reset');
        self::assertStringContainsString('#{사이트명} 비밀번호를 #{링크} 에서 재설정하세요', $section);
        self::assertStringContainsString('문자 채널이 꺼져 있어', $section);
    }

    /**
     * 요구 3. InboxChannel 은 comment_new 하나만 적을 줄 알고, 로그인하지 못하는 사람에게
     * 가는 알림은 알림함에 넣어 봐야 읽을 사람이 없다. 그 칸은 눌리고 나서 거절당할 것이
     * 아니라 처음부터 잠겨 있어야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheInboxCheckboxIsLockedWhereItCanNeverDeliver(array $dbConfig): void
    {
        $html = $this->body($this->get($this->adminApp($dbConfig), '/admin/settings/notifications'));

        foreach (['password_reset', 'email_verify', 'welcome', 'signup_attempt', 'social_email_verify'] as $event) {
            self::assertStringContainsString('disabled',
                self::checkbox(self::section($html, $event), 'inbox'), $event);
        }
        // 대조군: 알림함이 실제로 적을 줄 아는 유일한 알림은 잠기지 않는다.
        self::assertStringNotContainsString('disabled',
            self::checkbox(self::section($html, 'comment_new'), 'inbox'));
    }

    /**
     * **꺼진 채널의 칸을 고친 값이 저장돼야 한다.** 칸을 고칠 수 있게 내주면서 저장은
     * 버리면, 관리자는 「저장했습니다」를 보고 나서 옛 본문이 돌아온 것을 본다 —
     * 성공처럼 보이는 유실이다. 본문을 보여 주기로 한 결정(R117)이 만든 구멍이다.
     */
    #[DataProvider('connectionProvider')]
    public function testEditingASwitchedOffChannelsBodyIsActuallySaved(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'sms' => '1', 'sms_body' => '첫 번째 본문 #{링크}',
        ]);

        // 문자는 끈 채로 본문만 고친다 — 화면이 실제로 보내는 모양 그대로다.
        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'mail' => '1', 'sms_body' => '고친 본문 #{이름}',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertFalse($app->notifySettings()->isOn('password_reset', 'sms'));
        self::assertSame('고친 본문 #{이름}',
            $app->notifySettings()->formValues()['password_reset']['sms_body_stored']);
        $section = self::section(
            $this->body($this->get($app, '/admin/settings/notifications')), 'password_reset');
        self::assertStringContainsString('고친 본문 #{이름}', $section);
        self::assertStringNotContainsString('첫 번째 본문', $section);
        // 지금은 안 나간다는 사실과, 고쳐 둔 값이 남는다는 사실을 둘 다 말해야 한다.
        self::assertStringContainsString('문자 채널이 꺼져 있어', $section);
        self::assertStringContainsString('고쳐 저장해 두면 그대로 보관되고', $section);
        self::assertStringContainsString('칸을 비우고 저장하면 지워집니다', $section);
    }

    /** 알림톡도 같다 — 꺼진 채로 고친 변수 연결이 저장돼야 한다. */
    #[DataProvider('connectionProvider')]
    public function testEditingASwitchedOffChannelsTemplateMappingIsActuallySaved(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedTemplate($app);
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크'],
        ]);

        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'mail' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '사이트명', '주소' => '링크'],
        ]);

        self::assertFalse($app->notifySettings()->isOn('password_reset', 'alimtalk'));
        self::assertSame(['고객명' => '사이트명', '주소' => '링크'],
            $app->notifySettings()->formValues()['password_reset']['alimtalk_var_map']);
        $section = self::section(
            $this->body($this->get($app, '/admin/settings/notifications')), 'password_reset');
        self::assertStringContainsString('알림톡 채널이 꺼져 있어', $section);
        self::assertMatchesRegularExpression('/name="var_map\[고객명\]".*?<option value="사이트명" selected/s', $section);
    }

    /** 채널만 끄는 저장(본문·템플릿을 싣지 않는다)은 예전처럼 저장된 값을 지키지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testTurningAChannelOffWithoutSendingItsContentStillKeepsTheStoredContent(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedTemplate($app);
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'sms' => '1', 'sms_body' => '지켜야 할 본문 #{링크}', 'alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크'],
        ]);

        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset', 'mail' => '1',
        ]);

        // 끄기 자체가 성공했는지 먼저 못 박는다 — 끄기를 거절하는 구현에서도 "값이
        // 그대로다"는 참이 되므로, 그 단언만으로는 아무것도 증명하지 못한다.
        self::assertSame(['mail'], $app->notifySettings()->channelsFor('password_reset'));
        $values = $app->notifySettings()->formValues()['password_reset'];
        self::assertSame('지켜야 할 본문 #{링크}', $values['sms_body_stored']);
        self::assertSame(['고객명' => '이름', '주소' => '링크'], $values['alimtalk_var_map']);
        self::assertSame('T1', $values['alimtalk_tpl_code']);
    }

    /**
     * **문자의 한계는 글자가 아니라 EUC-KR 바이트다.** maxlength 는 글자를 세므로 한글
     * 1,207자는 브라우저를 통과하지만 2,414바이트라 Dispatch 가 전건 거절한다 — 저장하면
     * "켜져 있다고 답하면서 아무것도 보낼 수 없는" 상태가 만들어진다.
     */
    #[DataProvider('connectionProvider')]
    public function testABodyTooLongInEucKrBytesIsRefusedRatherThanStored(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $long = str_repeat('가', 1207);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'sms' => '1', 'sms_body' => $long,
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('본문이 너무 깁니다', $this->body($response));
        self::assertNotContains('sms', $app->notifySettings()->channelsFor('password_reset'));
        self::assertSame('', $app->notifySettings()->formValues()['password_reset']['sms_body_stored']);
    }

    /** 문자로 옮길 수 없는 글자(이모지 등)도 같은 자리에서 거절한다. */
    #[DataProvider('connectionProvider')]
    public function testABodyWithCharactersSmsCannotCarryIsRefused(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'sms' => '1', 'sms_body' => '안녕하세요 🙂 #{링크}',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('문자로 보낼 수 없는 글자가 있습니다', $this->body($response));
        self::assertNotContains('sms', $app->notifySettings()->channelsFor('password_reset'));
    }

    /** 꺼진 채로도 저장되므로, 꺼진 채로 밀어 넣는 길이 열려 있으면 안 된다. */
    #[DataProvider('connectionProvider')]
    public function testAnUnsendableBodyCannotBeSmuggledInWhileTheChannelIsOff(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'mail' => '1', 'sms_body' => str_repeat('가', 1207),
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('', $app->notifySettings()->formValues()['password_reset']['sms_body_stored']);
    }

    /**
     * 한계가 저장 버튼을 누른 뒤에야 나타나면 늦다 — 지금 몇 바이트인지, 그리고 SMS 와
     * LMS 중 어느 쪽으로 나가는지(요금이 갈리는 자리다) 보여준다. 경계는 상수에서 끌어
     * 쓴다: 기대 문장을 MessageText::SMS_BYTES 로 짓기 때문에, 화면이 그 숫자를 따로
     * 적어 두면(상수가 바뀌는 날, 또는 지금 당장 틀린 숫자를 적으면) 어긋나 깨진다.
     * 본문은 경계 **정확히**와 경계 **+1바이트**로 만든다 — 갈림길 위에 세워 둔다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheScreenShowsHowManyBytesTheBodyUsesAndWhichChannelItBecomes(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $boundary = MessageText::SMS_BYTES;
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'sms' => '1', 'sms_body' => self::bodyOfBytes($boundary),
        ]);
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_changed',
            'sms' => '1', 'sms_body' => self::bodyOfBytes($boundary + 1),
        ]);

        $html = $this->body($this->get($app, '/admin/settings/notifications'));
        $atBoundary = self::section($html, 'password_reset');
        $overBoundary = self::section($html, 'password_changed');
        $sms = sprintf('아직 %s바이트 안이라 SMS 로 나갑니다', number_format($boundary));
        $lms = sprintf('%s바이트를 넘어 LMS 로 나갑니다', number_format($boundary));

        self::assertStringContainsString(
            '<strong>' . number_format($boundary) . '바이트</strong>', $atBoundary);
        self::assertStringContainsString($sms, $atBoundary);
        self::assertStringNotContainsString($lms, $atBoundary);

        self::assertStringContainsString(
            '<strong>' . number_format($boundary + 1) . '바이트</strong>', $overBoundary);
        self::assertStringContainsString($lms, $overBoundary);
        self::assertStringNotContainsString($sms, $overBoundary);

        // 한계도 화면에 적혀 있어야 한다 — 숫자만 보여 주고 어디까지인지 말하지 않으면
        // 관리자는 여전히 저장 버튼을 눌러 봐야 안다.
        self::assertStringContainsString(
            '최대 ' . number_format(MessageText::LMS_BYTES) . '바이트', $atBoundary);

        // 본문이 없는 묶음은 제 숫자(0)를 재고, 있지도 않은 본문의 갈래를 말하지 않는다.
        $empty = self::section($html, 'comment_new');
        self::assertStringContainsString('<strong>0바이트</strong>', $empty);
        self::assertStringNotContainsString($sms, $empty);
        self::assertStringNotContainsString($lms, $empty);
    }

    /**
     * **꺼진 채널에 죽은 참조가 남은 카드가 「켜는 순간 이대로 나갑니다」라고 말하면 안
     * 된다** — 켜면 422 로 거절당한다. 예전에는 죽었다는 문장이 「알림톡을 켜 두었을
     * 때만」 나와서, 하필 이 상태에서 유일하게 정직한 문장이 빠져 있었다.
     */
    #[DataProvider('connectionProvider')]
    public function testADeadTemplateIsNotDescribedAsReadyToSendWhileTheChannelIsOff(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedDeadTemplate($app, false);

        $section = self::section(
            $this->body($this->get($app, '/admin/settings/notifications')), 'password_reset');

        self::assertStringNotContainsString('알림톡을 켜는 순간 이대로 나갑니다', $section);
        // 안내 문장과 지우기 라벨은 **각각** 코드를 불러야 한다. 하나로 뭉쳐 물으면
        // 나머지 하나가 코드를 잃어도 통과한다(둘의 합집합만 고정된다).
        self::assertStringContainsString('템플릿(T1)을 더는 쓸 수 없습니다', $section);
        self::assertStringContainsString('고를 수 없게 된 템플릿 설정(T1) 지우기', $section);
        self::assertStringContainsString('지금 이대로는 켤 수도 없습니다', $section);
    }

    /**
     * 죽은 참조를 버리는 길. 빈 <select> 의 뜻을 추측하지 않고 뜻이 하나뿐인 칸을 준다.
     * 지울 길이 없으면, 쓸 템플릿이 하나도 없는 사이트에서는 그 묶음의 모든 저장이
     * 거절되고(문자 본문을 고쳐도 함께 버려진다) 알림톡을 끄고 나면 참조가 영영 남는다.
     */
    #[DataProvider('connectionProvider')]
    public function testADeadTemplateReferenceCanBeDroppedExplicitly(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedDeadTemplate($app, false);
        $before = self::section(
            $this->body($this->get($app, '/admin/settings/notifications')), 'password_reset');
        self::assertStringContainsString('name="tpl_clear"', $before);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'mail' => '1', 'tpl_code' => '', 'tpl_clear' => '1',
        ]);

        self::assertSame(303, $response->getStatusCode());
        $values = $app->notifySettings()->formValues()['password_reset'];
        self::assertSame('', $values['alimtalk_tpl_code']);
        self::assertSame([], $values['alimtalk_var_map']);
        $after = self::section(
            $this->body($this->get($app, '/admin/settings/notifications')), 'password_reset');
        self::assertStringNotContainsString('name="tpl_clear"', $after);
        self::assertStringNotContainsString('더는 쓸 수 없습니다', $after);
    }

    /** 켠 채로 지우는 것은 앞뒤가 맞지 않는다 — 조용히 한쪽을 고르지 않고 이유를 말하며 거절한다. */
    #[DataProvider('connectionProvider')]
    public function testDroppingTheReferenceIsRefusedWhileAlimtalkIsOn(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedDeadTemplate($app);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'alimtalk' => '1', 'tpl_code' => '', 'tpl_clear' => '1',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $section = self::section($this->body($response), 'password_reset');
        self::assertStringContainsString('알림톡을 켠 채로는 템플릿 설정을 지울 수 없습니다', $section);
        self::assertStringContainsString('is-invalid', $section);
        self::assertSame('T1',
            $app->notifySettings()->formValues()['password_reset']['alimtalk_tpl_code']);
    }

    /** 새 템플릿을 함께 고르면 그쪽이 이긴다 — 지우기는 고를 것을 고르지 않았을 때만 적용된다. */
    #[DataProvider('connectionProvider')]
    public function testChoosingANewTemplateBeatsTheClearCheckbox(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedDeadTemplate($app);
        $this->seedSecondTemplate($app);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'alimtalk' => '1', 'tpl_code' => 'T2', 'tpl_clear' => '1',
            'var_map' => ['받는분' => '이름', '주소지' => '링크'],
        ]);

        self::assertSame(303, $response->getStatusCode());
        $values = $app->notifySettings()->formValues()['password_reset'];
        self::assertSame('T2', $values['alimtalk_tpl_code']);
        self::assertSame(['받는분' => '이름', '주소지' => '링크'], $values['alimtalk_var_map']);
    }

    /**
     * 쓸 수 있는 템플릿이 하나도 없는데 알림톡이 켜진 채로 저장돼 있으면 그 묶음의 모든
     * 저장이 422 로 거절된다. 그 화면이 아무것도 표시하지 않으면 — 예전에는 tpl_code
     * 힌트가 `if ($noTemplates)` 의 else 안에 있어 그렸을 자리가 아예 없었다 — 관리자는
     * 「저장하지 못했습니다」만 보고 어디를 고쳐야 할지 알 수 없다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheCardWithNoUsableTemplateMarksWhatItIsRefusing(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedDeadTemplate($app);

        // 화면이 실제로 보내는 모양: 체크는 켜진 채 남아 있고, 고를 option 이 없어 빈 값이 나간다.
        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'mail' => '1', 'alimtalk' => '1', 'tpl_code' => '', 'sms_body' => '',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $section = self::section($this->body($response), 'password_reset');
        self::assertStringContainsString('is-invalid', $section);
        self::assertStringContainsString('사용 중인 승인 템플릿을 골라 주세요', $section);
        self::assertStringContainsString('쓸 수 있는 승인 템플릿이 없습니다', $section);
        // 저장이 거절된 화면에서 하필 가장 중요한 사실이 사라지면 안 된다.
        self::assertStringContainsString('더는 쓸 수 없습니다', $section);
        // 빠져나갈 길도 같은 화면에 있어야 한다.
        self::assertStringContainsString('name="tpl_clear"', $section);
    }

    /**
     * **빈 칸으로 저장하는 것과 칸을 아예 안 보내는 것은 다른 사실이다.** 둘을 한데 묶어
     * 두면 문자를 끈 채로는 본문을 지울 수 없고(빈 값이 무시된다), 켠 채로도 지울 수
     * 없다(빈 값이 거절된다) — 저장된 본문을 이 화면에서 영영 못 지운다. 2계획의 전화번호
     * 칸이 같은 뭉개기로 반대 방향 사고를 냈다: 거기서는 안 보낸 것을 "지우라"로 읽었다.
     */
    #[DataProvider('connectionProvider')]
    public function testEmptyingTheBodyOfASwitchedOffChannelActuallyDeletesIt(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'sms' => '1', 'sms_body' => '지울 본문 #{링크}',
        ]);

        // 문자를 끄고 칸을 비워 저장한다 — 화면이 실제로 보내는 모양 그대로다.
        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'mail' => '1', 'sms_body' => '',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('',
            $app->notifySettings()->formValues()['password_reset']['sms_body_stored']);
        $section = self::section(
            $this->body($this->get($app, '/admin/settings/notifications')), 'password_reset');
        self::assertStringNotContainsString('지울 본문', $section);
        // 지운 뒤에도 「이 본문으로 나갑니다」가 남아 있으면 가리킬 본문이 없는 문장이 된다.
        self::assertStringNotContainsString('문자 채널이 꺼져 있어', $section);
    }

    /** 본문이 한 번도 없었던 묶음에도 그 문장이 붙으면 안 된다 — 같은 거짓말의 다른 입구다. */
    #[DataProvider('connectionProvider')]
    public function testTheOffChannelNoticeIsSilentWhenThereIsNoStoredBody(array $dbConfig): void
    {
        $html = $this->body($this->get($this->adminApp($dbConfig), '/admin/settings/notifications'));

        self::assertStringNotContainsString('문자 채널이 꺼져 있어', self::section($html, 'welcome'));
        self::assertStringNotContainsString('알림톡 채널이 꺼져 있어', self::section($html, 'welcome'));
    }

    /**
     * 거절당한 이유가 길이일 때 화면이 저장된 옛 본문의 크기를 보여주면, 관리자는
     * 무엇을 얼마나 줄여야 하는지 알 수 없다 — 숫자는 방금 거절당한 그 본문의 것이어야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheRejectedBodysOwnByteCountIsShownOnThe422(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'sms' => '1', 'sms_body' => str_repeat('가', 1207),
        ]);

        self::assertSame(422, $response->getStatusCode());
        $section = self::section($this->body($response), 'password_reset');
        self::assertStringContainsString('<strong>2,414바이트</strong>', $section);
        self::assertStringNotContainsString('<strong>0바이트</strong>', $section);
    }

    /**
     * 업그레이드가 이벤트를 없앤 사이 열려 있던 폼이 그 키로 저장을 시도하면 save() 는
     * 422 로 거절하는데, 그 오류를 받아 줄 묶음이 화면에 없다 — 그리지 않으면 422 인데
     * 화면은 평소와 똑같아 관리자는 저장이 안 된 줄도 모른다.
     */
    #[DataProvider('connectionProvider')]
    public function testAnUnknownEventKeySaysWhyItWasRefused(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'promotional_sms', 'mail' => '1',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('알 수 없는 알림입니다.', $this->body($response));
        // 정상 화면과 구별되지 않으면 안 된다 — 이 문구는 성공한 화면에는 절대 없다.
        self::assertStringNotContainsString('알 수 없는 알림입니다.',
            $this->body($this->get($app, '/admin/settings/notifications')));
    }

    /**
     * 요구 3의 "이유를 함께"는 별개의 약속이다. 화면 전체를 상대로 물으면 여섯 묶음 중
     * 하나만 적어도 통과하므로, 묶음마다 제 칸 옆에 적혔는지 따로 묻는다.
     */
    #[DataProvider('connectionProvider')]
    public function testEveryLockedControlCarriesItsReasonInsideItsOwnCard(array $dbConfig): void
    {
        $html = $this->body($this->get($this->adminApp($dbConfig), '/admin/settings/notifications'));

        foreach (['password_reset', 'password_changed', 'welcome', 'comment_new',
            'email_verify', 'signup_attempt', 'social_email_verify'] as $event) {
            $section = self::section($html, $event);
            $inboxLocked = $event !== 'comment_new';
            $phoneLocked = in_array($event, ['email_verify', 'signup_attempt', 'social_email_verify'], true);

            // 있음/없음만 물으면 안 된다. 전화 불가 안내는 한 묶음 안에서 알림톡 칸과
            // 문자 칸 옆에 **각각** 붙으므로, 둘 중 하나를 지워도 "있다"는 여전히 참이다.
            // 개수를 세야 칸마다 붙었는지 답할 수 있다.
            self::assertSame($inboxLocked ? 1 : 0,
                substr_count($section, '지금은 새 댓글·답글 알림만 받습니다'), $event);
            self::assertSame($phoneLocked ? 2 : 0,
                substr_count($section, '이 알림은 이메일로만 보낼 수 있습니다'), $event);
        }
    }

    /** 저장 안내는 방금 저장한 그 알림의 이름을 불러야 한다 — 아무 이름이나 부르면 안 된다. */
    #[DataProvider('connectionProvider')]
    public function testTheSavedNoticeNamesTheEventThatWasActuallySaved(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'email_verify', 'mail' => '1',
        ]);
        self::assertStringContainsString('saved=email_verify', $response->getHeaderLine('Location'));

        $html = $this->body($this->get($app, '/admin/settings/notifications', ['saved' => 'email_verify']));
        self::assertStringContainsString('「이메일 인증」 알림 설정을 저장했습니다.', $html);
        self::assertStringNotContainsString('「비밀번호 재설정」 알림 설정을 저장했습니다.', $html);

        // 카탈로그가 모르는 키를 쿼리에 실어도 문장을 지어내지 않는다.
        $bogus = $this->body($this->get($app, '/admin/settings/notifications', ['saved' => 'promotional_sms']));
        self::assertStringNotContainsString('알림 설정을 저장했습니다.', $bogus);
    }

    /**
     * 카드의 **모든** 칸은 422 를 건너 살아남아야 한다. 지우기 체크만 예외였다: 화면이
     * 「알림톡을 끄고 저장하거나…」라고 시키는 대로 따른 관리자가 303 과 「저장했습니다」를
     * 받고도 참조는 그대로인 화면을 보게 된다 — 시킨 대로 했는데 조용히 버려지는 수정이다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheClearTickSurvivesA422SoObeyingTheScreenActuallyWorks(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedDeadTemplate($app);

        // 1) 켠 채로 지우려 한다 — 거절당하고, 알림톡을 끄라는 안내를 받는다.
        $refused = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'alimtalk' => '1', 'tpl_code' => '', 'tpl_clear' => '1',
        ]);
        self::assertSame(422, $refused->getStatusCode());
        $section = self::section($this->body($refused), 'password_reset');
        self::assertStringContainsString('checked', self::checkbox($section, 'tpl_clear'));

        // 2) 안내대로 알림톡만 끄고 다시 저장한다. 체크가 살아 있어야 실제로 지워진다.
        $ok = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'mail' => '1', 'tpl_code' => '', 'tpl_clear' => '1',
        ]);
        self::assertSame(303, $ok->getStatusCode());
        self::assertSame('', $app->notifySettings()->formValues()['password_reset']['alimtalk_tpl_code']);
    }

    /** 체크하지 않은 422 에서 체크가 생겨나도 안 된다 — 되살리기는 되돌리기지 켜기가 아니다. */
    #[DataProvider('connectionProvider')]
    public function testAnUntickedClearBoxStaysUntickedOnA422(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedDeadTemplate($app);

        $refused = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'alimtalk' => '1', 'tpl_code' => '',
        ]);

        self::assertSame(422, $refused->getStatusCode());
        $section = self::section($this->body($refused), 'password_reset');
        self::assertStringNotContainsString('checked', self::checkbox($section, 'tpl_clear'));
    }

    /**
     * 「아직 고른 템플릿이 없습니다」는 화면에 그려진 값을 두고 하는 말이어야 한다.
     * 저장소를 보면, 변수 연결이 덜 된 채 거절당한 422 에서 T1 이 selected 로 그려진
     * 바로 위에 그 문장을 적게 된다 — 아직 저장되지 않았을 뿐인데.
     */
    #[DataProvider('connectionProvider')]
    public function testTheCardDoesNotClaimNoTemplateWhileShowingOneSelected(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedTemplate($app);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'alimtalk' => '1', 'tpl_code' => 'T1', 'var_map' => ['고객명' => '이름'],
        ]);

        self::assertSame(422, $response->getStatusCode());
        $section = self::section($this->body($response), 'password_reset');
        self::assertStringContainsString('<option value="T1" selected>', $section);
        self::assertStringNotContainsString('아직 고른 템플릿이 없습니다', $section);
        // 거절 이유는 그대로 표시돼 있어야 한다 — 위 단언이 "아무 말도 없다"로 통과하지 않게.
        self::assertStringContainsString('템플릿 변수에 넣을 값을 모두 골라 주세요', $section);

        // 대조군: 정말로 고르지 않았으면 그때는 말해야 한다.
        $none = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'alimtalk' => '1', 'tpl_code' => '',
        ]);
        self::assertStringContainsString('아직 고른 템플릿이 없습니다',
            self::section($this->body($none), 'password_reset'));
    }

    /**
     * 화면이 "죽었다"고 판단한 것은 화면을 그린 순간의 일이다. 그 사이 템플릿이 되살아나면
     * (관리자가 다시 사용으로 바꾸거나 다시 가져오면 — 이 화면의 안내가 권하는 바로 그
     * 일이다) 낡은 체크 하나가 멀쩡한 설정을 지우게 된다. 저장이 스스로 다시 따져야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testAStaleClearTickForARevivedTemplateIsRefusedNotObeyed(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedDeadTemplate($app, false);
        // 화면을 열어 둔 사이 승인이 돌아왔다.
        $app->db()->update('alimtalk_templates', ['enabled' => 1], 'tpl_code = :c', ['c' => 'T1']);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'mail' => '1', 'tpl_code' => '', 'tpl_clear' => '1',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $values = $app->notifySettings()->formValues()['password_reset'];
        self::assertSame('T1', $values['alimtalk_tpl_code']);
        self::assertSame(['고객명' => '이름', '주소' => '링크'], $values['alimtalk_var_map']);
        $section = self::section($this->body($response), 'password_reset');
        self::assertStringContainsString('지금은 다시 쓸 수 있습니다', $section);
        self::assertStringContainsString('아무것도 지우지 않았습니다', $section);
        // 되살아났으므로 지우기 칸 자체가 없다 — 그래도 422 는 무언가를 가리켜야 한다.
        self::assertStringNotContainsString('name="tpl_clear"', $section);
        self::assertStringContainsString('is-invalid', $section);
    }

    /**
     * 되살아났는데 알림톡도 켜져 있으면 두 가드가 모두 걸린다. 덜 구체적인 쪽이 먼저
     * 나오면 관리자는 템플릿이 돌아왔다는 말을 듣지 못한 채 「알림톡을 끄라」는 지시만
     * 받고, 그대로 따른 뒤에야 진짜 이유를 듣는다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheRevivedTemplateRefusalComesBeforeTheChannelIsOnRefusal(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        $this->seedDeadTemplate($app);   // 알림톡은 켜진 채로 둔다
        $app->db()->update('alimtalk_templates', ['enabled' => 1], 'tpl_code = :c', ['c' => 'T1']);

        $response = $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'alimtalk' => '1', 'tpl_code' => '', 'tpl_clear' => '1',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $section = self::section($this->body($response), 'password_reset');
        self::assertStringContainsString('지우려던 템플릿(T1)을 지금은 다시 쓸 수 있습니다', $section);
        self::assertStringNotContainsString('알림톡을 켠 채로는 템플릿 설정을 지울 수 없습니다', $section);
        self::assertSame('T1',
            $app->notifySettings()->formValues()['password_reset']['alimtalk_tpl_code']);
    }

    /** 알리고가 없으면 여기서 무엇을 켜든 전화로는 나가지 않는다 — 그 사실을 화면이 말해야 한다. */
    #[DataProvider('connectionProvider')]
    public function testTheScreenSaysPhoneChannelsCannotSendWhileAligoIsNotConnected(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);

        $html = $this->body($this->get($app, '/admin/settings/notifications'));
        self::assertStringContainsString('알리고 계정이 연결되어 있지 않습니다', $html);

        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $after = $this->body($this->get($app, '/admin/settings/notifications'));
        self::assertStringNotContainsString('알리고 계정이 연결되어 있지 않습니다', $after);
        // 계정은 있지만 채널 스위치가 꺼져 있는 것도 "켜도 나가지 않는다"는 같은 사실이다.
        self::assertStringContainsString('알림톡 발송과 문자 발송이 모두 꺼져 있습니다', $after);
    }
}
