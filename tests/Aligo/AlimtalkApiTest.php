<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\AlimtalkApi;
use GnuCms\Aligo\Settings;
use GnuCms\Aligo\SettingsRepository;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Tests\Support\DatabaseTestCase;
use GnuCms\Tests\Support\FakeAligoTransport;
use PHPUnit\Framework\Attributes\DataProvider;

final class AlimtalkApiTest extends DatabaseTestCase
{
    private FakeAligoTransport $transport;

    private function api(array $config): AlimtalkApi
    {
        $settings = new Settings(new SettingsRepository($this->freshDatabase($config)), new SecretCipher('s'));
        $settings->save(['user_id' => 'shop', 'api_key' => 'KEY', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        $this->transport = new FakeAligoTransport();

        return new AlimtalkApi($this->transport, $settings);
    }

    #[DataProvider('connectionProvider')]
    public function testSendsCredentialsWithEveryCall(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"code":0,"ALT_CNT":120,"SMS_CNT":50,"LMS_CNT":10}');

        self::assertSame(120, $api->heartInfo()['ALT_CNT']);
        $request = $this->transport->requests[0];
        self::assertStringStartsWith('https://kakaoapi.aligo.in/akv10/heartinfo/', $request['url']);
        self::assertSame('KEY', $request['fields']['apikey']);
        self::assertSame('shop', $request['fields']['userid']);
    }

    #[DataProvider('connectionProvider')]
    public function testSendReturnsMidAndCounts(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"code":0,"info":{"mid":"M1","scnt":2,"fcnt":0}}');

        $result = $api->send(['senderkey' => 'SK1', 'tpl_code' => 'T1', 'sender' => '0212345678',
            'receiver_1' => '01012345678', 'message_1' => '안녕하세요']);

        self::assertSame(['mid' => 'M1', 'scnt' => 2, 'fcnt' => 0], $result);
        self::assertStringContainsString('/akv10/alimtalk/send/', $this->transport->requests[0]['url']);
    }

    #[DataProvider('connectionProvider')]
    public function testFailureBecomesAReadableError(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"code":510,"message":"template not approved"}');

        try {
            $api->send(['tpl_code' => 'T1']);
            self::fail('실패 코드는 예외가 되어야 한다');
        } catch (DomainError $e) {
            self::assertStringContainsString('승인되지 않은 템플릿', $e->getMessage());
            self::assertStringNotContainsString('KEY', $e->getMessage());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testBrokenJsonIsRejected(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(500, '<html>오류</html>');

        $this->expectException(DomainError::class);
        $api->heartInfo();
    }

    #[DataProvider('connectionProvider')]
    public function testCallerCannotOverrideCredentials(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"code":0,"info":{"mid":"M1","scnt":1,"fcnt":0}}');

        $api->send(['apikey' => 'CALLER', 'userid' => 'CALLER']);

        $request = $this->transport->requests[0];
        self::assertSame('KEY', $request['fields']['apikey']);
        self::assertSame('shop', $request['fields']['userid']);
    }

    #[DataProvider('connectionProvider')]
    public function testProfilesCallsCorrectUrlAndReturnsArray(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"code":0,"list":[{"senderKey":"SK1","name":"Store","uuid":"u1","status":"A"}]}');

        $result = $api->profiles();

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertStringContainsString('/akv10/profile/list/', $this->transport->requests[0]['url']);
    }

    #[DataProvider('connectionProvider')]
    public function testTemplatesCallsCorrectUrlAndReturnsArray(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"code":0,"list":[{"tpl_code":"T1","tpl_name":"Welcome"}]}');

        $result = $api->templates('SK1');

        self::assertIsArray($result);
        self::assertCount(1, $result);
        $request = $this->transport->requests[0];
        self::assertStringContainsString('/akv10/template/list/', $request['url']);
        self::assertSame('SK1', $request['fields']['senderkey']);
    }

    #[DataProvider('connectionProvider')]
    public function testDetailCallsCorrectUrlAndReturnsArray(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"code":0,"list":[{"sms_cnt":1,"sts":"complete"}]}');

        $result = $api->detail('M123');

        self::assertIsArray($result);
        self::assertCount(1, $result);
        $request = $this->transport->requests[0];
        self::assertStringContainsString('/akv10/history/detail/', $request['url']);
        self::assertSame('M123', $request['fields']['mid']);
    }

    #[DataProvider('connectionProvider')]
    public function testMalformedListInSuccessfulResponseReturnsEmptyArray(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"code":0,"list":"not-an-array"}');

        $result = $api->profiles();

        self::assertIsArray($result);
        self::assertEmpty($result);
    }

    #[DataProvider('connectionProvider')]
    public function testCancelSendsTheMidToTheCancelPath(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"code":0}');

        $api->cancel('M77');

        $request = $this->transport->requests[0];
        self::assertStringContainsString('/akv10/cancel/', $request['url']);
        self::assertSame('M77', $request['fields']['mid']);
        self::assertSame('KEY', $request['fields']['apikey']);
    }

    #[DataProvider('connectionProvider')]
    public function testCancelTooLateBecomesAReadableError(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"code":-804,"message":"too late"}');

        $this->expectException(DomainError::class);
        $api->cancel('M77');
    }
}
