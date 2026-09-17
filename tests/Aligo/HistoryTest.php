<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\AlimtalkApi;
use GnuCms\Aligo\History;
use GnuCms\Aligo\Settings;
use GnuCms\Aligo\SettingsRepository;
use GnuCms\Aligo\SmsApi;
use GnuCms\Db\Connection;
use GnuCms\Mail\SecretCipher;
use GnuCms\Support\Clock;
use GnuCms\Tests\Support\DatabaseTestCase;
use GnuCms\Tests\Support\FakeAligoTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

final class HistoryTest extends DatabaseTestCase
{
    private FakeAligoTransport $transport;
    private History $history;
    private Connection $db;

    private function boot(array $config): void
    {
        $this->db = $this->freshDatabase($config);
        $settings = new Settings(new SettingsRepository($this->db), new SecretCipher('s'));
        $settings->save(['user_id' => 'shop', 'api_key' => 'K', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        $this->transport = new FakeAligoTransport();
        $this->history = new History($this->db,
            new AlimtalkApi($this->transport, $settings), new SmsApi($this->transport, $settings));
    }

    /**
     * 접수까지만 끝난 작업 하나를 만든다. Dispatch 가 접수 직후에 실제로 남기는 모양
     * 그대로다 — 수신자는 'accepted'(결과를 기다리는 중)이고 작업도 'sending' 이다.
     * 작업을 'sent'(전원 성공)로 심어 두면 코드가 만들 수 없는 상태를 시험하는 셈이 된다.
     *
     * @param list<string> $phones 수신자 번호. 기본은 한 명이다.
     */
    private function seed(string $channel, string $mid, string $sentAt, array $phones = ['01012345678']): int
    {
        $jobId = (int) $this->db->insert('message_jobs', [
            'channel' => $channel, 'sender' => '0212345678', 'body' => '본문', 'failover' => 0,
            'total' => count($phones), 'success' => count($phones), 'failure' => 0,
            'status' => 'sending', 'test_mode' => 0, 'created_at' => $sentAt,
        ]);
        foreach ($phones as $phone) {
            $this->db->insert('message_recipients', [
                'job_id' => $jobId, 'mid' => $mid, 'phone' => $phone, 'body' => '본문',
                'status' => 'accepted', 'requested_at' => $sentAt, 'sent_at' => $sentAt,
            ]);
        }

        return $jobId;
    }

    private function job(int $jobId): array
    {
        return (array) $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
    }

    /** 재확인 주기(60초)가 이미 지난 것처럼 만든다. Clock 을 얼리지 않고 checked_at 만 되돌린다. */
    private function expireRecheckWindow(int $jobId): void
    {
        $this->db->update('message_recipients',
            ['checked_at' => date('Y-m-d H:i:s', strtotime('-2 minutes'))],
            'job_id = :j', ['j' => $jobId]);
    }

    #[DataProvider('connectionProvider')]
    public function testWritesDeliveryResultBackToTheRecipient(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('sms', 'M1', Clock::now());
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => [
            ['mdid' => 'D1', 'receiver' => '01012345678', 'sms_state' => '전송성공',
             'send_date' => '2026-09-17 10:00:00'],
        ]]));

        self::assertSame(1, $this->history->refresh());

        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [$jobId]);
        self::assertSame('sent', $row['status']);
        self::assertSame('D1', $row['msgid']);
        self::assertNotNull($row['result_at']);

        // 수신자 결과를 적었으면 작업 집계도 같이 따라와야 한다 — 그러지 않으면 목록과
        // 상세가 서로 다른 말을 한다.
        $job = $this->job($jobId);
        self::assertSame(1, (int) $job['success']);
        self::assertSame(0, (int) $job['failure']);
        self::assertSame('sent', $job['status']);
        self::assertNotNull($job['finished_at'], '더 기다릴 수신자가 없으면 그때가 끝난 시각이다');
    }

    /**
     * 이 분기가 이 수정의 핵심이다. 예전에는 작업 집계를 Dispatch 가 접수 시각에 한 번
     * 쓰고 끝이라, 전원이 실패로 돌아온 작업도 목록에서는 "상태 성공 · 성공 N · 실패 0"
     * 으로 보였다 — 같은 화면의 상세에는 실패가 줄줄이 나열된 채로.
     */
    #[DataProvider('connectionProvider')]
    public function testEveryRecipientFailingTurnsTheJobIntoAFailure(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('sms', 'M1', Clock::now(), ['01012345678', '01098765432']);
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => [
            ['mdid' => 'D1', 'receiver' => '01012345678', 'sms_state' => '전송실패'],
            ['mdid' => 'D2', 'receiver' => '01098765432', 'sms_state' => '전송실패'],
        ]]));

        $this->history->refresh();

        $job = $this->job($jobId);
        self::assertSame(0, (int) $job['success']);
        self::assertSame(2, (int) $job['failure']);
        self::assertSame('failed', $job['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testOneSuccessAndOneFailureMakeThePartialStatus(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('sms', 'M1', Clock::now(), ['01012345678', '01098765432']);
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => [
            ['mdid' => 'D1', 'receiver' => '01012345678', 'sms_state' => '전송성공'],
            ['mdid' => 'D2', 'receiver' => '01098765432', 'sms_state' => '전송실패'],
        ]]));

        $this->history->refresh();

        $job = $this->job($jobId);
        self::assertSame(1, (int) $job['success']);
        self::assertSame(1, (int) $job['failure']);
        self::assertSame('partial', $job['status']);
    }

    /** 아직 결과를 기다리는 수신자가 남아 있으면 작업을 최종 상태로 넘기지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testAJobStaysPendingWhileOneRecipientIsStillWaiting(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('sms', 'M1', Clock::now(), ['01012345678', '01098765432']);
        // 알리고가 한 명분 결과만 돌려준 상황 — 나머지 한 명은 여전히 'accepted' 다.
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => [
            ['mdid' => 'D1', 'receiver' => '01012345678', 'sms_state' => '전송성공'],
        ]]));

        $this->history->refresh();

        $job = $this->job($jobId);
        self::assertSame(1, (int) $job['success']);
        self::assertSame('sending', $job['status'], '한 명이라도 기다리는 중이면 확정하지 않는다');
        self::assertNull($job['finished_at']);
    }

    /** 7일이 지나 조회를 포기한 건은 성공도 실패도 아니다 — 작업도 그렇게 말해야 한다. */
    #[DataProvider('connectionProvider')]
    public function testGivingUpLeavesTheJobUnknownRatherThanFailed(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('sms', 'M1', Clock::now(), ['01012345678', '01098765432']);
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => [
            ['mdid' => 'D1', 'receiver' => '01012345678', 'sms_state' => '전송성공'],
        ]]));
        $this->history->refresh();
        // 남은 한 명을 7일이 지난 것처럼 만들어 포기 처리를 태운다.
        $this->db->update('message_recipients',
            ['requested_at' => date('Y-m-d H:i:s', strtotime('-8 days'))],
            'job_id = :j AND status = :s', ['j' => $jobId, 's' => 'accepted']);
        $this->expireRecheckWindow($jobId);

        // 포기 처리는 그 작업의 집계까지 같이 정리해야 한다 — 포기한 건은 결과 조회를
        // 더 타지 않으므로, 여기서 정리하지 않으면 작업이 영영 "결과를 기다리는 중"이다.
        $this->history->refresh();

        $job = $this->job($jobId);
        self::assertSame(1, (int) $job['success']);
        self::assertSame(0, (int) $job['failure'], '포기한 건을 실패로 세면 관리자가 다시 보낸다');
        self::assertSame('unknown', $job['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testAlimtalkFailureKeepsAReadableReason(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('at', 'A1', Clock::now());
        $this->transport->queue(200, (string) json_encode(['code' => 0, 'list' => [
            ['msgid' => 'X1', 'phone' => '01012345678', 'rslt' => 'U', 'rslt_message' => 'mismatch'],
        ]]));

        $this->history->refresh();

        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [$jobId]);
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('템플릿', (string) $row['rslt_message']);
    }

    #[DataProvider('connectionProvider')]
    public function testDoesNotAskAgainWithinTheRecheckWindow(array $config): void
    {
        $this->boot($config);
        $this->seed('sms', 'M1', Clock::now());
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => []]));

        self::assertSame(1, $this->history->refresh());
        self::assertSame(0, $this->history->refresh(), '60초 안에는 다시 묻지 않는다');
        self::assertCount(1, $this->transport->requests);
    }

    #[DataProvider('connectionProvider')]
    public function testStopsAskingAfterSevenDaysAndMarksUnknown(array $config): void
    {
        $this->boot($config);
        $old = date('Y-m-d H:i:s', strtotime('-8 days'));
        $jobId = $this->seed('sms', 'M1', $old);

        self::assertSame(0, $this->history->refresh());
        self::assertSame([], $this->transport->requests);
        self::assertSame('unknown', $this->db->selectOne('SELECT status FROM '
            . $this->db->table('message_recipients') . ' WHERE job_id = ?', [$jobId])['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testPendingCountAndJobListing(array $config): void
    {
        $this->boot($config);
        $this->seed('sms', 'M1', Clock::now());
        self::assertSame(1, $this->history->pendingCount());

        $listing = $this->history->jobs();
        self::assertSame(1, $listing['total']);
        self::assertSame('sms', $listing['items'][0]['channel']);
    }

    #[DataProvider('connectionProvider')]
    public function testFallbackResolvesToSuccess(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('at', 'A1', Clock::now());
        // 수신자가 카카오톡을 쓰지 않아 알림톡이 실패하고 대체문자로 넘어간 상황.
        $this->transport->queue(200, (string) json_encode(['code' => 0, 'list' => [
            ['msgid' => 'X1', 'phone' => '01012345678', 'rslt' => 'K', 'rslt_message' => '카카오톡 미사용',
             'smid' => 'FB1'],
        ]]));
        $this->history->refresh();
        $this->expireRecheckWindow($jobId);
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => [
            ['mdid' => 'D1', 'receiver' => '01012345678', 'sms_state' => '전송성공',
             'send_date' => '2026-09-17 10:05:00'],
        ]]));

        self::assertSame(1, $this->history->refresh());

        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [$jobId]);
        self::assertSame('failed', $row['status'], '원 알림톡 결과는 그대로 남는다');
        self::assertSame('sent', $row['fallback_status']);

        // 알림톡이 실패해도 대체문자가 도착했으면 그 사람은 메시지를 받았다 — 작업
        // 집계에서는 성공이다.
        $job = $this->job($jobId);
        self::assertSame(1, (int) $job['success']);
        self::assertSame(0, (int) $job['failure']);
        self::assertSame('sent', $job['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testFallbackResolvesToFailure(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('at', 'A1', Clock::now());
        $this->transport->queue(200, (string) json_encode(['code' => 0, 'list' => [
            ['msgid' => 'X1', 'phone' => '01012345678', 'rslt' => 'K', 'rslt_message' => '카카오톡 미사용',
             'smid' => 'FB1'],
        ]]));
        $this->history->refresh();
        $this->expireRecheckWindow($jobId);
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => [
            ['mdid' => 'D1', 'receiver' => '01012345678', 'sms_state' => '전송실패',
             'send_date' => '2026-09-17 10:05:00'],
        ]]));

        self::assertSame(1, $this->history->refresh());

        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [$jobId]);
        self::assertSame('failed', $row['fallback_status']);

        $job = $this->job($jobId);
        self::assertSame(0, (int) $job['success']);
        self::assertSame(1, (int) $job['failure']);
        self::assertSame('failed', $job['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testFallbackLookupFailureLeavesRowRetryable(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('at', 'A1', Clock::now());
        $this->transport->queue(200, (string) json_encode(['code' => 0, 'list' => [
            ['msgid' => 'X1', 'phone' => '01012345678', 'rslt' => 'K', 'rslt_message' => '카카오톡 미사용',
             'smid' => 'FB1'],
        ]]));
        $this->history->refresh();
        $this->expireRecheckWindow($jobId);
        $this->transport->queueFailure();

        self::assertSame(0, $this->history->refresh());

        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [$jobId]);
        self::assertSame('accepted', $row['fallback_status'], '조회 실패는 다음 방문에 다시 시도한다');
    }

    #[DataProvider('connectionProvider')]
    public function testLateAnswerOverwritesAnAlreadyGivenUpRow(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('sms', 'M1', Clock::now());
        // 다른 관리자의 요청이 먼저 포기 처리를 끝냈다고 가정한다 — 실제 응답은 그 직후에 막 도착한다.
        $this->db->update('message_recipients', ['status' => 'unknown'], 'job_id = :j', ['j' => $jobId]);
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => [
            ['mdid' => 'D1', 'receiver' => '01012345678', 'sms_state' => '전송성공',
             'send_date' => '2026-09-17 10:00:00'],
        ]]));

        $method = new ReflectionMethod(History::class, 'apply');
        $method->setAccessible(true);
        $method->invoke($this->history, 'M1', 'sms');

        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [$jobId]);
        self::assertSame('sent', $row['status'], '늦게 온 실제 결과가 포기 표시를 덮어써야 한다');
    }

    #[DataProvider('connectionProvider')]
    public function testUnmatchedPhoneFromAligoIsIgnored(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('sms', 'M1', Clock::now());
        // 우리 수신자 목록에 없는 번호가 섞여 와도 깨지지 않고, 우리 행은 건드리지 않는다.
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => [
            ['mdid' => 'D9', 'receiver' => '01099999999', 'sms_state' => '전송성공',
             'send_date' => '2026-09-17 10:00:00'],
        ]]));

        self::assertSame(1, $this->history->refresh(), '조회 자체는 정상 처리된다');

        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [$jobId]);
        self::assertSame('accepted', $row['status'], '맞지 않는 번호는 우리 행을 바꾸지 않는다');
        self::assertNull($row['msgid']);
    }

    #[DataProvider('connectionProvider')]
    public function testOneFailingLookupDoesNotBlockTheRestOfTheBatch(array $config): void
    {
        $this->boot($config);
        $jobId1 = $this->seed('sms', 'M1', Clock::now());
        $jobId2 = $this->seed('sms', 'M2', Clock::now());
        $this->transport->queueFailure();
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => [
            ['mdid' => 'D2', 'receiver' => '01012345678', 'sms_state' => '전송성공',
             'send_date' => '2026-09-17 10:00:00'],
        ]]));

        self::assertSame(1, $this->history->refresh(), '한 건이 실패해도 나머지는 갱신된다');

        self::assertCount(2, $this->transport->requests);
        $row1 = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [$jobId1]);
        $row2 = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [$jobId2]);
        self::assertSame('accepted', $row1['status'], '실패한 조회는 재시도할 수 있게 그대로 남는다');
        self::assertSame('sent', $row2['status']);
    }

    /**
     * 대기 안내와 갱신 버튼은 pendingCount() 로 나타나고 사라진다. 알림톡 결과가 다
     * 잡히고 대체문자 결과만 남은 동안에도 갱신할 일이 남아 있는데, status 만 세면
     * 그 사이 안내와 버튼이 통째로 사라져 관리자가 손으로 갱신할 방법이 없어진다.
     */
    #[DataProvider('connectionProvider')]
    public function testPendingCountSeesTheFallbackQueueToo(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('at', 'A1', Clock::now());
        // 알림톡은 실패로 확정됐고 대체문자 결과만 남은 상태.
        $this->db->update('message_recipients',
            ['status' => 'failed', 'smid' => 'FB1', 'fallback_status' => 'accepted', 'result_at' => Clock::now()],
            'job_id = :j', ['j' => $jobId]);

        self::assertSame(1, $this->history->pendingCount());
    }

    /**
     * 조회 상한은 방문 한 번에 부를 알리고 호출 수다. 두 대기열에 같은 값을 그대로
     * 넘기면 "최대 N개"가 실제로는 최대 2N개가 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testThePollingBudgetIsSharedByBothQueues(array $config): void
    {
        $this->boot($config);
        $this->seed('sms', 'M1', Clock::now());
        $fallbackJob = $this->seed('at', 'A1', Clock::now(), ['01055556666']);
        $this->db->update('message_recipients',
            ['status' => 'failed', 'smid' => 'FB1', 'fallback_status' => 'accepted', 'result_at' => Clock::now()],
            'job_id = :j', ['j' => $fallbackJob]);
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => []]));
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => []]));

        $this->history->refresh(1);

        self::assertCount(1, $this->transport->requests, '두 대기열이 예산을 나눠 쓴다');
    }

    /** 조회에 실패하면 그 사유를 화면이 읽어 갈 수 있게 남긴다. 성공하면 남기지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testTheLastLookupFailureIsAvailableToTheScreen(array $config): void
    {
        $this->boot($config);
        $jobId = $this->seed('sms', 'M1', Clock::now());
        $this->transport->queueFailure();

        $this->history->refresh();
        self::assertNotNull($this->history->lastFailure());

        $this->expireRecheckWindow($jobId);
        $this->transport->queue(200, (string) json_encode(['result_code' => 1, 'list' => []]));
        $this->history->refresh();
        self::assertNull($this->history->lastFailure(), '이번 방문에 실패가 없었으면 남기지 않는다');
    }
}
