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
}
