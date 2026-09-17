<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\AligoService;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Tests\Support\DatabaseTestCase;
use GnuCms\Tests\Support\FakeAligoTransport;
use PHPUnit\Framework\Attributes\DataProvider;

final class AligoServiceTest extends DatabaseTestCase
{
    #[DataProvider('connectionProvider')]
    public function testStatusReportsWhatIsConfigured(array $config): void
    {
        $transport = new FakeAligoTransport();
        $service = new AligoService($this->freshDatabase($config), $transport, new SecretCipher('s'));

        self::assertFalse($service->status()['configured']);

        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $service->settings->setEnabled('sms', true);

        $status = $service->status();
        self::assertTrue($status['configured']);
        self::assertTrue($status['sms_enabled']);
        self::assertFalse($status['alimtalk_enabled']);
        self::assertSame(0, $status['pending']);
    }

    #[DataProvider('connectionProvider')]
    public function testVerifyAsksBothServicesForRemainingCounts(array $config): void
    {
        $transport = new FakeAligoTransport();
        $service = new AligoService($this->freshDatabase($config), $transport, new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        $transport->queue(200, '{"code":0,"ALT_CNT":120}');
        $transport->queue(200, '{"result_code":1,"SMS_CNT":500,"LMS_CNT":100,"MMS_CNT":0}');

        self::assertSame(['ALT_CNT' => 120, 'SMS_CNT' => 500, 'LMS_CNT' => 100], $service->verify());
    }

    /**
     * 알림톡은 아직 신청하지 않고 문자만 쓰는 계정이 있을 수 있다. 알림톡 확인이
     * 실패해도 문자 쪽에서 알아낸 값은 그대로 보고해야 한다 — 절반이 실패했다고
     * 아무것도 못 알아낸 것처럼 돌려주면 안 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testVerifyReportsSmsCountsEvenWhenAlimtalkFails(array $config): void
    {
        $transport = new FakeAligoTransport();
        $service = new AligoService($this->freshDatabase($config), $transport, new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        $transport->queue(200, '{"code":"509","message":"발신프로필을 확인할 수 없습니다."}');
        $transport->queue(200, '{"result_code":1,"SMS_CNT":500,"LMS_CNT":100,"MMS_CNT":0}');

        self::assertSame(['ALT_CNT' => 0, 'SMS_CNT' => 500, 'LMS_CNT' => 100], $service->verify());
    }

    /** 반대로 문자 쪽이 막혀 있어도 알림톡에서 알아낸 값은 그대로 보고해야 한다. */
    #[DataProvider('connectionProvider')]
    public function testVerifyReportsAlimtalkCountEvenWhenSmsFails(array $config): void
    {
        $transport = new FakeAligoTransport();
        $service = new AligoService($this->freshDatabase($config), $transport, new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        $transport->queue(200, '{"code":0,"ALT_CNT":120}');
        $transport->queue(200, '{"result_code":"-104","message":"인증에 실패했습니다."}');

        self::assertSame(['ALT_CNT' => 120, 'SMS_CNT' => 0, 'LMS_CNT' => 0], $service->verify());
    }

    /** 계정 자체가 저장되어 있지 않으면 "0건 남음"처럼 보이는 값 대신 설정하라는 오류를 낸다. */
    #[DataProvider('connectionProvider')]
    public function testVerifyRefusesWhenNoAccountIsSaved(array $config): void
    {
        $service = new AligoService($this->freshDatabase($config), new FakeAligoTransport(), new SecretCipher('s'));

        $this->expectException(DomainError::class);
        $service->verify();
    }

    /** send() 는 Dispatch::send() 를 그대로 거치므로, 채널이 꺼져 있으면 확장도 보낼 수 없다. */
    #[DataProvider('connectionProvider')]
    public function testSendStillRefusesWhenTheChannelIsNotAllowed(array $config): void
    {
        $service = new AligoService($this->freshDatabase($config), new FakeAligoTransport(), new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        $this->expectException(DomainError::class);
        $service->send(['channel' => 'sms', 'body' => '안녕하세요',
            'recipients' => [['phone' => '01012345678']]]);
    }
}
