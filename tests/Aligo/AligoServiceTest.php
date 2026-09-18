<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\AligoService;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Support\Clock;
use GnuCms\Tests\Support\DatabaseTestCase;
use GnuCms\Tests\Support\FakeAligoTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

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

    /** 켜는 경우는 취소할 것이 없다 — 이미 예약된 작업이 있어도 손대지 않고, 알리고에 취소 요청도 보내지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testTurningAChannelOnCancelsNothing(array $config): void
    {
        $db = $this->freshDatabase($config);
        $transport = new FakeAligoTransport();
        $service = new AligoService($db, $transport, new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $service->settings->setEnabled('sms', true);

        // 'Z' 로 명시적 UTC 오프셋을 붙인다 — 오프셋 없이 넘기면 SendTime::parse() 가
        // KST 로 읽어 9시간 이르게 해석되므로 하한(10분)을 벗어난다(SendTimeTest 참고).
        // 이 값 자체의 시간대는 아래 시험들의 관심사가 아니다.
        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $transport->queue(200, '{"result_code":1,"msg_id":"M1","success_cnt":1,"error_cnt":0}');
        $jobId = $service->send(['channel' => 'sms', 'body' => '안녕하세요', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01012345678']]]);
        $requestsBefore = count($transport->requests);

        $result = $service->setChannelEnabled('sms', true);

        self::assertSame(['cancelled' => 0, 'failed' => 0, 'reasons' => []], $result);
        self::assertCount($requestsBefore, $transport->requests, '켜는 경우는 알리고에 취소 요청을 보내지 않아야 한다');
        $job = $db->selectOne('SELECT status FROM ' . $db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertSame('scheduled', $job['status']);
    }

    /**
     * 채널을 끄면 그 채널에 걸려 있던 예약이 실제로 취소 요청되어야 한다 — 관리자 화면에서
     * 채널을 끄는 유일한 통로(AdminAligoController::toggle())가 이 메서드를 거치므로, 여기서
     * 취소가 일어나지 않으면 스위치를 꺼도 예약은 그대로 나간다. 다른 채널(alimtalk)에
     * 걸린 예약까지 함께 취소되면(=일괄 취소) 이 테스트만으로는 걸러지지 않으므로, 그
     * 예약이 그대로 살아 있는지도 함께 확인한다.
     */
    #[DataProvider('connectionProvider')]
    public function testTurningAChannelOffCancelsItsSchedules(array $config): void
    {
        $db = $this->freshDatabase($config);
        $transport = new FakeAligoTransport();
        $service = new AligoService($db, $transport, new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $service->settings->setEnabled('sms', true);
        $service->settings->setEnabled('at', true);
        $transport->queue(200, (string) json_encode(['code' => 0, 'list' => [[
            'templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '본문',
            'status' => 'A', 'inspStatus' => 'APR']]]));
        $service->templates->fetch();
        $service->templates->setEnabled('T1', true);

        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $transport->queue(200, '{"result_code":1,"msg_id":"M1","success_cnt":1,"error_cnt":0}');
        $smsJobId = $service->send(['channel' => 'sms', 'body' => '안녕하세요', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01012345678']]]);
        $transport->queue(200, (string) json_encode(['code' => 0, 'info' => ['mid' => 'A1', 'scnt' => 1, 'fcnt' => 0]]));
        $atJobId = $service->send(['channel' => 'at', 'tpl_code' => 'T1', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01098765432']]]);

        $transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $result = $service->setChannelEnabled('sms', false);

        self::assertSame(1, $result['cancelled']);
        self::assertSame(0, $result['failed']);
        self::assertSame([], $result['reasons']);
        self::assertFalse($service->settings->isEnabled('sms'));

        $cancelRequest = end($transport->requests);
        self::assertStringContainsString('/cancel/', $cancelRequest['url']);
        self::assertSame('M1', $cancelRequest['fields']['mid']);

        $smsJob = $db->selectOne('SELECT status FROM ' . $db->table('message_jobs') . ' WHERE id = ?', [$smsJobId]);
        self::assertSame('cancelled', $smsJob['status']);

        // 알림톡 채널은 건드리지 않았다 — 여전히 켜져 있고, 그 예약도 그대로 남는다.
        self::assertTrue($service->settings->isEnabled('at'));
        $atJob = $db->selectOne('SELECT status FROM ' . $db->table('message_jobs') . ' WHERE id = ?', [$atJobId]);
        self::assertSame('scheduled', $atJob['status']);
    }

    /**
     * 취소가 알리고에서 거절되면(-804) 그 사실이 반환값에 남아야 한다 — 삼키면 관리자는
     * 스위치를 껐으니 예약도 멈췄다고 믿게 된다. 취소 실패가 스위치를 되돌리지도 않는다 —
     * "껐지만 취소 실패"가 "끄기 자체가 실패"로 감춰지면 관리자는 더 나쁜 판단을 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testACancellationThatFailsIsReportedNotSwallowed(array $config): void
    {
        $db = $this->freshDatabase($config);
        $transport = new FakeAligoTransport();
        $service = new AligoService($db, $transport, new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $service->settings->setEnabled('sms', true);

        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $transport->queue(200, '{"result_code":1,"msg_id":"M1","success_cnt":1,"error_cnt":0}');
        $jobId = $service->send(['channel' => 'sms', 'body' => '안녕하세요', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01012345678']]]);

        $transport->queue(200, '{"result_code":-804,"message":"too late"}');
        $result = $service->setChannelEnabled('sms', false);

        self::assertSame(0, $result['cancelled']);
        self::assertSame(1, $result['failed']);
        self::assertNotSame([], $result['reasons']);
        self::assertFalse($service->settings->isEnabled('sms'), '취소가 실패해도 스위치는 꺼진 채로 남아야 한다');

        $job = $db->selectOne('SELECT status FROM ' . $db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertSame('scheduled', $job['status'], '취소되지 못했으므로 예약 상태가 그대로 남아야 한다');
    }

    /**
     * 전원 취소된 작업의 숫자. 접수 직후의 success 는 "알리고가 몇 건을 접수했나"이지
     * "몇 명이 받았나"가 아니다 — 취소하고 나면 그 502건은 아무에게도 가지 않는다.
     * 취소가 집계에 전혀 나타나지 않던 판에서는 이 작업이 이력에 "총 502 · 성공 502 ·
     * 실패 0 · 취소됨"으로 남았다: 아무도 받지 않은 발송을 502건 성공했다고 적는 줄이다.
     */
    #[DataProvider('connectionProvider')]
    public function testAFullyCancelledJobStopsClaimingTheBookedCountAsSuccesses(array $config): void
    {
        $db = $this->freshDatabase($config);
        $transport = new FakeAligoTransport();
        $service = new AligoService($db, $transport, new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $service->settings->setEnabled('sms', true);

        // 502명 — Dispatch 의 500명 단위 분할로 묶음(mid)이 둘 생긴다.
        $recipients = [];
        for ($i = 0; $i < 502; $i++) {
            $recipients[] = ['phone' => '010' . str_pad((string) (11110000 + $i), 8, '0', STR_PAD_LEFT)];
        }
        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $transport->queue(200, '{"result_code":1,"msg_id":"M1","success_cnt":500,"error_cnt":0}');
        $transport->queue(200, '{"result_code":1,"msg_id":"M2","success_cnt":2,"error_cnt":0}');
        $jobId = $service->send(['channel' => 'sms', 'body' => '예약 발송', 'scheduled_at' => $at,
            'recipients' => $recipients]);

        $booked = $db->selectOne('SELECT success FROM ' . $db->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
        self::assertSame(502, (int) $booked['success'], '접수 직후에는 접수 건수를 적는다');

        $transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $result = $service->cancel($jobId);

        self::assertSame(2, $result['cancelled'], '묶음 둘 다 취소된다');
        self::assertSame(0, $result['failed']);

        $job = $db->selectOne('SELECT * FROM ' . $db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertSame('cancelled', $job['status']);
        self::assertSame(502, (int) $job['total']);
        self::assertSame(0, (int) $job['success'], '아무에게도 가지 않았다 — 성공은 0 이다');
        self::assertSame(0, (int) $job['failure']);
        self::assertSame(502, (int) $job['cancelled'], '502명은 사라지지 않고 취소 칸에 그대로 있어야 한다');
    }

    /**
     * 템플릿을 다시 가져와 승인을 잃은 사본을 발견하면, 그 템플릿으로 걸린 예약도 함께
     * 취소 요청되어야 한다. 승인을 그대로 유지한 다른 템플릿의 예약은 손대지 않는다 —
     * 그것까지 취소되면 이 테스트의 앞부분만 보는 검증으로는 걸러지지 않으므로 함께 확인한다.
     */
    #[DataProvider('connectionProvider')]
    public function testRefetchCancelsSchedulesOfATemplateThatLostApproval(array $config): void
    {
        $db = $this->freshDatabase($config);
        $transport = new FakeAligoTransport();
        $service = new AligoService($db, $transport, new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $service->settings->setEnabled('at', true);

        $transport->queue(200, (string) json_encode(['code' => 0, 'list' => [
            ['templtCode' => 'T1', 'templtName' => '안내1', 'templtContent' => '본문1',
                'status' => 'A', 'inspStatus' => 'APR'],
            ['templtCode' => 'T2', 'templtName' => '안내2', 'templtContent' => '본문2',
                'status' => 'A', 'inspStatus' => 'APR'],
        ]]));
        $service->templates->fetch();
        $service->templates->setEnabled('T1', true);
        $service->templates->setEnabled('T2', true);

        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $transport->queue(200, (string) json_encode(['code' => 0, 'info' => ['mid' => 'A1', 'scnt' => 1, 'fcnt' => 0]]));
        $t1JobId = $service->send(['channel' => 'at', 'tpl_code' => 'T1', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01012345678']]]);
        $transport->queue(200, (string) json_encode(['code' => 0, 'info' => ['mid' => 'A2', 'scnt' => 1, 'fcnt' => 0]]));
        $t2JobId = $service->send(['channel' => 'at', 'tpl_code' => 'T2', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01098765432']]]);

        // T1 은 승인을 잃고(status=S), T2 는 그대로 승인 상태다.
        $transport->queue(200, (string) json_encode(['code' => 0, 'list' => [
            ['templtCode' => 'T1', 'templtName' => '안내1', 'templtContent' => '본문1',
                'status' => 'S', 'inspStatus' => 'APR'],
            ['templtCode' => 'T2', 'templtName' => '안내2', 'templtContent' => '본문2',
                'status' => 'A', 'inspStatus' => 'APR'],
        ]]));
        $transport->queue(200, '{"code":0}');

        $result = $service->importTemplates();

        self::assertSame(0, $result['imported']);
        self::assertSame(1, $result['updated']);
        self::assertSame(1, $result['disabled']);
        self::assertSame(['T1'], $result['disabled_tpl_codes']);
        self::assertSame(1, $result['cancelled']);
        self::assertSame(0, $result['failed']);
        self::assertSame([], $result['reasons']);

        $t1Job = $db->selectOne('SELECT status FROM ' . $db->table('message_jobs') . ' WHERE id = ?', [$t1JobId]);
        self::assertSame('cancelled', $t1Job['status']);

        // 승인을 유지한 T2 의 예약은 손대지 않는다.
        $t2Job = $db->selectOne('SELECT status FROM ' . $db->table('message_jobs') . ' WHERE id = ?', [$t2JobId]);
        self::assertSame('scheduled', $t2Job['status']);
    }

    /**
     * 취소 대상 목록을 만든 시점(scheduledJobIdsForChannel() 의 SELECT)과 실제로
     * Dispatch::cancel() 을 부르는 시점 사이에는 틈이 있다 — 다른 관리자가 열어 둔 이력
     * 화면의 History::refresh() 가 그 사이 한 작업을 먼저 끝냈거나, 끄기 버튼이 두 번
     * 눌려 겹친 두 요청의 목록이 같은 작업을 함께 보고 있었을 수 있다. 그 작업은
     * Dispatch::cancel() 의 가드절에서 거부되어 DomainError 를 던지는데, 그 예외 하나
     * 때문에 배치의 나머지 작업이 통째로 시도되지 못하면(그리고 그 사실이 호출부에
     * 남지 않으면) 스위치는 꺼졌는데 취소는 부분적으로만 시도된 채 아무도 모르게 된다.
     *
     * cancelJobs() 는 private 이므로, 두 작업이 든 목록을 직접 만들어 리플렉션으로 부른다
     * — 첫 작업의 상태를 미리 'scheduled' 가 아닌 값으로 바꿔 두면, "목록을 만들 때는
     * scheduled 였는데 취소를 시도할 때는 아니다"라는 경쟁 상황을 한 번의 동기 호출
     * 안에서 그대로 재현할 수 있다.
     */
    #[DataProvider('connectionProvider')]
    public function testOneJobRejectedByTheGuardClauseDoesNotStopTheRestOfTheBatch(array $config): void
    {
        $db = $this->freshDatabase($config);
        $transport = new FakeAligoTransport();
        $service = new AligoService($db, $transport, new SecretCipher('s'));
        $service->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $service->settings->setEnabled('sms', true);

        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $transport->queue(200, '{"result_code":1,"msg_id":"M1","success_cnt":1,"error_cnt":0}');
        $staleJobId = $service->send(['channel' => 'sms', 'body' => '안녕하세요', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01011111111']]]);
        $transport->queue(200, '{"result_code":1,"msg_id":"M2","success_cnt":1,"error_cnt":0}');
        $healthyJobId = $service->send(['channel' => 'sms', 'body' => '안녕하세요', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01022222222']]]);

        // 이 작업은 더는 'scheduled'가 아니다 — Dispatch::cancel() 이 가드절에서 거부한다.
        $db->update('message_jobs', ['status' => 'sent'], 'id = :id', ['id' => $staleJobId]);

        $transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');

        $method = new ReflectionMethod(AligoService::class, 'cancelJobs');
        $method->setAccessible(true);
        $result = $method->invoke($service, [$staleJobId, $healthyJobId]);

        self::assertSame(1, $result['cancelled']);
        self::assertSame(1, $result['failed']);
        self::assertNotSame([], $result['reasons']);
        self::assertStringContainsString((string) $staleJobId, implode(' ', $result['reasons']),
            '거부된 작업이 어느 것인지 이유에 남아야 한다');

        // 거부된 작업 뒤에 있던 작업도 시도되어 실제로 취소됐다 — 앞선 실패가 뒤를 막지 않는다.
        $healthyJob = $db->selectOne('SELECT status FROM ' . $db->table('message_jobs')
            . ' WHERE id = ?', [$healthyJobId]);
        self::assertSame('cancelled', $healthyJob['status']);
    }
}
