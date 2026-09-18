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
    }
}
