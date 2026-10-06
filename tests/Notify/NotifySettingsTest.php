<?php

declare(strict_types=1);

namespace GnuCms\Tests\Notify;

use GnuCms\Aligo\AlimtalkApi;
use GnuCms\Aligo\Settings as AligoSettings;
use GnuCms\Aligo\SettingsRepository as AligoSettingsRepository;
use GnuCms\Aligo\Templates;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Notify\NotifySettings;
use GnuCms\Notify\SettingsRepository;
use GnuCms\Tests\Support\DatabaseTestCase;
use GnuCms\Tests\Support\FakeAligoTransport;
use PHPUnit\Framework\Attributes\DataProvider;

final class NotifySettingsTest extends DatabaseTestCase
{
    private string $phoneMode = "off";
    private function boot(array $config): NotifySettings
    {
        $db = $this->freshDatabase($config);
        $aligo = new AligoSettings(new AligoSettingsRepository($db), new SecretCipher('s'));
        $aligo->save(['user_id' => 'shop', 'api_key' => 'K', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        $templates = new Templates($db, new AlimtalkApi(new FakeAligoTransport(), $aligo), $aligo);
        $db->insert('alimtalk_templates', ['tpl_code' => 'T1', 'senderkey' => 'SK1', 'name' => '재설정',
            'content' => '#{고객명}님 #{주소} 에서 재설정하세요', 'status' => 'A', 'insp_status' => 'APR',
            'enabled' => 1, 'fetched_at' => '2026-09-17 10:00:00']);

        return new NotifySettings(new SettingsRepository($db), $templates, null, fn (): array => $this->phoneStatus());
    }

    /** repository 를 직접 써서, NotifySettings::save() 검증을 거치지 않은(=업그레이드 전
     *  버전이 남겼을 법한) 값을 그대로 site_settings 에 심는다. */
    private function bootWithRawStorage(array $config, array $rawNotifySettings): NotifySettings
    {
        $db = $this->freshDatabase($config);
        $aligo = new AligoSettings(new AligoSettingsRepository($db), new SecretCipher('s'));
        $aligo->save(['user_id' => 'shop', 'api_key' => 'K', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        $templates = new Templates($db, new AlimtalkApi(new FakeAligoTransport(), $aligo), $aligo);
        $repository = new SettingsRepository($db);
        $repository->save($rawNotifySettings);

        return new NotifySettings($repository, $templates, null, fn (): array => $this->phoneStatus());
    }

    /** boot() 과 같지만 alimtalk_templates 를 직접 건드릴 수 있게 Connection·Templates 도
     *  함께 돌려준다 — save() 뒤에 템플릿이 "운영 중에" 죽는 상황(승인 취소, 삭제, 본문
     *  변경)을 재현하는 데 쓴다. */
    private function bootWithTemplateAccess(array $config): array
    {
        $db = $this->freshDatabase($config);
        $aligo = new AligoSettings(new AligoSettingsRepository($db), new SecretCipher('s'));
        $aligo->save(['user_id' => 'shop', 'api_key' => 'K', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        $templates = new Templates($db, new AlimtalkApi(new FakeAligoTransport(), $aligo), $aligo);
        $db->insert('alimtalk_templates', ['tpl_code' => 'T1', 'senderkey' => 'SK1', 'name' => '재설정',
            'content' => '#{고객명}님 #{주소} 에서 재설정하세요', 'status' => 'A', 'insp_status' => 'APR',
            'enabled' => 1, 'fetched_at' => '2026-09-17 10:00:00']);

        return [new NotifySettings(new SettingsRepository($db), $templates, null, fn (): array => $this->phoneStatus()), $db, $templates];
    }

    private function phoneStatus(): array
    {
        return ['sms_enabled' => in_array($this->phoneMode, ['sms', 'both'], true),
            'alimtalk_enabled' => in_array($this->phoneMode, ['at', 'both'], true)];
    }

    #[DataProvider('connectionProvider')]
    public function testMailIsTheOnlyChannelOnByDefault(array $config): void
    {
        $this->phoneMode = "off";
        $settings = $this->boot($config);
        self::assertSame(['mail'], $settings->channelsFor('password_reset'));
        self::assertSame(['mail', 'inbox'], $settings->channelsFor('comment_new'));
    }

    #[DataProvider('connectionProvider')]
    public function testTextBodyIsStoredAndRead(array $config): void
    {
        $this->phoneMode = "sms";
        $settings = $this->boot($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'sms_body' => '#{이름}님 #{링크} 에서 재설정하세요']);

        self::assertSame(['mail', 'sms'], $settings->channelsFor('password_reset'));
        self::assertSame('#{이름}님 #{링크} 에서 재설정하세요', $settings->smsBody('password_reset'));
    }

    #[DataProvider('connectionProvider')]
    public function testTextBodyMayOnlyUseVariablesTheEventProvides(array $config): void
    {
        $this->phoneMode = "sms";
        $settings = $this->boot($config);

        try {
            $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'sms_body' => '#{주문번호} 안내']);
            self::fail('없는 변수는 거절해야 한다');
        } catch (DomainError $e) {
            self::assertStringContainsString('주문번호', $e->details()['sms_body']);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testAlimtalkCannotBeTurnedOnUntilEveryVariableIsMapped(array $config): void
    {
        $this->phoneMode = "at";
        $settings = $this->boot($config);

        try {
            $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
                'var_map' => ['고객명' => '이름']]);
            self::fail('매핑이 빠지면 거절해야 한다');
        } catch (DomainError $e) {
            self::assertStringContainsString('주소', $e->details()['var_map']);
        }

        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        self::assertContains('alimtalk', $settings->channelsFor('password_reset'));
        self::assertSame(['고객명' => '이름', '주소' => '링크'],
            $settings->templateFor('password_reset')['var_map']);
    }

    #[DataProvider('connectionProvider')]
    public function testEventsThatCannotUseAPhoneRefuseThoseChannels(array $config): void
    {
        $this->phoneMode = "sms";
        $settings = $this->boot($config);

        $this->expectException(DomainError::class);
        $settings->save('email_verify', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'sms_body' => '#{링크}']);
    }

    #[DataProvider('connectionProvider')]
    public function testInboxIsMandatoryOnlyForCapableEvents(array $config): void
    {
        $this->phoneMode = 'off';
        $settings = $this->boot($config);
        foreach (['password_reset', 'email_verify', 'signup_attempt', 'social_email_verify'] as $event) {
            $settings->save($event, ['inbox' => '1']);
            self::assertFalse($settings->isOn($event, 'inbox'));
        }
        foreach (['welcome', 'password_changed', 'comment_new', 'order_paid'] as $event) {
            $settings->save($event, ['delivery_choice' => '1', 'inbox' => '0']);
            self::assertSame(['inbox'], $settings->channelsFor($event));
        }
    }

    /**
     * 이 검증이 생기기 전에 저장된 행(또는 DB 를 직접 고친 행)은 그대로 남아 있다. 읽는
     * 쪽이 걸러 주지 않으면 그 사이트는 "알림함이 켜져 있다"는 답만 참인 채로, 인증 링크가
     * 아무 데도 가지 않는 상태로 계속 돈다 — 지켜 주는 것은 검증이 아니라 이 필터다.
     */
    #[DataProvider('connectionProvider')]
    public function testAStoredInboxRowIsIgnoredWhereTheInboxCannotBeUsed(array $config): void
    {
        $this->phoneMode = "off";
        $settings = $this->bootWithRawStorage($config, [
            'email_verify.configured' => '1', 'email_verify.mail' => '0',
            'email_verify.inbox' => '1', 'email_verify.sms' => '0', 'email_verify.alimtalk' => '0',
        ]);

        self::assertSame(['mail'], $settings->channelsFor('email_verify'));
        self::assertFalse($settings->isOn('email_verify', 'inbox'));
        self::assertSame(['mail'], $settings->formValues()['email_verify']['channels']);
    }

    /** 화면이 켤 수 없는 칸을 꺼진 채로 그릴 수 있어야 한다 — 저장 때만 거절하는 것은 늦다. */
    #[DataProvider('connectionProvider')]
    public function testFormValuesSayWhichEventsCanUseTheInbox(array $config): void
    {
        $this->phoneMode = "off";
        $values = $this->boot($config)->formValues();

        self::assertTrue($values['comment_new']['inbox']);
        self::assertFalse($values['email_verify']['inbox']);
        self::assertTrue($values['welcome']['inbox']);
    }

    /**
     * 템플릿 변수를 존재하지 않는 코어 변수로 매핑한 것도 "매핑 안 됨"과 같은 취급이다 —
     * 가리키는 곳이 없는 매핑은 매핑이 없는 것과 실제로 다를 바가 없다.
     */
    #[DataProvider('connectionProvider')]
    public function testMappingToAVariableTheEventDoesNotHaveCountsAsIncomplete(array $config): void
    {
        $this->phoneMode = "at";
        $settings = $this->boot($config);

        try {
            $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
                'var_map' => ['고객명' => '이름', '주소' => '주문번호']]);
            self::fail('없는 코어 변수로의 매핑은 거절해야 한다');
        } catch (DomainError $e) {
            self::assertStringContainsString('주소', $e->details()['var_map']);
        }
    }

    /** 체크박스 폼은 매번 전체 채널 집합을 다시 보낸다 — 이전에 켠 채널이라도 이번
     *  입력에 없으면 꺼진다. save() 는 이벤트마다 부분 갱신이 아니라 전체 치환이다. */
    #[DataProvider('connectionProvider')]
    public function testSavingAgainReplacesTheWholeChannelSetForThatEvent(array $config): void
    {
        $this->phoneMode = "both";
        $settings = $this->boot($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'sms_body' => '#{이름}님 #{링크} 에서 재설정하세요']);
        self::assertSame(['mail', 'sms'], $settings->channelsFor('password_reset'));

        $this->phoneMode = 'at';
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        self::assertSame(['mail', 'alimtalk'], $settings->channelsFor('password_reset'));
    }

    /**
     * 관리자가 저장해 둔 설정이 업그레이드로 카탈로그에서 빠진 이벤트를 가리킬 수 있다.
     * 아무것도 설정하지 않은 것과 똑같이 답해야 한다 — 조용히 예외를 던지지도, 지워진
     * 이벤트의 낡은 값을 살려 보여주지도 않는다.
     */
    #[DataProvider('connectionProvider')]
    public function testUnknownEventKeyIsSafeEverywhere(array $config): void
    {
        $this->phoneMode = "off";
        $settings = $this->boot($config);

        self::assertSame([], $settings->channelsFor('promotional_sms'));
        self::assertFalse($settings->isOn('promotional_sms', 'mail'));
        self::assertNull($settings->templateFor('promotional_sms'));
        self::assertSame('', $settings->smsBody('promotional_sms'));
    }

    /**
     * 위 시나리오를 실제로 재현한다: NotifySettings::save() 의 검증을 거치지 않고(=지금
     * 버전이 카탈로그에서 이미 뺀 이벤트가 이전 버전에서 저장해 둔 값 그대로) repository
     * 에 직접 심는다. 그래도 읽기 쪽은 전부 "설정 없음"으로 답해야 하고, 화면 묶음에도
     * 나타나지 않아야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testUnknownEventKeyIsIgnoredEvenWhenRawDataIsStored(array $config): void
    {
        $this->phoneMode = "off";
        $settings = $this->bootWithRawStorage($config, [
            'promotional_sms.configured' => '1',
            'promotional_sms.mail' => '1',
            'promotional_sms.sms' => '1',
            'promotional_sms.sms_body' => '#{링크}',
            'promotional_sms.tpl_code' => 'T1',
            'promotional_sms.var_map' => '{"고객명":"이름"}',
        ]);

        self::assertSame([], $settings->channelsFor('promotional_sms'));
        self::assertFalse($settings->isOn('promotional_sms', 'mail'));
        self::assertNull($settings->templateFor('promotional_sms'));
        self::assertSame('', $settings->smsBody('promotional_sms'));
        self::assertArrayNotHasKey('promotional_sms', $settings->formValues());
    }

    /** save() 가 없어도, 저장소에 직접 잘못 심긴 값(수동 DB 편집·예전 버그)이라도
     *  channelsFor() 는 그 이벤트가 실제로 쓸 수 없는 채널을 절대 돌려주지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testChannelsForNeverReturnsAPhoneChannelTheEventCannotUse(array $config): void
    {
        $this->phoneMode = "off";
        $settings = $this->bootWithRawStorage($config, [
            'email_verify.configured' => '1',
            'email_verify.mail' => '1',
            'email_verify.sms' => '1',
            'email_verify.alimtalk' => '1',
        ]);

        $channels = $settings->channelsFor('email_verify');
        self::assertContains('mail', $channels);
        self::assertNotContains('sms', $channels);
        self::assertNotContains('alimtalk', $channels);
    }

    /** 배열처럼 스칼라가 아닌 입력이 들어와도 캐스팅 경고 없이 거절해야 한다 —
     *  이 분기의 Recipient 가 이미 겪은 것과 같은 모양의 입력이다. */
    #[DataProvider('connectionProvider')]
    public function testNonScalarTextFieldsAreRejectedWithoutWarnings(array $config): void
    {
        $this->phoneMode = "sms";
        $settings = $this->boot($config);

        foreach (['sms_body', 'sms_title', 'tpl_code'] as $field) {
            foreach ([['안 됨'], new \stdClass()] as $bad) {
                try {
                    $settings->save('password_reset', [$field => $bad]);
                    self::fail('배열·객체 입력은 거절해야 한다');
                } catch (DomainError $e) {
                    self::assertArrayHasKey($field, $e->details());
                }
            }
        }
    }

    #[DataProvider('connectionProvider')]
    public function testSaveRejectsAnUnknownEvent(array $config): void
    {
        $this->phoneMode = "off";
        $settings = $this->boot($config);

        try {
            $settings->save('promotional_sms', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', ]);
            self::fail('알 수 없는 이벤트는 거절해야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('event', $e->details());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testFormValuesBundlesEveryKnownEventForTheScreen(array $config): void
    {
        $this->phoneMode = "at";
        $settings = $this->boot($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        $values = $settings->formValues();

        self::assertSame([
            'password_reset', 'password_changed', 'welcome', 'comment_new',
            'order_pending', 'order_paid', 'order_cancelled', 'order_refunded', 'inquiry_replied',
            'order_confirmed', 'order_shipped', 'order_completed',
            'order_returning', 'order_returned', 'order_return_closed',
            'email_verify', 'signup_attempt', 'social_email_verify',
        ], array_keys($values));

        self::assertSame(['mail', 'alimtalk'], $values['password_reset']['channels']);
        self::assertSame(['tpl_code' => 'T1', 'var_map' => ['고객명' => '이름', '주소' => '링크']],
            $values['password_reset']['template']);
        self::assertSame(['mail', 'inbox'], $values['comment_new']['channels']);
        self::assertNull($values['comment_new']['template']);
        self::assertSame('', $values['comment_new']['sms_body']);
        self::assertSame('T1', $values['password_reset']['alimtalk_tpl_code']);
        self::assertSame('', $values['comment_new']['alimtalk_tpl_code']);
    }

    /**
     * save() 는 저장하는 순간에만 템플릿을 검증한다. 그 뒤 Templates::fetch() 가 카카오
     * 승인을 잃은 템플릿을 disable 할 수 있는데(Templates.php 자체 docblock에 적힌
     * 정상 동작이다), 관리자는 아무것도 다시 하지 않는다 — 그래도 채널·읽기 쪽 셋 다
     * "더는 못 쓴다"는 같은 답을 해야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testChannelsForDropsAlimtalkWhenItsTemplateIsDisabledLater(array $config): void
    {
        $this->phoneMode = "at";
        [$settings, $db, $templates] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        self::assertContains('alimtalk', $settings->channelsFor('password_reset'));

        $db->update('alimtalk_templates', ['status' => 'S'], 'tpl_code = :code', ['code' => 'T1']);

        self::assertNotContains('alimtalk', $settings->channelsFor('password_reset'));
        self::assertFalse($settings->isOn('password_reset', 'alimtalk'));
        self::assertNull($settings->templateFor('password_reset'));
    }

    /** 템플릿 사본 자체가 알리고 목록에서 사라져 Templates::fetch() 가 그 행을 지우는
     *  경우도 같다 — find() 가 null 을 돌려주는 것만 다르다. */
    #[DataProvider('connectionProvider')]
    public function testChannelsForDropsAlimtalkWhenItsTemplateRowIsGone(array $config): void
    {
        $this->phoneMode = "at";
        [$settings, $db] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        $db->delete('alimtalk_templates', 'tpl_code = :code', ['code' => 'T1']);

        self::assertNotContains('alimtalk', $settings->channelsFor('password_reset'));
        self::assertNull($settings->templateFor('password_reset'));
    }

    /**
     * Templates::fetch() 는 enabled 를 건드리지 않고도 본문(content)을 알리고 쪽 최신
     * 값으로 갱신할 수 있다. 새 변수가 본문에 추가되면, 저장해 둔 var_map 은 그 변수를
     * 모른 채로 남아 매핑이 다시 불완전해진다 — enabled 검사만으로는 이 경우를 잡지
     * 못하므로 본문 변수 전체를 다시 대조해야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testChannelsForDropsAlimtalkWhenTemplateContentGainsAnUnmappedVariable(array $config): void
    {
        $this->phoneMode = "at";
        [$settings, $db] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        self::assertContains('alimtalk', $settings->channelsFor('password_reset'));

        $db->update('alimtalk_templates',
            ['content' => '#{고객명}님 #{주소} #{비고} 뒤 만료, 재설정하세요'],
            'tpl_code = :code', ['code' => 'T1']);

        self::assertNotContains('alimtalk', $settings->channelsFor('password_reset'));
        self::assertNull($settings->templateFor('password_reset'));
    }

    /** 채널이 꺼진 진짜 이유(템플릿이 죽었다)를 화면이 말할 수 있어야 한다 — template
     *  이 null 이어도 원본 tpl_code 는 alimtalk_tpl_code 로 남아 있어야 "알림톡이
     *  꺼졌습니다"가 아니라 "템플릿 T1을 더는 쓸 수 없습니다"라고 말할 수 있다. */
    #[DataProvider('connectionProvider')]
    public function testFormValuesKeepsTheDeadTemplateCodeForTheScreenToExplain(array $config): void
    {
        $this->phoneMode = "at";
        [$settings, $db, $templates] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        $db->update('alimtalk_templates', ['status' => 'S'], 'tpl_code = :code', ['code' => 'T1']);
        $values = $settings->formValues();

        self::assertNotContains('alimtalk', $values['password_reset']['channels']);
        self::assertNull($values['password_reset']['template']);
        self::assertSame('T1', $values['password_reset']['alimtalk_tpl_code']);
    }

    /**
     * 화면은 "지금 쓸 수 있는가"(channels·template·sms_body)만으로는 어긋난 상태의 이유를
     * 말할 수 없다. 알림톡을 켜 두었는데 템플릿이 죽은 것과, 관리자가 알림톡을 그냥 꺼
     * 둔 것은 둘 다 template===null 이라 구별되지 않는다 — 구별하지 못하면 화면이 멀쩡한
     * 템플릿을 죽었다고 말하게 된다. 그래서 저장 원본을 함께 내준다.
     */
    #[DataProvider('connectionProvider')]
    public function testFormValuesAlsoCarriesTheRawStoredSettingsSoTheScreenCanExplainItself(array $config): void
    {
        $this->phoneMode = "both";
        [$settings, $db, $templates] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'sms_body' => '#{사이트명} 링크는 #{링크}', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        $db->update('alimtalk_templates', ['status' => 'S'], 'tpl_code = :code', ['code' => 'T1']);
        $dead = $settings->formValues()['password_reset'];
        self::assertNull($dead['template']);
        // 켜 두었다는 사실은 남는다 — 이것이 "템플릿이 죽었다"와 "그냥 껐다"를 가른다.
        self::assertTrue($dead['alimtalk_on']);
        self::assertSame(['고객명' => '이름', '주소' => '링크'], $dead['alimtalk_var_map']);

        // 채널을 모두 끈다. 저장소의 본문과 매핑은 save() 가 지우지 않는다.
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', ]);
        $off = $settings->formValues()['password_reset'];
        self::assertFalse($off['alimtalk_on']);
        self::assertSame('', $off['sms_body']);
        self::assertSame('#{사이트명} 링크는 #{링크}', $off['sms_body_stored']);
        self::assertSame(['고객명' => '이름', '주소' => '링크'], $off['alimtalk_var_map']);
    }

    /** 손으로 고쳐 넣은 var_map 이 JSON 이 아니거나 문자열 아닌 값을 담고 있어도, 원본을
     *  내주는 자리가 그걸 그대로 화면의 배열 첨자로 흘리지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testRawVarMapSurvivesBrokenStoredValues(array $config): void
    {
        $this->phoneMode = "off";
        [$settings, $db] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', ]);
        $db->insert('site_settings', ['setting_key' => 'notify.password_reset.var_map',
            'setting_value' => '{"고객명": ["배열"], "주소": "링크"}', 'updated_at' => '2026-09-17 10:00:00']);

        self::assertSame(['주소' => '링크'],
            $settings->formValues()['password_reset']['alimtalk_var_map']);
    }

    /**
     * save() 는 채널을 끌 때 그 채널의 매핑을 지우지 않는다 — 다시 켤 때 다시 고르지
     * 않아도 되게 하려는 의도된 동작이다. 하지만 그래서 channelsFor() 는 "꺼졌다"고
     * 답하는데 templateFor() 는 여전히 멀쩡한 tpl_code 를 내준다면, isOn() 을 먼저
     * 확인하지 않는 어떤 호출자든 꺼진 채널을 켜진 것처럼 믿게 된다. 둘은 같은 답을
     * 해야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testTemplateForIsNullWhenAlimtalkIsOffEvenThoughTheMappingRemainsStored(array $config): void
    {
        $this->phoneMode = "at";
        $settings = $this->boot($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        self::assertNotNull($settings->templateFor('password_reset'));

        // 알림톡만 끄고 저장한다 — 입력에 tpl_code·var_map 이 없어도 SettingsRepository
        // 는 기존 값을 지우지 않으므로 T1 매핑은 그대로 DB 에 남는다.
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', ]);

        self::assertSame(['mail'], $settings->channelsFor('password_reset'));
        self::assertFalse($settings->isOn('password_reset', 'alimtalk'));
        self::assertNull($settings->templateFor('password_reset'));
    }

    /**
     * "빈 값으로 보냈다"와 "아예 안 보냈다"는 다른 사실이다. 한데 묶으면 둘 중 하나는
     * 반드시 거짓말이 된다 — 여기서는 빈 값을 "안 보냈다"로 읽는 바람에 저장된 본문을
     * 지울 길이 아예 없었다(문자를 켜면 빈 본문은 거절되므로 그쪽 길도 막혀 있다).
     */
    #[DataProvider('connectionProvider')]
    public function testAnEmptySubmittedBodyDeletesWhileAnAbsentOneKeeps(array $config): void
    {
        $this->phoneMode = "sms";
        $settings = $this->boot($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'sms_body' => '#{이름}님 #{링크}']);

        // 키를 아예 안 보낸다 — 채널만 끄는 저장. 본문은 그대로 남아야 한다.
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', ]);
        self::assertSame('#{이름}님 #{링크}',
            $settings->formValues()['password_reset']['sms_body_stored']);

        // 빈 값으로 보낸다 — 관리자가 칸을 비우고 저장한 것이다. 지워져야 한다.
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', 'sms_body' => '']);
        self::assertSame('', $settings->formValues()['password_reset']['sms_body_stored']);
    }

    #[DataProvider('connectionProvider')]
    public function testAnEmptyBodyRestoresTheDefaultWhileSmsIsOn(array $config): void
    {
        $this->phoneMode = 'sms';
        $settings = $this->boot($config);
        $settings->save('password_reset', ['sms_body' => '#{링크}']);
        $settings->save('password_reset', ['sms_body' => '']);
        self::assertSame('', $settings->formValues()['password_reset']['sms_body_stored']);
        self::assertSame(\GnuCms\Notify\Events::defaultSmsBody('password_reset'), $settings->smsBody('password_reset'));
    }

    /**
     * tpl_code 는 일부러 다르다 — 빈 값을 "지우라"로 읽지 않는다. 저장된 템플릿이 죽으면
     * 화면의 <select> 에는 그 코드에 맞는 option 이 없어 빈 값이 나가는데, 그걸 지우기로
     * 읽으면 다른 칸만 고쳐 저장하는 순간 죽은 코드가 사라진다 — 화면이 「고르신
     * 템플릿(T1)을 더는 쓸 수 없습니다」라고 말할 수 있는 유일한 근거다.
     */
    #[DataProvider('connectionProvider')]
    public function testAnEmptyTemplateCodeDoesNotEraseTheStoredOne(array $config): void
    {
        $this->phoneMode = "at";
        [$settings, $db, $templates] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        $db->update('alimtalk_templates', ['status' => 'S'], 'tpl_code = :code', ['code' => 'T1']);

        // 죽은 템플릿 때문에 빈 값이 나가는 저장. 다른 칸만 고쳤을 뿐이다.
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', 'tpl_code' => '', 'sms_body' => '#{링크}']);

        $values = $settings->formValues()['password_reset'];
        self::assertSame('T1', $values['alimtalk_tpl_code']);
        self::assertSame(['고객명' => '이름', '주소' => '링크'], $values['alimtalk_var_map']);
    }

    /**
     * 죽은 참조를 버리는 **명시적인** 길. 빈 tpl_code 는 뜻이 둘이라 지우기로 읽을 수
     * 없지만(위 테스트), 그렇다고 지울 길이 없으면 쓸 템플릿이 하나도 없는 사이트에서는
     * 그 알림의 모든 저장이 거절되고 참조가 영영 남는다. 뜻이 하나뿐인 칸이 그 매듭을 푼다.
     */
    #[DataProvider('connectionProvider')]
    public function testTplClearDropsADeadTemplateReference(array $config): void
    {
        $this->phoneMode = "at";
        [$settings, $db, $templates] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        $db->update('alimtalk_templates', ['status' => 'S'], 'tpl_code = :code', ['code' => 'T1']);

        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', 'tpl_code' => '', 'tpl_clear' => '1']);

        $values = $settings->formValues()['password_reset'];
        self::assertSame('', $values['alimtalk_tpl_code']);
        self::assertSame([], $values['alimtalk_var_map']);
    }

    #[DataProvider('connectionProvider')]
    public function testDeadTemplateCanBeClearedWithPhoneSelected(array $config): void
    {
        $this->phoneMode = 'both';
        [$settings, $db] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['tpl_code' => 'T1', 'var_map' => ['주소' => '링크']]);
        $db->update('alimtalk_templates', ['status' => 'S'], 'tpl_code = :code', ['code' => 'T1']);
        $settings->save('password_reset', ['delivery_choice' => '1', 'phone' => '1', 'tpl_clear' => '1']);
        self::assertSame('', $settings->formValues()['password_reset']['alimtalk_tpl_code']);
        self::assertSame(['sms'], $settings->channelsFor('password_reset'));
    }

    /**
     * 저장된 템플릿을 지금도 쓸 수 있는지는 채널 스위치와 무관한 사실이다. templateFor()
     * 는 isOn() 게이트 때문에 꺼져 있으면 무조건 null 이라 그 질문에 답할 수 없다 —
     * 화면이 「꺼져 있지만 켜면 이대로 나갑니다」와 「켤 수조차 없습니다」를 가르려면
     * 게이트를 지나지 않은 답이 하나 필요하다.
     */
    #[DataProvider('connectionProvider')]
    public function testTemplateUsabilityIsReportedRegardlessOfTheChannelSwitch(array $config): void
    {
        $this->phoneMode = "at";
        [$settings, $db, $templates] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        // 알림톡을 꺼도 템플릿 자체는 멀쩡하다.
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', ]);
        $off = $settings->formValues()['password_reset'];
        self::assertNull($off['template']);
        self::assertTrue($off['alimtalk_template_usable']);

        // 승인이 풀리면 꺼져 있어도 "못 쓴다"가 되어야 한다.
        $db->update('alimtalk_templates', ['status' => 'S'], 'tpl_code = :code', ['code' => 'T1']);
        self::assertFalse(
            $settings->formValues()['password_reset']['alimtalk_template_usable']);
    }

    /**
     * 지우기 체크가 도착했을 때 "정말 죽었는가"는 저장이 스스로 다시 따져야 한다.
     * 화면의 가드만 믿으면, 화면을 그린 뒤 템플릿이 되살아난 사이에 도착한 낡은 체크
     * 하나가 멀쩡한 tpl_code·var_map 을 말없이 지운다 — channelsFor() 가 저장된 행을
     * 믿지 않고 validTemplate() 로 다시 묻는 것과 같은 자리, 같은 이유다.
     */
    #[DataProvider('connectionProvider')]
    public function testTplClearIsRefusedWhenTheTemplateCameBackToLife(array $config): void
    {
        $this->phoneMode = "at";
        [$settings, $db, $templates] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        $db->update('alimtalk_templates', ['status' => 'S'], 'tpl_code = :code', ['code' => 'T1']);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', ]);
        // 화면을 열어 둔 사이 승인이 돌아왔다.
        $db->update('alimtalk_templates', ['status' => 'A'], 'tpl_code = :code', ['code' => 'T1']);

        try {
            $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', 'tpl_code' => '', 'tpl_clear' => '1']);
            self::fail('되살아난 템플릿을 낡은 체크로 지워서는 안 된다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('tpl_clear', $e->details());
        }

        $values = $settings->formValues()['password_reset'];
        self::assertSame('T1', $values['alimtalk_tpl_code']);
        self::assertSame(['고객명' => '이름', '주소' => '링크'], $values['alimtalk_var_map']);
    }

    /** smsBody() 도 같은 함정이 있다 — 문자를 끄면 본문은 저장소에 남지만 더는
     *  내주지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testSmsBodyIsEmptyWhenSmsIsOffEvenThoughTheBodyRemainsStored(array $config): void
    {
        $this->phoneMode = "sms";
        $settings = $this->boot($config);
        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '1', 'sms_body' => '#{이름}님 #{링크} 에서 재설정하세요']);
        self::assertNotSame('', $settings->smsBody('password_reset'));

        $settings->save('password_reset', ['delivery_choice' => '1', 'mail' => '1', 'phone' => '0', ]);

        self::assertSame(['mail'], $settings->channelsFor('password_reset'));
        self::assertFalse($settings->isOn('password_reset', 'sms'));
        self::assertSame('', $settings->smsBody('password_reset'));
    }
}
