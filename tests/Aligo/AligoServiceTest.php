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

        self::assertSame([
            'alimtalk' => ['ok' => true, 'count' => 120, 'reason' => null],
            'sms' => ['ok' => true, 'sms_count' => 500, 'lms_count' => 100, 'reason' => null],
        ], $service->verify());
    }

    /**
     * 알림톡은 아직 신청하지 않고 문자만 쓰는 계정이 있을 수 있다. 알림톡 확인이
     * 실패해도 문자 쪽에서 알아낸 값은 그대로 보고해야 한다 — 절반이 실패했다고
     * 아무것도 못 알아낸 것처럼 돌려주면 안 된다. 다만 실패한 쪽은 0 건으로
     * "성공한 것처럼" 채우지 않고 ok=false 와 실패 사유를 그대로 드러낸다 —
     * 0 은 "정상 조회했더니 잔여 0 건"과 구별되지 않아 거짓 정보가 되기 때문이다.
     */
    #[DataProvider('connectionProvider')]
    public function testVerifyReportsSmsResultAndAlimtalkFailureReasonWhenAlimtalkFails(array $config): void
    {
        $transport = new FakeAligoTransport();
        $service = new AligoService($this->freshDatabase($config), $transport, new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        $transport->queue(200, '{"code":"509","message":"발신프로필을 확인할 수 없습니다."}');
        $transport->queue(200, '{"result_code":1,"SMS_CNT":500,"LMS_CNT":100,"MMS_CNT":0}');

        $result = $service->verify();

        self::assertFalse($result['alimtalk']['ok']);
        self::assertSame(0, $result['alimtalk']['count']);
        self::assertStringContainsString('발신프로필', (string) $result['alimtalk']['reason']);
        self::assertSame(['ok' => true, 'sms_count' => 500, 'lms_count' => 100, 'reason' => null], $result['sms']);
    }

    /** 반대로 문자 쪽이 막혀 있어도 알림톡에서 알아낸 값은 그대로 보고하고, 문자 쪽은 실패 사유를 담는다. */
    #[DataProvider('connectionProvider')]
    public function testVerifyReportsAlimtalkResultAndSmsFailureReasonWhenSmsFails(array $config): void
    {
        $transport = new FakeAligoTransport();
        $service = new AligoService($this->freshDatabase($config), $transport, new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        $transport->queue(200, '{"code":0,"ALT_CNT":120}');
        $transport->queue(200, '{"result_code":"-104","message":"인증에 실패했습니다."}');

        $result = $service->verify();

        self::assertSame(['ok' => true, 'count' => 120, 'reason' => null], $result['alimtalk']);
        self::assertFalse($result['sms']['ok']);
        self::assertSame(0, $result['sms']['sms_count']);
        self::assertSame(0, $result['sms']['lms_count']);
        self::assertStringContainsString('인증', (string) $result['sms']['reason']);
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

    /**
     * alimtalkApi·smsApi·dispatch 를 공개 속성으로 노출하면 누구든 이들을 직접 불러
     * Settings::isEnabled() 검사를 건너뛰고 발송할 수 있다. 공개 속성은 발송 능력이
     * 없는 것들(settings·templates·history)만 남아 있어야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testPublicPropertiesExposeNothingThatCanSendWithoutTheChannelSwitch(array $config): void
    {
        $service = new AligoService($this->freshDatabase($config), new FakeAligoTransport(), new SecretCipher('s'));

        $publicProperties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass($service))->getProperties(\ReflectionProperty::IS_PUBLIC)
        );
        sort($publicProperties);

        self::assertSame(['history', 'settings', 'templates'], $publicProperties);
    }
}
