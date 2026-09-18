<?php

declare(strict_types=1);

namespace GnuCms\Tests\Notify;

use GnuCms\Aligo\AligoService;
use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Notify\AlimtalkChannel;
use GnuCms\Notify\MailChannel;
use GnuCms\Notify\InboxChannel;
use GnuCms\Notify\NotifySettings;
use GnuCms\Notify\Recipient;
use GnuCms\Notify\SettingsRepository;
use GnuCms\Notify\SmsChannel;
use GnuCms\Repository\CommentRepository;
use GnuCms\Repository\NotificationRepository;
use GnuCms\Repository\PostRepository;
use GnuCms\Service\NotificationService;
use GnuCms\Tests\Support\CollectingMailer;
use GnuCms\Tests\Support\DatabaseTestCase;
use GnuCms\Tests\Support\FakeAligoTransport;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 채널 넷의 시험대.
 *
 * 알리고를 타는 두 채널은 진짜 AligoService 에 FakeAligoTransport 를 물려 돌린다.
 * 계획의 초안은 send(array): int 만 가진 익명 클래스로 대신하라고 했지만 AligoService·
 * NotifySettings 는 둘 다 final 이라 타입이 맞지 않는다(계획 4 의 초안도 NotifySettings
 * 에 대해 같은 이유로 진짜 객체를 쓰라고 적고 있다). 대신 실제로 남는 message_jobs·
 * message_recipients 행과 알리고로 나간 필드를 본다 — 흉내 낸 배열보다 강한 검사다.
 */
final class ChannelsTest extends DatabaseTestCase
{
    private AligoService $aligo;
    private NotifySettings $notify;
    private Connection $db;
    private FakeAligoTransport $transport;

    /** 알리고 계정은 저장돼 있고 승인 템플릿 T1 이 하나 있는 상태에서 시작한다. */
    private function boot(array $config): void
    {
        $this->db = $this->freshDatabase($config);
        $this->transport = new FakeAligoTransport();
        $this->aligo = new AligoService($this->db, $this->transport, new SecretCipher('s'));
        $this->aligo->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $this->db->insert('alimtalk_templates', ['tpl_code' => 'T1', 'senderkey' => 'SK1',
            'name' => '재설정', 'content' => '#{고객명}님 #{주소} 에서 재설정하세요',
            'status' => 'A', 'insp_status' => 'APR', 'enabled' => 1,
            'fetched_at' => '2026-09-17 10:00:00']);
        // 알림 설정은 알리고와 같은 Templates 사본을 본다 — 템플릿이 죽으면 둘 다 안다.
        $this->notify = new NotifySettings(new SettingsRepository($this->db), $this->aligo->templates);
    }

    private function member(string $phone = '01012345678'): Recipient
    {
        return Recipient::forUser(['id' => '1', 'display_name' => '홍길동',
            'email' => 'a@example.com', 'phone' => $phone]);
    }

    private function jobs(): array
    {
        return $this->db->select('SELECT * FROM ' . $this->db->table('message_jobs') . ' ORDER BY id');
    }

    /** $send 가 반드시 DomainError 를 던져야 하고, 그 details 를 돌려준다. */
    private function refusal(callable $send): array
    {
        try {
            $send();
        } catch (DomainError $e) {
            return $e->details();
        }

        self::fail('보낼 수 없는 상태인데 거절하지 않았습니다');
    }

    private function notificationService(): NotificationService
    {
        return new NotificationService(new NotificationRepository($this->db),
            new PostRepository($this->db), new CommentRepository($this->db));
    }

    public function testMailIsUnavailableWithoutAnAddress(): void
    {
        $channel = new MailChannel(new CollectingMailer());

        self::assertSame('mail', $channel->key());
        self::assertTrue($channel->available('password_reset',
            Recipient::forEmail('a@example.com', '홍길동')));
        self::assertFalse($channel->available('password_reset',
            Recipient::forUser(['id' => '1', 'display_name' => '홍길동', 'email' => ''])));
    }

    public function testMailSendsTheRenderedSubjectAndBody(): void
    {
        $mailer = new CollectingMailer();
        (new MailChannel($mailer))->send('password_reset',
            Recipient::forEmail('a@example.com', '홍길동'),
            ['사이트명' => '우리 커뮤니티', '이름' => '홍길동',
                '링크' => 'https://example.com/r', '유효시간' => '1시간']);

        self::assertSame('a@example.com', $mailer->messages[0]['to']);
        self::assertSame('[우리 커뮤니티] 비밀번호 재설정', $mailer->messages[0]['subject']);
        self::assertStringContainsString('https://example.com/r', $mailer->messages[0]['body']);
    }

    /** available() 이 거절할 상태에서는 send() 도 보내지 않는다 — 조용히 넘어가지 않고
     *  거절해서, available() 을 건너뛴 호출자가 드러나게 한다. */
    public function testMailRefusesToSendWithoutAnAddress(): void
    {
        $mailer = new CollectingMailer();
        $channel = new MailChannel($mailer);

        self::assertSame(['mail'], array_keys($this->refusal(fn () => $channel->send(
            'password_reset', Recipient::forUser(['id' => '1', 'display_name' => '홍']), []))));
        self::assertSame([], $mailer->messages, '빈 주소로 메일을 내보내지 않는다');
    }

    #[DataProvider('connectionProvider')]
    public function testPhoneChannelsAreUnavailableWithoutANumber(array $config): void
    {
        $this->boot($config);
        $this->aligo->settings->setEnabled('sms', true);
        $this->aligo->settings->setEnabled('at', true);
        $this->notify->save('password_reset', ['sms' => '1', 'sms_body' => '#{이름}님 #{링크}',
            'alimtalk' => '1', 'tpl_code' => 'T1', 'var_map' => ['고객명' => '이름', '주소' => '링크']]);

        $sms = new SmsChannel($this->aligo, $this->notify);
        self::assertSame('sms', $sms->key());
        self::assertTrue($sms->available('password_reset', $this->member()));
        self::assertFalse($sms->available('password_reset', $this->member('')));

        $alimtalk = new AlimtalkChannel($this->aligo, $this->notify);
        self::assertSame('alimtalk', $alimtalk->key());
        self::assertTrue($alimtalk->available('password_reset', $this->member()));
        self::assertFalse($alimtalk->available('password_reset', $this->member('')));
    }

    #[DataProvider('connectionProvider')]
    public function testTextChannelSubstitutesTheConfiguredBody(array $config): void
    {
        $this->boot($config);
        $this->aligo->settings->setEnabled('sms', true);
        $this->notify->save('password_reset', ['sms' => '1', 'sms_body' => '#{이름}님 #{링크}']);
        $this->transport->queue(200, (string) json_encode(
            ['result_code' => 1, 'msg_id' => 'M1', 'success_cnt' => 1, 'error_cnt' => 0]));

        (new SmsChannel($this->aligo, $this->notify))->send('password_reset', $this->member(),
            ['이름' => '홍길동', '링크' => 'https://example.com/r']);

        $job = $this->jobs()[0];
        self::assertSame('sms', $job['channel']);
        self::assertSame('#{이름}님 #{링크}', $job['body']);
        // 발송 이력에서 비밀번호 재설정과 가입 환영을 구별할 수 있어야 한다.
        self::assertSame('password_reset', $job['event_key']);

        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [(int) $job['id']]);
        self::assertSame('01012345678', $row['phone']);
        self::assertSame('홍길동님 https://example.com/r', $row['body']);
        self::assertSame('1', (string) $row['user_id']);
        self::assertSame('홍길동', $row['name']);
        // 문자 API 는 본문을 EUC-KR 로 실어 보낸다 — 실제로 그 길을 탔는지까지 본다.
        self::assertSame(mb_convert_encoding('홍길동님 https://example.com/r', 'EUC-KR', 'UTF-8'),
            $this->transport->requests[0]['fields']['msg_1']);
    }

    #[DataProvider('connectionProvider')]
    public function testAlimtalkChannelRenamesVariablesThroughTheMap(array $config): void
    {
        // 템플릿은 #{고객명}·#{주소}, 코어는 이름·링크. 매핑이 이어 준다.
        $this->boot($config);
        $this->aligo->settings->setEnabled('at', true);
        $this->notify->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        $this->transport->queue(200, (string) json_encode(
            ['code' => 0, 'info' => ['mid' => 'A1', 'scnt' => 1, 'fcnt' => 0]]));

        (new AlimtalkChannel($this->aligo, $this->notify))->send('password_reset', $this->member(),
            ['이름' => '홍길동', '링크' => 'https://example.com/r']);

        $job = $this->jobs()[0];
        self::assertSame('at', $job['channel']);
        self::assertSame('T1', $job['tpl_code']);
        self::assertSame('password_reset', $job['event_key']);

        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('message_recipients')
            . ' WHERE job_id = ?', [(int) $job['id']]);
        self::assertSame('홍길동님 https://example.com/r 에서 재설정하세요', $row['body']);
        self::assertSame('01012345678', $this->transport->requests[0]['fields']['receiver_1']);
    }

    /** 알리고 채널 스위치가 꺼져 있으면 관리자가 알림을 켜 두었어도 보낼 수 없다.
     *  available() 이 false 를 돌려 건너뛰게 해야, 스위치를 끈 상태에서 알림마다
     *  Dispatch 의 거절 예외가 터지지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testPhoneChannelsAreUnavailableWhileAligoIsSwitchedOff(array $config): void
    {
        $this->boot($config);
        $this->aligo->settings->setEnabled('sms', true);
        $this->aligo->settings->setEnabled('at', true);
        $this->notify->save('password_reset', ['sms' => '1', 'sms_body' => '#{이름}님 #{링크}',
            'alimtalk' => '1', 'tpl_code' => 'T1', 'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        $this->aligo->settings->setEnabled('sms', false);
        $this->aligo->settings->setEnabled('at', false);

        self::assertFalse((new SmsChannel($this->aligo, $this->notify))
            ->available('password_reset', $this->member()));
        self::assertFalse((new AlimtalkChannel($this->aligo, $this->notify))
            ->available('password_reset', $this->member()));
    }

    /** 관리자가 그 알림에서 채널을 꺼 두면 본문·매핑이 남아 있어도 보낼 수 없다
     *  (NotifySettings 가 빈 본문·null 템플릿으로 답한다). */
    #[DataProvider('connectionProvider')]
    public function testPhoneChannelsAreUnavailableWhenTheNotificationHasThemOff(array $config): void
    {
        $this->boot($config);
        $this->aligo->settings->setEnabled('sms', true);
        $this->aligo->settings->setEnabled('at', true);
        $this->notify->save('password_reset', ['sms' => '1', 'sms_body' => '#{이름}님 #{링크}',
            'alimtalk' => '1', 'tpl_code' => 'T1', 'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        $this->notify->save('password_reset', ['mail' => '1']);

        self::assertFalse((new SmsChannel($this->aligo, $this->notify))
            ->available('password_reset', $this->member()));
        self::assertFalse((new AlimtalkChannel($this->aligo, $this->notify))
            ->available('password_reset', $this->member()));
    }

    /** 승인 템플릿이 그 사이 죽으면(승인 취소·삭제·본문 변경) 알림톡은 건너뛴다. */
    #[DataProvider('connectionProvider')]
    public function testAlimtalkIsUnavailableOnceItsTemplateDies(array $config): void
    {
        $this->boot($config);
        $this->aligo->settings->setEnabled('at', true);
        $this->notify->save('password_reset', ['alimtalk' => '1', 'tpl_code' => 'T1',
            'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        $channel = new AlimtalkChannel($this->aligo, $this->notify);
        self::assertTrue($channel->available('password_reset', $this->member()));

        $this->db->update('alimtalk_templates', ['enabled' => 0], 'tpl_code = :code', ['code' => 'T1']);

        self::assertFalse($channel->available('password_reset', $this->member()));
    }

    /**
     * **경계 시험.** 알림함이 쓰는 문맥 값(_post_id)은 어떤 본문에도 닿으면 안 된다.
     * NotifySettings::save() 는 카탈로그 밖 변수를 담은 본문을 거절하므로, 그 검증을
     * 지나간 본문(손으로 고친 DB, 예전 버전이 남긴 값)을 리포지토리로 직접 심어
     * 재현한다. 값을 채워 조용히 내보내지 않고 거절하는 것이 정답이다 — 글번호가
     * 실린 문자는 취소할 수 없다.
     */
    #[DataProvider('connectionProvider')]
    public function testChannelContextCannotReachATextBody(array $config): void
    {
        $this->boot($config);
        $this->aligo->settings->setEnabled('sms', true);
        (new SettingsRepository($this->db))->save([
            'comment_new.configured' => '1', 'comment_new.sms' => '1',
            'comment_new.sms_body' => '#{이름}님 글번호 #{_post_id}',
        ]);
        $channel = new SmsChannel($this->aligo, $this->notify);

        try {
            $channel->send('comment_new', $this->member(), ['이름' => '홍길동', '_post_id' => '77']);
            self::fail('문맥 값이 문자 본문에 실려 나갔습니다');
        } catch (DomainError $e) {
            self::assertSame([], $this->jobs(), '거절했으면 발송 작업도 남지 않아야 한다');
        }
    }

    /** 카탈로그에 선언된 변수만 알림톡 변수로 이어진다 — 문맥을 가리키는 매핑은
     *  NotifySettings 가 거절하므로 채널은 그 이벤트를 아예 보낼 수 없다. */
    #[DataProvider('connectionProvider')]
    public function testChannelContextCannotBeMappedIntoAnAlimtalkTemplate(array $config): void
    {
        $this->boot($config);
        $this->aligo->settings->setEnabled('at', true);
        (new SettingsRepository($this->db))->save([
            'comment_new.configured' => '1', 'comment_new.alimtalk' => '1',
            'comment_new.tpl_code' => 'T1',
            'comment_new.var_map' => (string) json_encode(['고객명' => '이름', '주소' => '_post_id'],
                JSON_UNESCAPED_UNICODE),
        ]);

        $channel = new AlimtalkChannel($this->aligo, $this->notify);
        self::assertFalse($channel->available('comment_new', $this->member()));

        self::assertSame(['alimtalk'], array_keys($this->refusal(
            fn () => $channel->send('comment_new', $this->member(), ['이름' => '홍길동', '_post_id' => '77']))));
        self::assertSame([], $this->jobs());
    }

    /**
     * 저장된 매핑에 템플릿 본문과 무관한 칸이 섞여 있어도(손으로 고친 DB, 옛 버전의
     * 흔적) 발송은 멀쩡해야 한다 — templateFor() 는 템플릿에 실제로 쓰인 변수만
     * 검사하므로 그 밖의 칸은 무엇이든 들어 있을 수 있다.
     */
    #[DataProvider('connectionProvider')]
    public function testAStoredMapWithJunkEntriesStillSends(array $config): void
    {
        $this->boot($config);
        $this->aligo->settings->setEnabled('at', true);
        (new SettingsRepository($this->db))->save([
            'password_reset.configured' => '1', 'password_reset.alimtalk' => '1',
            'password_reset.tpl_code' => 'T1',
            'password_reset.var_map' => (string) json_encode(
                ['고객명' => '이름', '주소' => '링크', '옛변수' => ['이름']], JSON_UNESCAPED_UNICODE),
        ]);
        $this->transport->queue(200, (string) json_encode(
            ['code' => 0, 'info' => ['mid' => 'A1', 'scnt' => 1, 'fcnt' => 0]]));

        (new AlimtalkChannel($this->aligo, $this->notify))->send('password_reset', $this->member(),
            ['이름' => '홍길동', '링크' => 'https://example.com/r']);

        $row = $this->db->selectOne('SELECT body FROM ' . $this->db->table('message_recipients'));
        self::assertSame('홍길동님 https://example.com/r 에서 재설정하세요', $row['body']);
    }

    /**
     * available() 이 거절할 상태에서 send() 를 부르면 아무것도 보내지 않고 거절한다.
     *
     * 알리고 엔진도 결국은 거절하지만(번호가 없으면 수신자 없음, 스위치가 꺼져 있으면
     * 채널 불가) 그 거절은 채널이 아니라 엔진의 사정으로 나온다. 채널이 자기 문 앞에서
     * 먼저 거절해야 "available() 을 묻지 않고 send() 를 불렀다"는 사실이 한 가지 모양
     * (details 의 채널 키)으로 드러난다 — 그래서 여기서는 던졌다는 사실만이 아니라
     * **누가 던졌는지**까지 본다. 아래 두 상태는 모두 available() 이 false 인 상태다.
     */
    #[DataProvider('connectionProvider')]
    public function testPhoneChannelsRefuseToSendWhatAvailableWouldHaveSkipped(array $config): void
    {
        $this->boot($config);
        $this->aligo->settings->setEnabled('sms', true);
        $this->aligo->settings->setEnabled('at', true);
        $this->notify->save('password_reset', ['sms' => '1', 'sms_body' => '#{이름}님 #{링크}',
            'alimtalk' => '1', 'tpl_code' => 'T1', 'var_map' => ['고객명' => '이름', '주소' => '링크']]);
        $vars = ['이름' => '홍길동', '링크' => 'https://example.com/r'];
        $sms = new SmsChannel($this->aligo, $this->notify);
        $alimtalk = new AlimtalkChannel($this->aligo, $this->notify);

        // 1) 받을 번호가 없다.
        self::assertSame(['sms'], array_keys($this->refusal(
            fn () => $sms->send('password_reset', $this->member(''), $vars))));
        self::assertSame(['alimtalk'], array_keys($this->refusal(
            fn () => $alimtalk->send('password_reset', $this->member(''), $vars))));

        // 2) 번호는 있지만 알리고 채널 스위치가 꺼졌다.
        $this->aligo->settings->setEnabled('sms', false);
        $this->aligo->settings->setEnabled('at', false);
        self::assertFalse($sms->available('password_reset', $this->member()));
        self::assertFalse($alimtalk->available('password_reset', $this->member()));
        self::assertSame(['sms'], array_keys($this->refusal(
            fn () => $sms->send('password_reset', $this->member(), $vars))));
        self::assertSame(['alimtalk'], array_keys($this->refusal(
            fn () => $alimtalk->send('password_reset', $this->member(), $vars))));

        self::assertSame([], $this->jobs());
        self::assertSame([], $this->transport->requests);
    }

    /**
     * 알림함 서비스는 **부를 때** 만든다. App 의 게터들은 생성자 주입 + 지연 메모이즈라,
     * notifier() 가 InboxChannel 을 만드는 도중에 notificationService() 를 부르면 아직
     * 메모이즈되지 않은 notifier() 로 되돌아가 무한 재귀가 된다. 그래서 채널은 만들어진
     * 서비스가 아니라 그것을 돌려줄 callable 을 쥔다 — 이 테스트는 그 규칙을 못박는다:
     * 채널을 만들 때도, available() 을 물을 때도 서비스를 만들면 안 된다.
     */
    public function testTheInboxServiceIsResolvedOnlyWhenSomethingIsSent(): void
    {
        $resolved = 0;
        $channel = new InboxChannel(function () use (&$resolved): NotificationService {
            $resolved++;
            self::fail('알림함 서비스를 너무 일찍 만들었습니다');
        });

        self::assertSame('inbox', $channel->key());
        self::assertTrue($channel->available('comment_new',
            Recipient::forUser(['id' => '7', 'display_name' => '홍길동'])));
        self::assertSame(0, $resolved);
    }

    public function testInboxIsOnlyForMembersAndOnlyForComments(): void
    {
        $channel = new InboxChannel(fn (): NotificationService => self::fail('부를 일이 없습니다'));
        $member = Recipient::forUser(['id' => '7', 'display_name' => '홍길동']);
        $guest = Recipient::forEmail('a@example.com', '손님');

        self::assertTrue($channel->available('comment_new', $member));
        self::assertFalse($channel->available('comment_new', $guest), '손님은 알림함이 없다');
        self::assertFalse($channel->available('password_reset', $member),
            '댓글 외의 알림은 알림함에 넣지 않는다');
    }

    /**
     * 알림함에는 두 종류가 있고 회원에게 다르게 읽힌다 — 내 글에 달린 댓글과 내 댓글에
     * 달린 답글. comment_new 이벤트는 하나뿐이므로 종류는 채널 문맥으로 들어온다.
     * 채널이 한 종류로 뭉개면 지금 있는 기능이 조용히 퇴화한다.
     */
    /** 댓글번호는 없을 수 있다 — 그 칸은 NULL 을 받는다. */
    #[DataProvider('connectionProvider')]
    public function testInboxAcceptsANotificationWithoutACommentId(array $config): void
    {
        $this->boot($config);
        $channel = new InboxChannel(fn (): NotificationService => $this->notificationService());

        $channel->send('comment_new', Recipient::forUser(['id' => '7', 'display_name' => '홍길동']),
            ['글제목' => '첫 글', '작성자' => '김철수',
                '_kind' => NotificationService::KIND_COMMENT, '_post_id' => '3']);

        $row = $this->db->selectOne('SELECT * FROM ' . $this->db->table('notifications'));
        self::assertNull($row['comment_id']);
    }

    #[DataProvider('connectionProvider')]
    public function testInboxRecordsTheKindItIsGiven(array $config): void
    {
        $this->boot($config);
        $channel = new InboxChannel(fn (): NotificationService => $this->notificationService());

        $channel->send('comment_new', Recipient::forUser(['id' => '7', 'display_name' => '홍길동']),
            ['사이트명' => '우리 커뮤니티', '이름' => '홍길동', '글제목' => '첫 글', '작성자' => '김철수',
                '링크' => 'https://example.com/p/3', '_kind' => NotificationService::KIND_REPLY,
                '_post_id' => '3', '_comment_id' => '9']);
        $channel->send('comment_new', Recipient::forUser(['id' => '8', 'display_name' => '이영희']),
            ['사이트명' => '우리 커뮤니티', '이름' => '이영희', '글제목' => '첫 글', '작성자' => '김철수',
                '링크' => 'https://example.com/p/3', '_kind' => NotificationService::KIND_COMMENT,
                '_post_id' => '3', '_comment_id' => '9']);

        $rows = $this->db->select('SELECT * FROM ' . $this->db->table('notifications') . ' ORDER BY id');
        self::assertCount(2, $rows);
        self::assertSame(NotificationService::KIND_REPLY, $rows[0]['kind']);
        self::assertSame('7', (string) $rows[0]['user_id']);
        self::assertSame(3, (int) $rows[0]['post_id']);
        self::assertSame(9, (int) $rows[0]['comment_id']);
        self::assertSame('김철수', $rows[0]['actor_name']);
        self::assertSame('첫 글', $rows[0]['subject']);
        self::assertSame(NotificationService::KIND_COMMENT, $rows[1]['kind']);
    }

    /**
     * 알림함에 필요한 값(종류·글번호)은 카탈로그 변수가 아니라 문맥이라 서명이 실어
     * 나르지 않는다. 그래서 빠질 수 있고, 빠지면 **시끄럽게** 거절한다 — 종류를 마음대로
     * 하나 골라 적으면 회원이 보는 문구가 틀리고, 글번호 없이 적으면 열리지 않는 알림이
     * 쌓인다. 건너뛰기(available() 이 false)와 달리 이것은 부르는 쪽의 결함이다.
     */
    #[DataProvider('connectionProvider')]
    public function testInboxRefusesIncompleteContextInsteadOfGuessing(array $config): void
    {
        $this->boot($config);
        $channel = new InboxChannel(fn (): NotificationService => $this->notificationService());
        $to = Recipient::forUser(['id' => '7', 'display_name' => '홍길동']);
        $full = ['글제목' => '첫 글', '작성자' => '김철수',
            '_kind' => NotificationService::KIND_COMMENT, '_post_id' => '3', '_comment_id' => '9'];

        foreach ([
            '종류 없음' => array_diff_key($full, ['_kind' => null]),
            '모르는 종류' => ['_kind' => 'shout'] + $full,
            '글번호 없음' => array_diff_key($full, ['_post_id' => null]),
            '글번호가 숫자가 아님' => ['_post_id' => '3번'] + $full,
            '댓글번호가 숫자가 아님' => ['_comment_id' => '9번'] + $full,
        ] as $why => $vars) {
            self::assertSame(['inbox'], array_keys($this->refusal(
                fn () => $channel->send('comment_new', $to, $vars))), $why);
        }

        self::assertSame([], $this->db->select('SELECT id FROM ' . $this->db->table('notifications')));
    }

    /** 댓글이 아닌 알림이나 손님에게는 send() 도 거절한다 — available() 과 같은 답이다. */
    #[DataProvider('connectionProvider')]
    public function testInboxRefusesToSendWhatAvailableWouldHaveSkipped(array $config): void
    {
        $this->boot($config);
        $channel = new InboxChannel(fn (): NotificationService => $this->notificationService());
        $vars = ['글제목' => '첫 글', '작성자' => '김철수',
            '_kind' => NotificationService::KIND_COMMENT, '_post_id' => '3', '_comment_id' => '9'];

        self::assertSame(['inbox'], array_keys($this->refusal(fn () => $channel->send('comment_new',
            Recipient::forEmail('a@example.com', '손님'), $vars))));
        self::assertSame(['inbox'], array_keys($this->refusal(fn () => $channel->send('password_reset',
            Recipient::forUser(['id' => '7', 'display_name' => '홍길동']), $vars))));
        self::assertSame([], $this->db->select('SELECT id FROM ' . $this->db->table('notifications')));
    }
}
