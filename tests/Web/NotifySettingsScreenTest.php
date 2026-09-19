<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

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
        // 거절당한 입력이 화면에서 사라지면 관리자는 방금 쓴 본문을 다시 써야 한다.
        self::assertStringContainsString('#{주문번호}', self::section($html, 'password_reset'));
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
        self::assertStringContainsString('T1', $section);
        self::assertStringContainsString('더는 쓸 수 없습니다', $section);
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

    /** 한계가 저장 버튼을 누른 뒤에야 나타나면 늦다 — 지금 몇 바이트인지 보여준다. */
    #[DataProvider('connectionProvider')]
    public function testTheScreenShowsHowManyBytesTheBodyUses(array $dbConfig): void
    {
        $app = $this->adminApp($dbConfig);
        // '안녕하세요'(10) + 공백(1) + '#{링크}'(#·{·}·링크 = 1+1+1+4) = 18바이트.
        $this->post($app, '/admin/settings/notifications/save', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset',
            'sms' => '1', 'sms_body' => '안녕하세요 #{링크}',
        ]);

        $section = self::section(
            $this->body($this->get($app, '/admin/settings/notifications')), 'password_reset');
        self::assertStringContainsString('<strong>18바이트</strong>', $section);
        self::assertStringContainsString('90바이트까지는 SMS', $section);
        // 다른 묶음은 제 본문(없음)을 잰다 — 한 묶음의 숫자를 일곱 곳에 베끼지 않는다.
        self::assertStringContainsString('<strong>0바이트</strong>', self::section(
            $this->body($this->get($app, '/admin/settings/notifications')), 'password_changed'));
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

            self::assertSame($inboxLocked, str_contains($section, '지금은 새 댓글·답글 알림만 받습니다'), $event);
            self::assertSame($phoneLocked, str_contains($section, '이 알림은 이메일로만 보낼 수 있습니다'), $event);
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
