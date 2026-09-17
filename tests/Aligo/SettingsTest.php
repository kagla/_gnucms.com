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

    #[DataProvider('connectionProvider')]
    public function testApiKeyIsEncryptedAndNeverReturnedToTheForm(array $config): void
    {
        $settings = $this->settings($config);
        $settings->save(['user_id' => 'shop', 'api_key' => 'live-key-value',
            'sender' => '02-1234-5678', 'senderkey' => 'SK1', 'channel_name' => '@상점']);

        $form = $settings->formValues();
        self::assertSame('', $form['api_key']);
        self::assertTrue($form['api_key_set']);
        self::assertSame('0212345678', $form['sender']);
        self::assertSame('live-key-value', $settings->runtime()['api_key']);
    }

    #[DataProvider('connectionProvider')]
    public function testBlankKeyKeepsTheStoredOneAndDeleteRemovesIt(array $config): void
    {
        $settings = $this->settings($config);
        $base = ['user_id' => 'shop', 'sender' => '0212345678', 'senderkey' => 'SK1'];
        $settings->save($base + ['api_key' => 'first-key']);
        $settings->save($base + ['api_key' => '']);
        self::assertSame('first-key', $settings->runtime()['api_key']);

        $settings->save($base + ['api_key' => '', 'api_key_delete' => '1']);
        self::assertNull($settings->runtime());
    }

    #[DataProvider('connectionProvider')]
    public function testAlimtalkKeyFallsBackToTheSharedOne(array $config): void
    {
        $settings = $this->settings($config);
        $settings->save(['user_id' => 'shop', 'api_key' => 'shared', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        self::assertSame('shared', $settings->runtime()['alimtalk_api_key']);

        $settings->save(['user_id' => 'shop', 'api_key' => '', 'alimtalk_api_key' => 'special',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        self::assertSame('special', $settings->runtime()['alimtalk_api_key']);
        self::assertSame('shared', $settings->runtime()['api_key']);
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
