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

    private function seed(string $channel, string $mid, string $sentAt): int
    {
        $jobId = (int) $this->db->insert('message_jobs', [
            'channel' => $channel, 'sender' => '0212345678', 'body' => '본문', 'failover' => 0,
            'total' => 1, 'success' => 1, 'failure' => 0, 'status' => 'sent', 'test_mode' => 0,
            'created_at' => $sentAt,
        ]);
        $this->db->insert('message_recipients', [
            'job_id' => $jobId, 'mid' => $mid, 'phone' => '01012345678', 'body' => '본문',
            'status' => 'accepted', 'requested_at' => $sentAt, 'sent_at' => $sentAt,
        ]);

        return $jobId;
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
}
