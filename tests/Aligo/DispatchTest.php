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
use GnuCms\Support\Clock;
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
        self::assertSame(2, (int) $job['success'], '알리고가 접수한 건수');
        // 접수 직후에는 아직 아무 전달도 확인되지 않았다 — 'sent'(전원 성공)는 결과를
        // 다 확인한 뒤에만 붙는다. 여기서 'sent' 라고 적으면 수신자는 "결과를 기다리는
        // 중"인데 작업은 "성공"이라고 말하는, 한 화면이 스스로 모순되는 이력이 된다.
        self::assertSame('sending', $job['status']);
        self::assertNull($job['finished_at'], '아직 기다리는 수신자가 있으면 끝난 것이 아니다');

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

    /**
     * 알림톡 본문 자체는 UTF-8 이라 이모지를 그대로 보낼 수 있지만, 실패하면 그 본문이
     * 그대로 대체문자(fmessage_N)로 나가고 대체문자는 문자 API와 같은 EUC-KR 제약을 받는다.
     * 이모지는 템플릿 원문이 아니라 변수값으로만 들어간다 — 원문만 검사했다면 걸러지지
     * 않았을 경우를 확인한다.
     */
    #[DataProvider('connectionProvider')]
    public function testAlimtalkWithFailoverRefusesABodyEucKrCannotCarry(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('at', true);
        $this->transport->queue(200, (string) json_encode(['code' => 0, 'list' => [[
            'templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '#{이름}님 안녕하세요',
            'status' => 'A', 'inspStatus' => 'APR']]]));
        $this->templates->fetch();


        try {
            $this->dispatch->send(['channel' => 'at', 'tpl_code' => 'T1', 'failover' => true,
                'recipients' => [['phone' => '01012345678', 'vars' => ['이름' => '🎉동']]]]);
            self::fail('대체문자로 나갈 본문에 EUC-KR 로 옮길 수 없는 글자가 있으면 거절해야 한다');
        } catch (DomainError $e) {
            self::assertStringContainsString('🎉', $e->details()['body']);
        }
        self::assertSame(0, (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM '
            . $this->db->table('message_jobs'))['c']);
        // 템플릿 원문(변수 치환 전)에는 이모지가 없다 — 검사가 원문이 아니라 치환된
        // 수신자별 본문을 보고 있다는 증거다.
        self::assertStringNotContainsString('🎉', (string) $this->templates->find('T1')['content']);
        // 템플릿 가져오기(fetch) 요청 하나만 있고, 실제 발송 호출은 없어야 한다.
        self::assertCount(1, $this->transport->requests);
    }

    /**
     * 같은 이모지라도 대체발송을 켜지 않았다면 알림톡 본문 자체는 EUC-KR 제약을 받지
     * 않으므로 그대로 나가야 한다 — 위 거절이 과도하게 넓지 않은지 함께 확인한다.
     */
    #[DataProvider('connectionProvider')]
    public function testAlimtalkWithoutFailoverSendsABodyEucKrCannotCarryUnchanged(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('at', true);
        $this->transport->queue(200, (string) json_encode(['code' => 0, 'list' => [[
            'templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '#{이름}님 안녕하세요',
            'status' => 'A', 'inspStatus' => 'APR']]]));
        $this->templates->fetch();

        $this->transport->queue(200, (string) json_encode(
            ['code' => 0, 'info' => ['mid' => 'A1', 'scnt' => 1, 'fcnt' => 0]]));

        $jobId = $this->dispatch->send(['channel' => 'at', 'tpl_code' => 'T1',
            'recipients' => [['phone' => '01012345678', 'vars' => ['이름' => '🎉동']]]]);

        $fields = $this->transport->requests[1]['fields'];
        self::assertSame('🎉동님 안녕하세요', $fields['message_1']);
        self::assertArrayNotHasKey('fmessage_1', $fields);
        self::assertSame(0, (int) $this->db->selectOne('SELECT failover FROM '
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

    /**
     * 알리고가 실제로 받아들인 뒤, 그 결과를 'accepted'로 남기는 DB 쓰기 자체가 실패하면
     * — 보냈는데 이력에는 "안 보냄"으로 남는 상황이 된다. 그 기록을 보고 관리자가 다시
     * 보내면 실제 전화기에 중복 발송이 된다. 그러니 이 경우는 발송 실패(catch)로 떨어져
     * 'failed'로 덮이면 안 되고, 예외가 그대로 올라가 작업이 'sending'(=모른다)으로
     * 남아야 한다. status 칼럼에 UPDATE 가 걸리면 실패하는 트리거로 그 DB 쓰기 실패를
     * 실제로 일으킨다.
     */
    #[DataProvider('connectionProvider')]
    public function testAcceptedChunkIsNotMisrecordedAsFailedWhenRecordingItFails(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(1);

        $table = $this->db->table('message_recipients');
        $this->db->execute("CREATE TRIGGER trg_boom BEFORE UPDATE ON $table FOR EACH ROW "
            . "BEGIN IF NEW.status = 'accepted' THEN SIGNAL SQLSTATE '45000' "
            . "SET MESSAGE_TEXT = '강제 실패'; END IF; END");

        try {
            $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
                'recipients' => [['phone' => '01012345678']]]);
            self::fail('기록 실패는 그대로 드러나야 한다');
        } catch (DomainError $e) {
            // 기대한 대로다 — 전송은 성공했지만 결과를 남기지 못했다는 사실이 조용히
            // 삼켜지지 않고 호출자에게 올라간다.
        }

        self::assertCount(1, $this->transport->requests, '발송은 재시도하지 않는다');

        // 이 테스트는 이 send() 호출 하나만 하는 신선한 DB 이므로 행은 각 표에 하나뿐이다.
        $job = $this->db->selectOne('SELECT status FROM ' . $this->db->table('message_jobs'));
        self::assertSame('sending', $job['status'], '보낸 것을 실패로 둔갑시키면 안 된다');

        $row = $this->db->selectOne('SELECT status FROM ' . $table);
        self::assertNotSame('failed', $row['status'], '보낸 메시지를 실패로 잘못 기록하면 안 된다');
    }

    /** 한 묶음은 성공하고 다른 묶음은 실패하면, 상태만 보고도 "일부만 안 갔다"를 알 수 있어야 한다. */
    #[DataProvider('connectionProvider')]
    public function testPartiallyFailedJobIsRecordedAsPartial(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(500);
        $this->transport->queueFailure();

        $recipients = [];
        for ($i = 0; $i < 502; $i++) {
            $recipients[] = ['phone' => '010' . str_pad((string) $i, 8, '0', STR_PAD_LEFT)];
        }
        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요', 'recipients' => $recipients]);

        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertSame('partial', $job['status']);
        self::assertSame(500, (int) $job['success']);
        self::assertSame(2, (int) $job['failure']);
    }

    /**
     * 알리고 문자 API 는 EUC-KR 서비스라 응답 본문도 EUC-KR 로 올 수 있다. 그 응답을
     * 읽지 못해 예외가 나면, 이미 알리고가 받아들여 전화기가 울린 묶음이 통째로
     * 'failed' 로 기록된다 — 그걸 본 관리자가 다시 보내면 중복 발송·이중 과금이다.
     * 지금까지의 모든 시험이 UTF-8 응답만 먹여 왔기 때문에 보이지 않던 경로다.
     */
    #[DataProvider('connectionProvider')]
    public function testAnEucKrResponseIsRecordedAsAcceptedNotFailed(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $json = (string) json_encode(['result_code' => 1, 'message' => '성공적으로 전송요청 하였습니다.',
            'msg_id' => 'M42', 'success_cnt' => 2, 'error_cnt' => 0], JSON_UNESCAPED_UNICODE);
        $this->transport->queue(200, (string) mb_convert_encoding($json, 'EUC-KR', 'UTF-8'));

        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요', 'recipients' => [
            ['phone' => '01012345678'], ['phone' => '01098765432'],
        ]]);

        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertNotSame('failed', $job['status'], '보낸 것을 실패로 둔갑시키면 관리자가 다시 보낸다');
        self::assertSame(0, (int) $job['failure']);
        $rows = $this->db->select('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ? ORDER BY id', [$jobId]);
        self::assertSame('accepted', $rows[0]['status']);
        self::assertSame('accepted', $rows[1]['status']);
        self::assertSame('M42', $rows[0]['mid'], '응답의 mid 를 읽어야 결과 조회도 할 수 있다');
    }

    /**
     * 알리고는 묶음마다 접수 성공·실패 건수를 돌려준다. 묶음 크기로 세면 알리고가
     * 2건을 거절했다고 답해도 이력에는 500건 접수 성공으로 남는다.
     */
    #[DataProvider('connectionProvider')]
    public function testUsesAligoReportedCountsInsteadOfTheChunkSize(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->transport->queue(200, (string) json_encode(
            ['result_code' => 1, 'msg_id' => 'M1', 'success_cnt' => 3, 'error_cnt' => 2]));

        $recipients = [];
        for ($i = 0; $i < 5; $i++) {
            $recipients[] = ['phone' => '010' . str_pad((string) $i, 8, '0', STR_PAD_LEFT)];
        }
        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요', 'recipients' => $recipients]);

        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertSame(3, (int) $job['success']);
        self::assertSame(2, (int) $job['failure']);
        // 이미 확인된 실패가 있으므로 '일부 실패'다 — 나머지는 아직 결과를 기다린다.
        self::assertSame('partial', $job['status']);
    }

    /** 알림톡도 같다 — info.scnt·info.fcnt 를 그대로 쓴다. */
    #[DataProvider('connectionProvider')]
    public function testAlimtalkUsesAligoReportedCounts(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('at', true);
        $this->transport->queue(200, (string) json_encode(['code' => 0, 'list' => [[
            'templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '안녕하세요',
            'status' => 'A', 'inspStatus' => 'APR']]]));
        $this->templates->fetch();

        $this->transport->queue(200, (string) json_encode(
            ['code' => 0, 'info' => ['mid' => 'A1', 'scnt' => 1, 'fcnt' => 1]]));

        $jobId = $this->dispatch->send(['channel' => 'at', 'tpl_code' => 'T1', 'recipients' => [
            ['phone' => '01012345678'], ['phone' => '01098765432'],
        ]]);

        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertSame(1, (int) $job['success']);
        self::assertSame(1, (int) $job['failure']);
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

    #[DataProvider('connectionProvider')]
    public function testSchedulesInsteadOfSendingNow(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(1);

        // 'Z' 로 명시적 UTC 오프셋을 붙인다 — 이 값 자체의 시간대는 이 시험의 관심사가
        // 아니라 "미래의 어느 절대 시각"이면 충분하다(오프셋 없이 넘기면 SendTime::parse()
        // 가 KST 로 읽어 9시간 이르게 해석하므로 하한을 벗어난다. SendTimeTest 참고).
        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'scheduled_at' => $at, 'recipients' => [['phone' => '01012345678']]]);

        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
        self::assertSame('scheduled', $job['status']);
        self::assertNotNull($job['scheduled_at']);

        $fields = $this->transport->requests[0]['fields'];
        self::assertArrayHasKey('rdate', $fields);
        self::assertArrayHasKey('rtime', $fields);
    }

    /**
     * FIX 1: 확장이 부르는 $app->aligo()->send(['scheduled_at' => …]) 가 실제로 거치는
     * 경로가 바로 Dispatch::send() 다. 오프셋 없는 한국 시각 벽시계 값을 그대로 넘기면
     * SendTime::parse() 가 그 값을 KST 로 읽으므로, 관리자 화면과 마찬가지로 발신자가
     * 뜻한 절대 시각 그대로 예약돼야 한다(9시간 어긋나면 안 된다).
     */
    #[DataProvider('connectionProvider')]
    public function testBareKoreanWallClockThroughTheExtensionEntryBooksTheInstantTheCallerMeant(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(1);

        $targetUtc = Clock::timestamp() + 3600;
        $bareKst = gmdate('Y-m-d\TH:i', $targetUtc + 9 * 3600);

        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'scheduled_at' => $bareKst, 'recipients' => [['phone' => '01012345678']]]);

        $job = $this->db->selectOne('SELECT scheduled_at FROM ' . $this->db->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
        self::assertSame(gmdate('Y-m-d H:i:00', $targetUtc), $job['scheduled_at']);
    }

    /**
     * 같은 벽시계 숫자에 "+09:00"을 명시적으로 붙여도 오프셋 없는 입력과 정확히 같은
     * 절대 시각(=같은 저장값)이 나와야 한다 — 오프셋 없는 입력이 이미 KST 로 읽히므로,
     * "+09:00"은 그 읽기를 확인해 줄 뿐이다.
     */
    #[DataProvider('connectionProvider')]
    public function testExplicitKstOffsetThroughTheExtensionEntryBooksTheSameInstantAsBareInput(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(1);
        $this->queueSmsOk(1);

        $targetUtc = Clock::timestamp() + 3600;
        $bareKst = gmdate('Y-m-d\TH:i', $targetUtc + 9 * 3600);

        $bareJobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'scheduled_at' => $bareKst, 'recipients' => [['phone' => '01012345678']]]);
        $offsetJobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'scheduled_at' => $bareKst . '+09:00', 'recipients' => [['phone' => '01098765432']]]);

        $bareAt = $this->db->selectOne('SELECT scheduled_at FROM ' . $this->db->table('message_jobs')
            . ' WHERE id = ?', [$bareJobId])['scheduled_at'];
        $offsetAt = $this->db->selectOne('SELECT scheduled_at FROM ' . $this->db->table('message_jobs')
            . ' WHERE id = ?', [$offsetJobId])['scheduled_at'];
        self::assertSame($bareAt, $offsetAt);
    }

    /**
     * 다른 명시적 오프셋("+00:00")은 같은 벽시계 숫자라도 다른 절대 시각을 가리켜야
     * 한다 — 이것이 "오프셋이 실제로 존중된다"의 증거다: "+00:00"으로 준 값은 그
     * 자체가 UTC 이므로, KST 로 읽은 값(=$targetUtc)보다 정확히 9시간 뒤에 저장된다.
     */
    #[DataProvider('connectionProvider')]
    public function testADifferentExplicitOffsetThroughTheExtensionEntryBooksADifferentInstant(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(1);

        $targetUtc = Clock::timestamp() + 3600;
        $bareKst = gmdate('Y-m-d\TH:i', $targetUtc + 9 * 3600);

        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'scheduled_at' => $bareKst . '+00:00', 'recipients' => [['phone' => '01012345678']]]);

        $job = $this->db->selectOne('SELECT scheduled_at FROM ' . $this->db->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
        self::assertSame(gmdate('Y-m-d H:i:00', $targetUtc + 9 * 3600), $job['scheduled_at']);
    }

    /** 알림톡은 rdate·rtime 이 아니라 senddate 한 칸을 쓴다. */
    #[DataProvider('connectionProvider')]
    public function testAlimtalkScheduleUsesSenddate(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('at', true);
        $this->transport->queue(200, (string) json_encode(['code' => 0, 'list' => [[
            'templtCode' => 'T1', 'templtName' => '안내', 'templtContent' => '#{이름}님 안녕하세요',
            'status' => 'A', 'inspStatus' => 'APR']]]));
        $this->templates->fetch();

        $this->transport->queue(200, (string) json_encode(
            ['code' => 0, 'info' => ['mid' => 'A1', 'scnt' => 1, 'fcnt' => 0]]));

        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $jobId = $this->dispatch->send(['channel' => 'at', 'tpl_code' => 'T1', 'scheduled_at' => $at,
            'recipients' => [['phone' => '01012345678', 'vars' => ['이름' => '홍길동']]]]);

        $fields = $this->transport->requests[1]['fields'];
        self::assertMatchesRegularExpression('/^\d{14}$/D', $fields['senddate']);
        self::assertArrayNotHasKey('rdate', $fields);
        self::assertArrayNotHasKey('rtime', $fields);
        self::assertSame('scheduled', $this->db->selectOne('SELECT status FROM '
            . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId])['status']);
    }

    /**
     * 알리고가 예약 접수를 하나도 받아들이지 않았다면(부팅 자체가 실패) 이 예약은
     * 존재하지 않는다 — 'scheduled'라고 적으면 화면이 시스템이 모르는 사실을 안다고
     * 말하는 것이고, 나중에(4단계) 취소를 시도하면 존재하지도 않는 mid를 취소하려 든다.
     */
    #[DataProvider('connectionProvider')]
    public function testAScheduleThatFailsToBookReadsFailedNotScheduled(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->transport->queueFailure();

        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'scheduled_at' => $at, 'recipients' => [['phone' => '01012345678']]]);

        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertSame('failed', $job['status'], '알리고가 하나도 접수하지 않았으면 예약됐다고 적으면 안 된다');
        self::assertNotNull($job['finished_at'], '더 일어날 일이 없으므로 끝난 시각이 있어야 한다');

        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients') . ' WHERE job_id = ?', [$jobId]);
        self::assertSame('failed', $row['status']);
        self::assertNull($row['mid'], '접수되지 못한 수신자는 mid 가 없어야 한다 — 나중에 취소 대상이 되면 안 된다');
    }

    /**
     * 502명이면 묶음이 둘이다(500 + 2). 첫 묶음은 접수되고 둘째 묶음은 접수 자체가
     * 실패해도, 첫 묶음은 실제로 나갈 것이고 취소도 할 수 있으므로 예약은 살아 있다.
     * 'partial'로 적지 않는다 — partial은 "이미 다 끝났는데 일부 실패"를 뜻하고,
     * 예약은 아직 끝나지 않았다(그 시각에 나간다).
     */
    #[DataProvider('connectionProvider')]
    public function testAPartlyBookedScheduleStaysScheduledWithFailureCounted(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(500);
        $this->transport->queueFailure();

        $recipients = [];
        for ($i = 0; $i < 502; $i++) {
            $recipients[] = ['phone' => '010' . str_pad((string) $i, 8, '0', STR_PAD_LEFT)];
        }
        $at = gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z';
        $jobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'scheduled_at' => $at, 'recipients' => $recipients]);

        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs') . ' WHERE id = ?', [$jobId]);
        self::assertSame('scheduled', $job['status'], '일부라도 접수됐으면 예약은 살아 있다 — partial 이 아니다');
        self::assertSame(500, (int) $job['success']);
        self::assertSame(2, (int) $job['failure']);
        self::assertNull($job['finished_at']);
    }

    #[DataProvider('connectionProvider')]
    public function testARefusedScheduleCreatesNoJobAndSendsNothing(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);

        try {
            $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
                'scheduled_at' => gmdate('Y-m-d\TH:i', Clock::timestamp() + 60),
                'recipients' => [['phone' => '01012345678']]]);
            self::fail('10분 안쪽 예약은 거절해야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('scheduled_at', $e->details());
        }
        self::assertSame([], $this->transport->requests);
        self::assertSame(0, (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM '
            . $this->db->table('message_jobs'))['c']);
    }

    #[DataProvider('connectionProvider')]
    public function testAScheduledSendStillNeedsTheChannelSwitch(array $config): void
    {
        $this->boot($config);
        $this->expectException(DomainError::class);
        $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'scheduled_at' => gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z',
            'recipients' => [['phone' => '01012345678']]]);
    }

    /** @return array{0:int} 예약된 작업 id 하나 (502명, 두 묶음 500+2, 둘 다 접수됨) */
    private function bookScheduledJobOfFiveHundredTwo(): int
    {
        $this->queueSmsOk(500);
        $this->queueSmsOk(2);
        $recipients = [];
        for ($i = 0; $i < 502; $i++) {
            $recipients[] = ['phone' => '010' . str_pad((string) $i, 8, '0', STR_PAD_LEFT)];
        }

        return $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'scheduled_at' => gmdate('Y-m-d\TH:i', Clock::timestamp() + 3600) . 'Z',
            'recipients' => $recipients]);
    }

    #[DataProvider('connectionProvider')]
    public function testCancelsEveryMidOfTheJob(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $jobId = $this->bookScheduledJobOfFiveHundredTwo();

        $this->transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $this->transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $requestsBeforeCancel = count($this->transport->requests);

        $result = $this->dispatch->cancel($jobId);

        self::assertSame(2, $result['cancelled']);
        self::assertSame(0, $result['failed']);
        self::assertSame([], $result['reasons']);
        // mid 는 묶음마다 하나다 — 502명이라도 취소 요청은 2건(mid 개수)이어야 한다.
        // 수신자 수만큼(502건) 부르면 알리고에 존재하지도 않는 취소 요청을 500번 더
        // 보내는 셈이다.
        self::assertCount($requestsBeforeCancel + 2, $this->transport->requests);

        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
        self::assertSame('cancelled', $job['status']);
        self::assertNotNull($job['cancelled_at']);

        $rows = $this->db->select('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ? ORDER BY id', [$jobId]);
        foreach ($rows as $row) {
            self::assertSame('cancelled', $row['status']);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testReportsAPartialCancellationInsteadOfHidingIt(array $config): void
    {
        // 두 묶음 중 하나만 취소된다. 작업은 취소되지 않은 것으로 남고, 이유가 그대로 올라온다.
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $jobId = $this->bookScheduledJobOfFiveHundredTwo();

        $this->transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $this->transport->queue(200, '{"result_code":-804,"message":"too late"}');

        $result = $this->dispatch->cancel($jobId);

        self::assertSame(1, $result['cancelled']);
        self::assertSame(1, $result['failed']);
        self::assertNotSame([], $result['reasons']);
        $job = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
        self::assertSame('scheduled', $job['status'], '남은 묶음이 아직 나갈 것이므로 취소됐다고 적으면 안 된다');
        self::assertNull($job['cancelled_at']);

        $rows = $this->db->select('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ? ORDER BY id', [$jobId]);
        // 500명 묶음(mid=M500)이 먼저 취소됐고, 2명 묶음(mid=M2)은 취소에 실패해 그대로다.
        self::assertSame('cancelled', $rows[0]['status']);
        self::assertSame('accepted', $rows[501]['status']);
    }

    /**
     * 취소에 실패한 묶음의 사유는 그 수신자 행에 남는다(이력 상세의 "사유" 칸이 보여준다).
     * 그런데 관리자가 다시 취소를 눌러 이번에는 성공하면 그 문장은 더는 참이 아니다 —
     * 지우지 않으면 상태는 '취소됨'인데 사유는 "취소하지 못했습니다"라고 말하는 행이
     * 영원히 남는다. 이 행은 더는 결과 조회를 타지 않으므로(History::apply() 는
     * 'accepted' 행만 건드린다) 아무도 대신 정리해 주지 않는다. 그래서 상태만이 아니라
     * 사유가 사라졌다는 것까지 확인한다 — 상태만 보면 이 시험은 아무것도 증명하지 못한다.
     */
    #[DataProvider('connectionProvider')]
    public function testASuccessfulRetryClearsTheEarlierCancellationFailureReason(array $config): void
    {
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $jobId = $this->bookScheduledJobOfFiveHundredTwo();

        // 첫 시도: 500명 묶음은 취소되고 2명 묶음은 실패한다.
        $this->transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $this->transport->queue(200, '{"result_code":-201,"message":"not registered"}');
        $this->dispatch->cancel($jobId);

        $failedRow = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ? ORDER BY id DESC', [$jobId]);
        self::assertStringContainsString('취소하지 못했습니다', (string) $failedRow['rslt_message'],
            '첫 시도의 실패 사유는 그 행에 남아야 한다');

        // 두 번째 시도: 남은 묶음만 다시 부르고, 이번에는 성공한다.
        $requestsBeforeRetry = count($this->transport->requests);
        $this->transport->queue(200, '{"result_code":1,"cancel_date":"2026-09-18 10:00:00"}');
        $result = $this->dispatch->cancel($jobId);

        self::assertSame(1, $result['cancelled'], '이미 취소된 묶음은 다시 부르지 않는다');
        self::assertSame(0, $result['failed']);
        self::assertCount($requestsBeforeRetry + 1, $this->transport->requests);

        $rows = $this->db->select('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ? ORDER BY id', [$jobId]);
        foreach ($rows as $row) {
            self::assertSame('cancelled', $row['status']);
            self::assertNull($row['rslt_message'],
                '멈춘 행이 "취소하지 못했습니다"라고 말하면 안 된다');
        }
        $job = $this->db->selectOne('SELECT status FROM ' . $this->db->table('message_jobs')
            . ' WHERE id = ?', [$jobId]);
        self::assertSame('cancelled', $job['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testRefusesToCancelWhatIsNotScheduled(array $config): void
    {
        // 즉시 발송한 작업은 취소할 수 없다 — 이미 나갔다.
        $this->boot($config);
        $this->settings->setEnabled('sms', true);
        $this->queueSmsOk(1);
        $immediateJobId = $this->dispatch->send(['channel' => 'sms', 'body' => '안녕하세요',
            'recipients' => [['phone' => '01012345678']]]);

        $requestsBeforeCancel = count($this->transport->requests);
        try {
            $this->dispatch->cancel($immediateJobId);
            self::fail('예약이 아닌 작업은 취소할 수 없어야 한다');
        } catch (DomainError $e) {
            // 기대한 대로다.
        }
        // 예외가 호출 자체를 막았는지, 아니면 알리고를 먼저 부르고 나서 실패했는지가
        // 갈린다 — 요청 수가 그대로여야 후자가 아니라는 증거가 된다.
        self::assertCount($requestsBeforeCancel, $this->transport->requests,
            '취소 요청이 알리고로 나가면 안 된다');
    }
}
