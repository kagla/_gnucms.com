<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Notify\Events;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** 공통 발송 방식과 알림별 메일·문자 선택을 실제 컨트롤러로 검증한다. */
final class NotifySettingsScreenTest extends WebTestCase
{
    private function adminApp(array $config, string $mode = 'alimtalk_sms'): App
    {
        $app = $this->makeApp($config);
        $id = $app->users()->create('notify-admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $this->get($app, '/login');
        session_start();
        $_SESSION['user_id'] = $id;
        $_SESSION['session_epoch'] = 0;
        session_write_close();
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'K', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        $app->aligo()->settings->setEnabled('sms', in_array($mode, ['sms', 'alimtalk_sms'], true));
        $app->aligo()->settings->setEnabled('at', in_array($mode, ['alimtalk', 'alimtalk_sms'], true));
        return $app;
    }

    private function seedTemplate(App $app, string $code = 'T1', string $status = 'A'): void
    {
        $app->db()->insert('alimtalk_templates', ['tpl_code' => $code, 'senderkey' => 'SK1',
            'name' => '재설정 안내', 'content' => '#{고객명}님 #{주소} 에서 재설정하세요',
            'status' => $status, 'insp_status' => 'APR', 'enabled' => 0, 'fetched_at' => '2026-09-17 10:00:00']);
    }

    private function save(App $app, array $input): \Psr\Http\Message\ResponseInterface
    {
        return $this->post($app, '/admin/settings/notifications/save', $input +
            ['csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset']);
    }

    private static function section(string $html, string $event): string
    {
        $start = strpos($html, 'name="event" value="' . $event . '"');
        self::assertNotFalse($start);
        $end = strpos($html, '</form>', $start);
        self::assertNotFalse($end);
        return substr($html, $start, $end - $start);
    }

    #[DataProvider('connectionProvider')]
    public function testEveryEventIsEditableAndMembershipComesFirst(array $config): void
    {
        $html = $this->body($this->get($this->adminApp($config), '/admin/settings/notifications'));
        foreach (Events::ALL as $event => $details) self::section($html, $event);
        self::assertLessThan(strpos($html, 'name="event" value="order_pending"'), strpos($html, 'name="event" value="welcome"'));
        self::assertStringContainsString('회원가입 완료 안내', $html);
        self::assertStringContainsString('알림·발송 설정', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testOnlyMailAndPhoneAreSelectedAndInboxHasNoControl(array $config): void
    {
        $html = $this->body($this->get($this->adminApp($config), '/admin/settings/notifications'));
        $reset = self::section($html, 'password_reset');
        self::assertStringContainsString('name="mail"', $reset);
        self::assertStringContainsString('name="phone"', $reset);
        foreach (['sms', 'alimtalk', 'inbox'] as $old) self::assertStringNotContainsString('name="' . $old . '"', $html);
        self::assertStringNotContainsString('name="phone"', self::section($html, 'email_verify'));
        self::assertStringContainsString('이 알림은 이메일 전용입니다', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testSavingChoicesLeavesOtherEventsAloneAndInboxMandatory(array $config): void
    {
        $app = $this->adminApp($config, 'sms');
        self::assertSame(303, $this->save($app, ['delivery_choice' => '1', 'phone' => '1'])->getStatusCode());
        self::assertSame(['sms'], $app->notifySettings()->channelsFor('password_reset'));
        self::assertSame(['mail', 'sms', 'inbox'], $app->notifySettings()->channelsFor('welcome'));
        self::assertSame(303, $this->save($app, ['event' => 'welcome', 'delivery_choice' => '1'])->getStatusCode());
        self::assertSame(['inbox'], $app->notifySettings()->channelsFor('welcome'));
    }

    #[DataProvider('connectionProvider')]
    public function testGlobalSmsModeShowsNoAlimtalkTemplatePicker(array $config): void
    {
        $app = $this->adminApp($config, 'sms');
        $this->seedTemplate($app);
        $html = $this->body($this->get($app, '/admin/settings/notifications'));
        self::assertStringNotContainsString('name="tpl_code"', $html);
        self::assertStringContainsString('문자만', $html);
    }

    #[DataProvider('connectionProvider')]
    public function testApprovedProviderTemplateIsAvailableWithoutLocalEnabling(array $config): void
    {
        $app = $this->adminApp($config);
        $this->seedTemplate($app, 'T1', 'R');
        $html = $this->body($this->get($app, '/admin/settings/notifications'));
        self::assertStringContainsString('value="T1"', $html);
        self::assertSame(303, $this->save($app, ['tpl_code' => 'T1', 'var_map' => ['주소' => '링크']])->getStatusCode());
        self::assertSame(['고객명' => '이름', '주소' => '링크'], $app->notifySettings()->templateFor('password_reset')['var_map']);
    }

    #[DataProvider('connectionProvider')]
    public function testUnknownSmsVariableIsRejectedWithoutChangingStoredBody(array $config): void
    {
        $app = $this->adminApp($config, 'sms');
        $this->save($app, ['sms_body' => '#{이름}님 #{링크}']);
        $response = $this->save($app, ['sms_body' => '#{주문번호}']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('주문번호', $this->body($response));
        self::assertSame('#{이름}님 #{링크}', $app->notifySettings()->smsBody('password_reset'));
    }

    #[DataProvider('connectionProvider')]
    public function testNonScalarAndOverLimitSmsBodiesAreRejected(array $config): void
    {
        $app = $this->adminApp($config, 'sms');
        foreach ([['bad'], str_repeat('가', 1001)] as $bad) {
            self::assertSame(422, $this->save($app, ['sms_body' => $bad])->getStatusCode());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testContentEditKeepsDeliveryChoicesAndHiddenContent(array $config): void
    {
        $app = $this->adminApp($config, 'sms');
        $this->save($app, ['delivery_choice' => '1', 'sms_body' => '#{이름}님 #{링크}']);
        self::assertSame(303, $this->save($app, ['sms_title' => '안내'])->getStatusCode());
        $row = $app->notifySettings()->formValues()['password_reset'];
        self::assertSame(['mail' => false, 'phone' => false], $row['selection']);
        self::assertSame('#{이름}님 #{링크}', $row['sms_body_stored']);
        self::assertSame('안내', $row['sms_title_stored']);
    }

    #[DataProvider('connectionProvider')]
    public function testDeadTemplateIsNamedAndCanBeExplicitlyCleared(array $config): void
    {
        $app = $this->adminApp($config);
        $this->seedTemplate($app);
        $this->save($app, ['tpl_code' => 'T1', 'var_map' => ['주소' => '링크']]);
        $app->db()->update('alimtalk_templates', ['status' => 'S'], 'tpl_code = :c', ['c' => 'T1']);
        $html = $this->body($this->get($app, '/admin/settings/notifications'));
        $reset = self::section($html, 'password_reset');
        self::assertStringContainsString('T1', $reset);
        self::assertStringContainsString('name="tpl_clear"', $reset);
        $this->save($app, ['tpl_code' => '']);
        self::assertSame('T1', $app->notifySettings()->formValues()['password_reset']['alimtalk_tpl_code']);
        self::assertSame(303, $this->save($app, ['tpl_clear' => '1'])->getStatusCode());
        self::assertSame('', $app->notifySettings()->formValues()['password_reset']['alimtalk_tpl_code']);
    }

    #[DataProvider('connectionProvider')]
    public function testRevivedTemplateCannotBeErasedByAStaleClearTick(array $config): void
    {
        $app = $this->adminApp($config);
        $this->seedTemplate($app);
        $this->save($app, ['tpl_code' => 'T1', 'var_map' => ['주소' => '링크']]);
        $response = $this->save($app, ['tpl_clear' => '1']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('지금은 다시 쓸 수', $this->body($response));
        self::assertSame('T1', $app->notifySettings()->formValues()['password_reset']['alimtalk_tpl_code']);
    }

    #[DataProvider('connectionProvider')]
    public function testNewTemplateSelectionWinsOverClearAndUnknownMappingFails(array $config): void
    {
        $app = $this->adminApp($config);
        $this->seedTemplate($app);
        self::assertSame(422, $this->save($app, ['tpl_code' => 'T1', 'var_map' => ['주소' => '주문번호']])->getStatusCode());
        self::assertSame(303, $this->save($app, ['tpl_code' => 'T1', 'tpl_clear' => '1', 'var_map' => ['주소' => '링크']])->getStatusCode());
        self::assertSame('T1', $app->notifySettings()->templateFor('password_reset')['tpl_code']);
    }

    #[DataProvider('connectionProvider')]
    public function testMailAndSmsHaveChannelPanelsAndPreviewDoesNotSave(array $config): void
    {
        $app = $this->adminApp($config, 'sms');
        $html = $this->body($this->get($app, '/admin/settings/notifications'));
        self::assertStringContainsString('data-notify-editor-panel="mail"', $html);
        self::assertStringContainsString('data-notify-editor-panel="phone"', $html);
        self::assertStringContainsString('data-notify-search', $html);
        self::assertStringNotContainsString('문자 미리보기 (선택)', $html);
        $before = $app->notifySettings()->formValues()['password_reset']['sms_body_stored'];
        $response = $this->post($app, '/admin/settings/notifications/sms-preview', [
            'csrf_token' => $_SESSION['csrf_token'], 'event' => 'password_reset', 'sms_body' => '#{이름}님 #{링크}', 'editor_channel' => 'phone']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($before, $app->notifySettings()->formValues()['password_reset']['sms_body_stored']);
        self::assertSame([], $app->db()->select('SELECT * FROM ' . $app->db()->table('message_jobs')));
    }
}
