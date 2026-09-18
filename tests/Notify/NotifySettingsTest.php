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
    private function boot(array $config): NotifySettings
    {
        $db = $this->freshDatabase($config);
        $aligo = new AligoSettings(new AligoSettingsRepository($db), new SecretCipher('s'));
        $templates = new Templates($db, new AlimtalkApi(new FakeAligoTransport(), $aligo), $aligo);
        $db->insert('alimtalk_templates', ['tpl_code' => 'T1', 'senderkey' => 'SK1', 'name' => '재설정',
            'content' => '#{고객명}님 #{주소} 에서 재설정하세요', 'status' => 'A', 'insp_status' => 'APR',
            'enabled' => 1, 'fetched_at' => '2026-09-17 10:00:00']);

        return new NotifySettings(new SettingsRepository($db), $templates);
    }

    /** repository 를 직접 써서, NotifySettings::save() 검증을 거치지 않은(=업그레이드 전
     *  버전이 남겼을 법한) 값을 그대로 site_settings 에 심는다. */
    private function bootWithRawStorage(array $config, array $rawNotifySettings): NotifySettings
    {
        $db = $this->freshDatabase($config);
        $aligo = new AligoSettings(new AligoSettingsRepository($db), new SecretCipher('s'));
        $templates = new Templates($db, new AlimtalkApi(new FakeAligoTransport(), $aligo), $aligo);
        $repository = new SettingsRepository($db);
        $repository->save($rawNotifySettings);

        return new NotifySettings($repository, $templates);
    }

    /** boot() 과 같지만 alimtalk_templates 를 직접 건드릴 수 있게 Connection·Templates 도
     *  함께 돌려준다 — save() 뒤에 템플릿이 "운영 중에" 죽는 상황(승인 취소, 삭제, 본문
     *  변경)을 재현하는 데 쓴다. */
    private function bootWithTemplateAccess(array $config): array
    {
        $db = $this->freshDatabase($config);
        $aligo = new AligoSettings(new AligoSettingsRepository($db), new SecretCipher('s'));
        $templates = new Templates($db, new AlimtalkApi(new FakeAligoTransport(), $aligo), $aligo);
        $db->insert('alimtalk_templates', ['tpl_code' => 'T1', 'senderkey' => 'SK1', 'name' => '재설정',
            'content' => '#{고객명}님 #{주소} 에서 재설정하세요', 'status' => 'A', 'insp_status' => 'APR',
            'enabled' => 1, 'fetched_at' => '2026-09-17 10:00:00']);

        return [new NotifySettings(new SettingsRepository($db), $templates), $db, $templates];
    }

    #[DataProvider('connectionProvider')]
    public function testMailIsTheOnlyChannelOnByDefault(array $config): void
    {
        $settings = $this->boot($config);
        self::assertSame(['mail'], $settings->channelsFor('password_reset'));
        self::assertSame(['inbox'], $settings->channelsFor('comment_new'));
    }

    #[DataProvider('connectionProvider')]
    public function testTextBodyIsStoredAndRead(array $config): void
    {
        $settings = $this->boot($config);
        $settings->save('password_reset', ['mail' => '1', 'sms' => '1',
            'sms_body' => '#{이름}님 #{링크} 에서 재설정하세요']);

        self::assertSame(['mail', 'sms'], $settings->channelsFor('password_reset'));
        self::assertSame('#{이름}님 #{링크} 에서 재설정하세요', $settings->smsBody('password_reset'));
    }

    #[DataProvider('connectionProvider')]
    public function testTextBodyMayOnlyUseVariablesTheEventProvides(array $config): void
    {
        $settings = $this->boot($config);

        try {
            $settings->save('password_reset', ['sms' => '1', 'sms_body' => '#{주문번호} 안내']);
            self::fail('없는 변수는 거절해야 한다');
        } catch (DomainError $e) {
            self::assertStringContainsString('주문번호', $e->details()['sms_body']);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testAlimtalkCannotBeTurnedOnUntilEveryVariableIsMapped(array $config): void
    {
        $settings = $this->boot($config);

        try {
            $settings->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
                'var_map' => ['고객명' => '이름']]);
            self::fail('매핑이 빠지면 거절해야 한다');
        } catch (DomainError $e) {
            self::assertStringContainsString('주소', $e->details()['var_map']);
        }

        $settings->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        self::assertContains('alimtalk', $settings->channelsFor('password_reset'));
        self::assertSame(['고객명' => '이름', '주소' => '링크'],
            $settings->templateFor('password_reset')['var_map']);
    }

    #[DataProvider('connectionProvider')]
    public function testEventsThatCannotUseAPhoneRefuseThoseChannels(array $config): void
    {
        $settings = $this->boot($config);

        $this->expectException(DomainError::class);
        $settings->save('email_verify', ['sms' => '1', 'sms_body' => '#{링크}']);
    }

    /**
     * 템플릿 변수를 존재하지 않는 코어 변수로 매핑한 것도 "매핑 안 됨"과 같은 취급이다 —
     * 가리키는 곳이 없는 매핑은 매핑이 없는 것과 실제로 다를 바가 없다.
     */
    #[DataProvider('connectionProvider')]
    public function testMappingToAVariableTheEventDoesNotHaveCountsAsIncomplete(array $config): void
    {
        $settings = $this->boot($config);

        try {
            $settings->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
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
        $settings = $this->boot($config);
        $settings->save('password_reset', ['mail' => '1', 'sms' => '1',
            'sms_body' => '#{이름}님 #{링크} 에서 재설정하세요']);
        self::assertSame(['mail', 'sms'], $settings->channelsFor('password_reset'));

        $settings->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        self::assertSame(['alimtalk'], $settings->channelsFor('password_reset'));
    }

    /**
     * 관리자가 저장해 둔 설정이 업그레이드로 카탈로그에서 빠진 이벤트를 가리킬 수 있다.
     * 아무것도 설정하지 않은 것과 똑같이 답해야 한다 — 조용히 예외를 던지지도, 지워진
     * 이벤트의 낡은 값을 살려 보여주지도 않는다.
     */
    #[DataProvider('connectionProvider')]
    public function testUnknownEventKeyIsSafeEverywhere(array $config): void
    {
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
    public function testNonScalarSmsBodyIsRejectedNotCastToAWarning(array $config): void
    {
        $settings = $this->boot($config);

        try {
            $settings->save('password_reset', ['sms' => '1', 'sms_body' => ['안 됨']]);
            self::fail('배열 본문은 거절해야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('sms_body', $e->details());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testSaveRejectsAnUnknownEvent(array $config): void
    {
        $settings = $this->boot($config);

        try {
            $settings->save('promotional_sms', ['mail' => '1']);
            self::fail('알 수 없는 이벤트는 거절해야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('event', $e->details());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testFormValuesBundlesEveryKnownEventForTheScreen(array $config): void
    {
        $settings = $this->boot($config);
        $settings->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        $values = $settings->formValues();

        self::assertSame([
            'password_reset', 'password_changed', 'welcome', 'comment_new',
            'email_verify', 'signup_attempt', 'social_email_verify',
        ], array_keys($values));

        self::assertSame(['alimtalk'], $values['password_reset']['channels']);
        self::assertSame(['tpl_code' => 'T1', 'var_map' => ['고객명' => '이름', '주소' => '링크']],
            $values['password_reset']['template']);
        self::assertSame(['inbox'], $values['comment_new']['channels']);
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
        [$settings, , $templates] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        self::assertContains('alimtalk', $settings->channelsFor('password_reset'));

        $templates->setEnabled('T1', false);

        self::assertNotContains('alimtalk', $settings->channelsFor('password_reset'));
        self::assertFalse($settings->isOn('password_reset', 'alimtalk'));
        self::assertNull($settings->templateFor('password_reset'));
    }

    /** 템플릿 사본 자체가 알리고 목록에서 사라져 Templates::fetch() 가 그 행을 지우는
     *  경우도 같다 — find() 가 null 을 돌려주는 것만 다르다. */
    #[DataProvider('connectionProvider')]
    public function testChannelsForDropsAlimtalkWhenItsTemplateRowIsGone(array $config): void
    {
        [$settings, $db] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
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
        [$settings, $db] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        self::assertContains('alimtalk', $settings->channelsFor('password_reset'));

        $db->update('alimtalk_templates',
            ['content' => '#{고객명}님 #{주소} #{유효시간} 뒤 만료, 재설정하세요'],
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
        [$settings, , $templates] = $this->bootWithTemplateAccess($config);
        $settings->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        $templates->setEnabled('T1', false);
        $values = $settings->formValues();

        self::assertNotContains('alimtalk', $values['password_reset']['channels']);
        self::assertNull($values['password_reset']['template']);
        self::assertSame('T1', $values['password_reset']['alimtalk_tpl_code']);
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
        $settings = $this->boot($config);
        $settings->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        self::assertNotNull($settings->templateFor('password_reset'));

        // 알림톡만 끄고 저장한다 — 입력에 tpl_code·var_map 이 없어도 SettingsRepository
        // 는 기존 값을 지우지 않으므로 T1 매핑은 그대로 DB 에 남는다.
        $settings->save('password_reset', ['mail' => '1']);

        self::assertSame(['mail'], $settings->channelsFor('password_reset'));
        self::assertFalse($settings->isOn('password_reset', 'alimtalk'));
        self::assertNull($settings->templateFor('password_reset'));
    }

    /** smsBody() 도 같은 함정이 있다 — 문자를 끄면 본문은 저장소에 남지만 더는
     *  내주지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testSmsBodyIsEmptyWhenSmsIsOffEvenThoughTheBodyRemainsStored(array $config): void
    {
        $settings = $this->boot($config);
        $settings->save('password_reset', ['sms' => '1',
            'sms_body' => '#{이름}님 #{링크} 에서 재설정하세요']);
        self::assertNotSame('', $settings->smsBody('password_reset'));

        $settings->save('password_reset', ['mail' => '1']);

        self::assertSame(['mail'], $settings->channelsFor('password_reset'));
        self::assertFalse($settings->isOn('password_reset', 'sms'));
        self::assertSame('', $settings->smsBody('password_reset'));
    }
}
