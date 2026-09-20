<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\Settings;
use GnuCms\Aligo\SettingsRepository;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingsTest extends DatabaseTestCase
{
    private function settings(array $config): Settings
    {
        return new Settings(new SettingsRepository($this->freshDatabase($config)), new SecretCipher('test-secret'));
    }

    /** 키는 암호문으로 저장하고 화면에는 절대 돌려주지 않는다 — 소스 보기에 키가 나오면 안 된다. 저장 여부만 알린다. */
    #[DataProvider('connectionProvider')]
    public function testApiKeyIsEncryptedAndNeverReturnedToTheForm(array $config): void
    {
        $repository = new SettingsRepository($this->freshDatabase($config));
        $settings = new Settings($repository, new SecretCipher('test-secret'));
        $settings->save(['user_id' => 'shop', 'api_key' => 'live-key-value',
            'sender' => '02-1234-5678', 'senderkey' => 'SK1', 'channel_name' => '@상점']);

        $form = $settings->formValues();
        self::assertSame('', $form['api_key']);
        self::assertTrue($form['api_key_set']);
        self::assertSame('0212345678', $form['sender']);
        self::assertSame('live-key-value', $settings->runtime()['api_key']);
        self::assertStringNotContainsString('live-key-value', $repository->all()['api_key']);
    }

    /**
     * 칸은 평소 비어 있으므로(페이지에 키를 싣지 않는다) 빈 칸은 "기존 키 유지"다. 눈 아이콘으로
     * 저장된 키를 칸에 불러온 뒤(api_key_loaded) 비우고 저장한 것만 삭제다. 따로 삭제 체크는 없다.
     */
    #[DataProvider('connectionProvider')]
    public function testBlankKeyKeepsTheStoredOneUnlessItWasLoadedIntoTheField(array $config): void
    {
        $settings = $this->settings($config);
        $base = ['user_id' => 'shop', 'sender' => '0212345678', 'senderkey' => 'SK1'];
        $settings->save($base + ['api_key' => 'first-key']);
        $settings->save($base + ['api_key' => '']);
        self::assertSame('first-key', $settings->runtime()['api_key']);

        $settings->save($base + ['api_key' => '', 'api_key_loaded' => '1']);
        self::assertNull($settings->runtime());
        self::assertFalse($settings->formValues()['api_key_set']);
    }

    /** 비밀값의 비밀키가 바뀌어 저장된 암호문을 못 읽어도 화면은 열려야 한다. */
    #[DataProvider('connectionProvider')]
    public function testAnUnreadableStoredKeyStillLetsTheFormOpen(array $config): void
    {
        $repository = new SettingsRepository($this->freshDatabase($config));
        $repository->save(['user_id' => 'shop', 'api_key' => 'v2:not-really-a-cipher-text']);
        $settings = new Settings($repository, new SecretCipher('test-secret'));

        self::assertSame('', $settings->formValues()['api_key']);
        self::assertTrue($settings->formValues()['api_key_set']);
    }

    /**
     * 알리고는 계정당 API 키가 하나다. 문자 API 의 key 와 알림톡 API 의 apikey 는 같은
     * 값이고, 두 스펙(smartsms.aligo.in/admin/api/spec.html, /alimapi.html) 모두 그 값을
     * "인증용 API Key" 라고만 부른다. 한때 "알림톡 전용 키" 칸을 예비로 두었는데, 있지도
     * 않은 값을 묻는 칸이었다. 그 이름으로 무엇이 들어와도 저장하지도 돌려주지도 않는다.
     */
    #[DataProvider('connectionProvider')]
    public function testThereIsOneApiKeyAndNoAlimtalkOnlyOne(array $config): void
    {
        $repository = new SettingsRepository($this->freshDatabase($config));
        $settings = new Settings($repository, new SecretCipher('test-secret'));
        $settings->save(['user_id' => 'shop', 'api_key' => 'shared', 'alimtalk_api_key' => 'special',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        self::assertSame('shared', $settings->runtime()['api_key']);
        self::assertArrayNotHasKey('alimtalk_api_key', $settings->runtime());
        self::assertArrayNotHasKey('alimtalk_api_key_set', $settings->formValues());
        self::assertArrayNotHasKey('alimtalk_api_key', $repository->all());
    }

    #[DataProvider('connectionProvider')]
    public function testSendingIsOffUntilTurnedOnPerChannel(array $config): void
    {
        $settings = $this->settings($config);
        $settings->save(['user_id' => 'shop', 'api_key' => 'k', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        self::assertFalse($settings->isEnabled('sms'));
        self::assertFalse($settings->isEnabled('at'));

        $settings->setEnabled('sms', true);
        self::assertTrue($settings->isEnabled('sms'));
        self::assertFalse($settings->isEnabled('at'));
    }

    #[DataProvider('connectionProvider')]
    public function testRejectsABadSenderNumber(array $config): void
    {
        $this->expectException(DomainError::class);
        $this->settings($config)->save(['user_id' => 'shop', 'api_key' => 'k',
            'sender' => '123', 'senderkey' => 'SK1']);
    }

    #[DataProvider('connectionProvider')]
    public function testRetypingTheSameApiKeyLeavesChannelsEnabled(array $config): void
    {
        $settings = $this->settings($config);
        $base = ['user_id' => 'shop', 'api_key' => 'live-key-value', 'sender' => '0212345678', 'senderkey' => 'SK1'];
        $settings->save($base);
        $settings->setEnabled('sms', true);
        $settings->setEnabled('at', true);

        // 관리자가 같은 API 키를 다시 입력해도 암호문은 매번 달라지므로,
        // 평문 비교 없이는 계정이 바뀐 것처럼 보여 채널이 꺼질 수 있다.
        $settings->save($base);

        self::assertTrue($settings->isEnabled('sms'));
        self::assertTrue($settings->isEnabled('at'));
    }

    #[DataProvider('connectionProvider')]
    public function testDifferentApiKeyTurnsChannelsOff(array $config): void
    {
        $settings = $this->settings($config);
        $settings->save(['user_id' => 'shop', 'api_key' => 'live-key-value',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $settings->setEnabled('sms', true);
        $settings->setEnabled('at', true);

        $settings->save(['user_id' => 'shop', 'api_key' => 'a-different-key',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        self::assertFalse($settings->isEnabled('sms'));
        self::assertFalse($settings->isEnabled('at'));
    }
}
