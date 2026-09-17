<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\Settings;
use GnuCms\Aligo\SettingsRepository;
use GnuCms\Aligo\SmsApi;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Tests\Support\DatabaseTestCase;
use GnuCms\Tests\Support\FakeAligoTransport;
use PHPUnit\Framework\Attributes\DataProvider;

final class SmsApiTest extends DatabaseTestCase
{
    private FakeAligoTransport $transport;

    private function api(array $config): SmsApi
    {
        $settings = new Settings(new SettingsRepository($this->freshDatabase($config)), new SecretCipher('s'));
        $settings->save(['user_id' => 'shop', 'api_key' => 'KEY', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        $this->transport = new FakeAligoTransport();

        return new SmsApi($this->transport, $settings);
    }

    #[DataProvider('connectionProvider')]
    public function testUsesTheSmsHostAndItsOwnParameterNames(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"result_code":1,"SMS_CNT":500,"LMS_CNT":100,"MMS_CNT":0}');

        self::assertSame(500, $api->remain()['SMS_CNT']);
        $request = $this->transport->requests[0];
        self::assertStringStartsWith('https://apis.aligo.in/remain/', $request['url']);
        self::assertSame('KEY', $request['fields']['key']);
        self::assertSame('shop', $request['fields']['user_id']);
        self::assertArrayNotHasKey('apikey', $request['fields']);
    }

    #[DataProvider('connectionProvider')]
    public function testConvertsBodyAndTitleToEucKr(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"result_code":1,"msg_id":"7788","success_cnt":1,"error_cnt":0}');

        $result = $api->sendMass(['cnt' => '1', 'msg_type' => 'LMS', 'title' => '안내',
            'rec_1' => '01012345678', 'msg_1' => '안녕하세요']);

        self::assertSame('7788', $result['mid']);
        self::assertSame(1, $result['scnt']);
        $fields = $this->transport->requests[0]['fields'];
        self::assertSame(mb_convert_encoding('안녕하세요', 'EUC-KR', 'UTF-8'), $fields['msg_1']);
        self::assertSame(mb_convert_encoding('안내', 'EUC-KR', 'UTF-8'), $fields['title']);
        self::assertSame('01012345678', $fields['rec_1']);
    }

    #[DataProvider('connectionProvider')]
    public function testNegativeResultCodeBecomesAReadableError(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"result_code":-101,"message":"sender not registered"}');

        try {
            $api->sendMass(['cnt' => '1', 'rec_1' => '01012345678', 'msg_1' => '안녕']);
            self::fail('음수 코드는 예외가 되어야 한다');
        } catch (DomainError $e) {
            self::assertStringContainsString('발신번호', $e->getMessage());
            self::assertStringNotContainsString('KEY', $e->getMessage());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testCallerCannotOverrideCredentials(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"result_code":1,"msg_id":"M1","success_cnt":1,"error_cnt":0}');

        $api->sendMass(['key' => 'CALLER', 'user_id' => 'CALLER', 'cnt' => '1', 'rec_1' => '01012345678', 'msg_1' => 'test']);

        $request = $this->transport->requests[0];
        self::assertSame('KEY', $request['fields']['key']);
        self::assertSame('shop', $request['fields']['user_id']);
    }

    #[DataProvider('connectionProvider')]
    public function testDetailCallsCorrectUrlAndReturnsArray(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"result_code":1,"list":[{"msg_type":"LMS","cnt":1,"sts":"C"}]}');

        $result = $api->detail('M456');

        self::assertIsArray($result);
        self::assertCount(1, $result);
        $request = $this->transport->requests[0];
        self::assertStringContainsString('/sms_list/', $request['url']);
        self::assertSame('M456', $request['fields']['mid']);
    }

    #[DataProvider('connectionProvider')]
    public function testMalformedListInSuccessfulResponseReturnsEmptyArray(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"result_code":1,"list":"not-an-array"}');

        $result = $api->detail('M789');

        self::assertIsArray($result);
        self::assertEmpty($result);
    }

    #[DataProvider('connectionProvider')]
    public function testSendMassReturnsMidAndCounts(array $config): void
    {
        $api = $this->api($config);
        $this->transport->queue(200, '{"result_code":1,"msg_id":"M999","success_cnt":5,"error_cnt":2}');

        $result = $api->sendMass(['cnt' => '7', 'rec_1' => '01012345678', 'msg_1' => 'hello']);

        self::assertSame('M999', $result['mid']);
        self::assertSame(5, $result['scnt']);
        self::assertSame(2, $result['fcnt']);
        self::assertStringContainsString('/send_mass/', $this->transport->requests[0]['url']);
    }
}
