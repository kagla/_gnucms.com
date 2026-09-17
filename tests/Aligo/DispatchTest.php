<?php

declare(strict_types=1);

namespace GnuCms\Tests\Aligo;

use GnuCms\Aligo\AlimtalkApi;
use GnuCms\Aligo\Dispatch;
use GnuCms\Aligo\Settings;
use GnuCms\Aligo\SettingsRepository;
use GnuCms\Aligo\SmsApi;
use GnuCms\Aligo\Templates;
use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Tests\Support\DatabaseTestCase;
use GnuCms\Tests\Support\FakeAligoTransport;
use PHPUnit\Framework\Attributes\DataProvider;

final class DispatchTest extends DatabaseTestCase
{
    private FakeAligoTransport $transport;
    private Dispatch $dispatch;
    private Templates $templates;
    private Settings $settings;
    private Connection $db;

    private function boot(array $config): void
    {
        $this->db = $this->freshDatabase($config);
        $this->settings = new Settings(new SettingsRepository($this->db), new SecretCipher('s'));
        $this->settings->save(['user_id' => 'shop', 'api_key' => 'K', 'sender' => '0212345678', 'senderkey' => 'SK1']);
        $this->transport = new FakeAligoTransport();
        $alimtalk = new AlimtalkApi($this->transport, $this->settings);
        $sms = new SmsApi($this->transport, $this->settings);
        $this->templates = new Templates($this->db, $alimtalk, $this->settings);
        $this->dispatch = new Dispatch($this->db, $alimtalk, $sms, $this->templates, $this->settings);
    }

    private function queueSmsOk(int $count): void
    {
        $this->transport->queue(200, (string) json_encode(
            ['result_code' => 1, 'msg_id' => 'M' . $count, 'success_cnt' => $count, 'error_cnt' => 0]));
    }

    #[DataProvider('connectionProvider')]
    public function testRefusesWhenTheChannelIsNotAllowed(array $config): void
    {
        $this->boot($config);
        $this->expectException(DomainError::class);
        $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'recipients' => [['phone' => '01012345678']]]);
    }

    #[DataProvider('connectionProvider')]
    public function testSendsTextAndRecordsEveryRecipient(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(2);

        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '#{이름}님 안녕하세요', 'recipients' => [
            ['phone' => '010-1234-5678', 'name' => '홍길동', 'vars' => ['이름' => '홍길동']],
            ['phone' => '01098765432', 'name' => '김철수', 'vars' => ['이름' => '김철수']],
        ]]);

        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertSame('sms', $job['channel']);
        self::assertSame(2, (int) $job['total']);
        self::assertSame(2, (int) $job['success']);
        self::assertSame('sent', $job['status']);

        $rows = $this->db->select('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ? ORDER BY id', [$jobId]);
        self::assertSame('01012345678', $rows[0]['phone']);
        self::assertSame('홍길동님 안녕하세요', $rows[0]['body']);
        self::assertSame('김철수님 안녕하세요', $rows[1]['body']);
        self::assertSame('accepted', $rows[0]['status']);

        $fields = $this->transport->requests[0]['fields'];
        self::assertSame('2', $fields['cnt']);
        self::assertSame('01012345678', $fields['rec_1']);
        self::assertSame('01098765432', $fields['rec_2']);
    }

    #[DataProvider('connectionProvider')]
    public function testSwitchesToLmsWhenTheBodyIsLong(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(1);

        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => str_repeat('가', 46),
            'title' => '안내', 'recipients' => [['phone' => '01012345678']]]);

        self::assertSame('lms', $this->db->selectOne('SELECT channel FROM '
            . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId])['channel']);
        self::assertSame('LMS', $this->transport->requests[0]['fields']['msg_type']);
    }

    /**
     * 분류는 치환이 끝난 본문으로 해야 한다. 템플릿 자체는 90바이트 이하라 SMS 로
     * 보이지만, 변수를 채우면 그중 한 명이라도 90바이트를 넘으면 작업 전체가 LMS 다.
     * (거꾸로 분류하면 msg_type 을 명시했기 때문에 알리고가 자동으로 승격해 주지
     * 않는다 — 그대로 잘리거나 거절된다.)
     */
    #[DataProvider('connectionProvider')]
    public function testClassifiesAsLmsWhenSubstitutionMakesTheBodyLongEvenThoughTheTemplateLooksShort(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(2);

        $long = str_repeat('가나다라마바사아자차', 5); // 50자 — 치환 전 템플릿은 짧지만 이 값이 들어가면 90바이트를 넘는다
        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '#{이름}님 축하드립니다', 'recipients' => [
            ['phone' => '01012345678', 'vars' => ['이름' => $long]],
            ['phone' => '01098765432', 'vars' => ['이름' => '김']],
        ]]);

        self::assertSame('lms', $this->db->selectOne('SELECT channel FROM '
            . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId])['channel']);
        self::assertSame('LMS', $this->transport->requests[0]['fields']['msg_type']);
    }

    #[DataProvider('connectionProvider')]
    public function testSplitsIntoChunksOfFiveHundred(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(500);
        $this->queueSmsOk(2);

        $recipients = [];
        for ($i = 0; $i < 502; $i++) {
            $recipients[] = ['phone' => '010' . str_pad((string) $i, 8, '0', STR_PAD_LEFT)];
        }
        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요', 'recipients' => $recipients]);

        self::assertCount(2, $this->transport->requests);
        self::assertSame('500', $this->transport->requests[0]['fields']['cnt']);
        self::assertSame('2', $this->transport->requests[1]['fields']['cnt']);
        self::assertSame(502, (int) $this->db->selectOne('SELECT total FROM '
            . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId])['total']);
        // mid 는 묶음마다 다르므로 수신자 쪽에 적힌다.
        self::assertSame('M500', $this->db->selectOne('SELECT mid FROM '
            . $this->db->table('message_recipients') . ' WHERE job_id = ? ORDER BY id', [$jobId])['mid']);
    }

    #[DataProvider('connectionProvider')]
    public function testRefusesTheWholeJobWhenAVariableIsBlank(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);

        try {
            $this->dispatch->send(['channel' => 'sms', 'body' => '#{이름}님', 'recipients' => [
                ['phone' => '01012345678', 'vars' => ['이름' => '홍길동']],
                ['phone' => '01098765432', 'vars' => []],
            ]]);
            self::fail('빈 변수가 있으면 작업을 만들지 않아야 한다');
        } catch (DomainError $e) {
            self::assertStringContainsString('이름', $e->details()['vars']);
        }
        self::assertSame(0, (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM '
            . $this->db->table('message_jobs'))['c']);
        self::assertSame([], $this->transport->requests);
    }

    #[DataProvider('connectionProvider')]
    public function testDropsDuplicateNumbers(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(1);

        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요', 'recipients' => [
            ['phone' => '010-1234-5678'], ['phone' => '01012345678'],
        ]]);

        self::assertSame(1, (int) $this->db->selectOne('SELECT total FROM '
            . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId])['total']);
    }

    #[DataProvider('connectionProvider')]
    public function testAlimtalkNeedsAnEnabledTemplateAndSendsFailoverText(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('at', true);
        $this->transport->queue(200, (string) json_encode(['code' => 0, 'list' => [[
            'templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '#{이름}님 안녕하세요',
            'status' => 'A', 'inspStatus' => 'APR']]]));
        $this->templates->fetch();
        $this->templates->setEnabled('T1', true);
        $this->transport->queue(200, (string) json_encode(
            ['code' => 0, 'info' => ['mid' => 'A1', 'scnt' => 1, 'fcnt' => 0]]));

        $jobId = $this->dispatch->send(['channel' => 'at', 'tpl_code' => 'T1', 'failover' => true,
            'recipients' => [['phone' => '01012345678', 'vars' => ['이름' => '홍길동']]]]);

        $fields = $this->transport->requests[1]['fields'];
        self::assertSame('T1', $fields['tpl_code']);
        self::assertSame('홍길동님 안녕하세요', $fields['message_1']);
        self::assertSame('Y', $fields['failover']);
        self::assertSame('홍길동님 안녕하세요', $fields['fmessage_1']);
        self::assertSame(1, (int) $this->db->selectOne('SELECT failover FROM '
            . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId])['failover']);
    }

    #[DataProvider('connectionProvider')]
    public function testAlimtalkRefusesATemplateThatIsNotTurnedOn(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('at', true);
        $this->expectException(DomainError::class);
        $this->dispatch->send(['channel' => 'at', 'tpl_code' => 'NOPE',
            'recipients' => [['phone' => '01012345678']]]);
    }

    #[DataProvider('connectionProvider')]
    public function testAFailedChunkIsRecordedAndNotRetried(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->transport->queueFailure();

        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'recipients' => [['phone' => '01012345678']]]);

        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertSame('failed', $job['status']);
        self::assertSame(1, (int) $job['failure']);
        self::assertCount(1, $this->transport->requests, '발송은 재시도하지 않는다');
        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [$jobId]);
        self::assertSame('failed', $row['status']);
        self::assertNotSame('', (string) $row['rslt_message']);
    }

    #[DataProvider('connectionProvider')]
    public function testTestModeIsPassedThroughAndRecorded(array $config): void
    {
        $this->boot($config);
        $this->settings->save(['user_id' => 'shop', 'api_key' => 'K', 'sender' => '0212345678',
            'senderkey' => 'SK1', 'test_mode' => '1']);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(1);

        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'recipients' => [['phone' => '01012345678']]]);

        self::assertSame('Y', $this->transport->requests[0]['fields']['testmode_yn']);
        self::assertSame(1, (int) $this->db->selectOne('SELECT test_mode FROM '
            . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId])['test_mode']);
    }
}
