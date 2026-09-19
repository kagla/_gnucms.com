<?php

declare(strict_types=1);

namespace GnuCms\Tests\Service;

use GnuCms\Aligo\AligoService;
use GnuCms\App;
use GnuCms\Mail\MailerInterface;
use GnuCms\Mail\SecretCipher;
use GnuCms\Notify\ChannelInterface;
use GnuCms\Notify\Notifier;
use GnuCms\Notify\Recipient;
use GnuCms\Service\NotificationService;
use GnuCms\Tests\Support\CollectingMailer;
use GnuCms\Tests\Support\FakeAligoTransport;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 댓글 알림이 채널로 나가는 길.
 *
 * 이 알림은 코어에서 유일하게 **받을 사람이 여럿일 수 있는** 알림이다. Notifier 는
 * 수신자 하나만 받으므로 사람마다 한 번씩 부르고, 그래서 여기서 보는 것은 대부분
 * "둘이 서로를 어떻게 건드리지 않는가"다 — 종류가 섞이지 않는지, 한 사람의 실패가
 * 다른 사람을 삼키지 않는지, 한 사람이 두 자격을 겸해도 한 번만 가는지.
 *
 * 채널은 흉내 내지 않고 **진짜 채널**로 확인한다. 알림함만 보면 "이벤트로 나갔는지"와
 * "예전처럼 표에 적었는지"를 구별할 수 없기 때문이다 — 메일·문자·알림톡은 오직 발송기를
 * 거쳐야만 나간다. 특히 전화 채널 둘은 **돈이 들고 되돌릴 수 없는** 발송이라, 번호가
 * 수신자까지 제대로 실려 가는지를 여기서 못박는다(5단계에서 `findByEmail()` 에 phone 이
 * 빠져 문자 재설정이 영영 조용히 아무것도 안 하던 결함이 바로 한 칸 옆이다).
 *
 * **번호가 서로 달라야 한다.** 글번호·댓글번호·회원번호를 우연히 같은 값으로 만들어 두면
 * 그 둘을 맞바꿔도 어떤 단언도 구별하지 못한다(글 1번·댓글 1번이 정확히 그랬다). 그래서
 * 모든 시험이 spaceOutIds() 로 번호를 벌려 놓고 시작하고, 이름도 마찬가지로 받는 사람
 * (이름)과 댓글 쓴 사람(작성자)을 절대 같은 문자열로 두지 않는다.
 */
final class CommentNotificationRoutingTest extends WebTestCase
{
    private const SITE = '우리 커뮤니티';
    private const URL = 'https://example.test';
    /** 글쓴이·부모 댓글 작성자·댓글 쓴 사람은 서로 다른 이름이어야 구별이 된다. */
    private const WRITER = '글쓴이';
    private const REPLIED = '댓글쓴이';
    private const ACTOR = '지나가던손님';

    /**
     * 글쓴이에게 가는 알림 한 통에 글·댓글·사이트가 모두 실려 나간다. 본문을 통째로
     * 비교하는 이유는 값이 "있는지"가 아니라 **제자리에 있는지**를 보기 위해서다 —
     * 이름과 작성자를 맞바꿔도 두 이름이 본문 어딘가에 있기는 하다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheCommentEventCarriesThePostAndSiteVariables(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $mailer = $this->collectMail($app);
        $this->saveSiteSettings($app, ['site_name' => self::SITE]);
        $this->turnOn($app, ['mail']);
        $this->spaceOutIds($app);

        $writer = $this->seedMember($app, 'writer@example.com', self::WRITER);
        $postId = $this->seedPost($app, $writer, '알림이 붙을 글');
        $commentId = $this->seedComment($app, $postId, null, self::ACTOR, null);

        $app->notificationService()->notifyComment($postId, $commentId);

        self::assertCount(1, $mailer->messages);
        self::assertSame('writer@example.com', $mailer->messages[0]['to']);
        self::assertSame('[' . self::SITE . '] 새 댓글이 달렸습니다', $mailer->messages[0]['subject']);
        self::assertSame(
            self::WRITER . '님, ' . self::ACTOR . "님이 「알림이 붙을 글」 글에 댓글을 남겼습니다.\n\n"
            . self::URL . '/posts/' . $postId . '#comment-' . $commentId,
            $mailer->messages[0]['body']
        );
    }

    /**
     * 받을 사람이 둘이면 알림도 둘이다. 그리고 **둘의 종류가 다르다** — 부모 댓글
     * 작성자에게는 "내 댓글에 답글", 글쓴이에게는 "내 글에 댓글". 이벤트는 comment_new
     * 하나뿐이라 이 구분은 채널 문맥(_kind)으로만 건너가고, 한쪽으로 뭉개면 회원의
     * 알림함 문구가 통째로 틀린다. 글번호·댓글번호도 그 문맥으로 건너가므로 함께 본다.
     */
    #[DataProvider('connectionProvider')]
    public function testEachTargetGetsItsOwnNotificationWithItsOwnKind(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $mailer = $this->collectMail($app);
        $this->turnOn($app, ['mail', 'inbox']);
        $this->spaceOutIds($app);

        [$writer, $replied, $postId, , $replyId] = $this->seedReplyThread($app);

        $app->notificationService()->notifyComment($postId, $replyId);

        self::assertSame([
            [$replied, 'reply', $postId, $replyId],
            [$writer, 'comment', $postId, $replyId],
        ], $this->inbox($app), '부모 댓글 작성자는 답글로, 글쓴이는 댓글로 읽는다');
        self::assertSame(['replied@example.com', 'writer@example.com'],
            array_column($mailer->messages, 'to'), '사람마다 한 통씩 나간다');
    }

    /** 설정을 건드리지 않은 사이트의 기본값은 알림함 하나다. 예전과 똑같이 한 줄이 남는다. */
    #[DataProvider('connectionProvider')]
    public function testTheInboxStillGetsTheNotification(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $mailer = $this->collectMail($app);
        $this->spaceOutIds($app);

        $writer = $this->seedMember($app, 'writer@example.com', self::WRITER);
        $postId = $this->seedPost($app, $writer, '알림이 붙을 글');
        $commentId = $this->seedComment($app, $postId, null, self::ACTOR, null);

        $app->notificationService()->notifyComment($postId, $commentId);

        self::assertSame([[$writer, 'comment', $postId, $commentId]], $this->inbox($app));
        self::assertSame([], $mailer->messages, '켜지 않은 채널로는 나가지 않는다');
    }

    /**
     * 사람마다 **자기 번호로** 문자 한 통. 돈이 들고 되돌릴 수 없는 발송이라, 번호가
     * 수신자까지 실려 가는지(Recipient::forUser 의 phone), 본문이 사람마다 자기 이름으로
     * 채워지는지, 관리자 발송 내역에 사람마다 한 건씩 남는지를 모두 본다.
     */
    #[DataProvider('connectionProvider')]
    public function testEveryTargetGetsATextMessageAtTheirOwnNumber(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $transport = $this->bootAligo($app);
        $app->aligo()->settings->setEnabled('sms', true);
        $this->turnOn($app, ['sms'], ['sms_body' => '#{이름}님 「#{글제목}」 #{링크}']);
        $this->spaceOutIds($app);

        [$writer, $replied, $postId, , $replyId] = $this->seedReplyThread($app);
        $app->users()->updatePhone((int) $writer, '01011112222');
        $app->users()->updatePhone((int) $replied, '01033334444');
        $transport->queue(200, $this->smsOk());
        $transport->queue(200, $this->smsOk());

        $app->notificationService()->notifyComment($postId, $replyId);

        self::assertSame(['sms', 'sms'], array_column($this->jobs($app), 'channel'),
            '사람마다 한 건씩 남는다 — 한 건에 둘을 묶으면 본문이 한쪽 이름으로 고정된다');
        $link = self::URL . '/posts/' . $postId . '#comment-' . $replyId;
        self::assertSame([
            ['01033334444', self::REPLIED . '님 「두 사람이 받을 글」 ' . $link, $replied],
            ['01011112222', self::WRITER . '님 「두 사람이 받을 글」 ' . $link, $writer],
        ], $this->recipients($app), '번호·본문·회원번호가 사람마다 제 것이어야 한다');
        self::assertSame(['01033334444', '01011112222'], [
            $transport->requests[0]['fields']['rec_1'],
            $transport->requests[1]['fields']['rec_1'],
        ], '알리고로 실제로 나간 번호');
    }

    /** 알림톡도 같은 길이다 — 승인 템플릿의 변수에 코어 값을 이어 사람마다 한 건씩 나간다. */
    #[DataProvider('connectionProvider')]
    public function testEveryTargetGetsAnAlimtalkAtTheirOwnNumber(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $transport = $this->bootAligo($app);
        $app->aligo()->settings->setEnabled('at', true);
        $app->db()->insert('alimtalk_templates', ['tpl_code' => 'T1', 'senderkey' => 'SK1',
            'name' => '새 댓글', 'content' => '#{고객명}님 #{제목} 에 새 댓글이 있습니다',
            'status' => 'A', 'insp_status' => 'APR', 'enabled' => 1,
            'fetched_at' => '2026-09-17 10:00:00']);
        $this->turnOn($app, ['alimtalk'],
            ['tpl_code' => 'T1', 'var_map' => ['고객명' => '이름', '제목' => '글제목']]);
        $this->spaceOutIds($app);

        [$writer, $replied, $postId, , $replyId] = $this->seedReplyThread($app);
        $app->users()->updatePhone((int) $writer, '01011112222');
        $app->users()->updatePhone((int) $replied, '01033334444');
        $transport->queue(200, $this->alimtalkOk());
        $transport->queue(200, $this->alimtalkOk());

        $app->notificationService()->notifyComment($postId, $replyId);

        self::assertSame(['at', 'at'], array_column($this->jobs($app), 'channel'));
        self::assertSame([
            ['01033334444', self::REPLIED . '님 두 사람이 받을 글 에 새 댓글이 있습니다', $replied],
            ['01011112222', self::WRITER . '님 두 사람이 받을 글 에 새 댓글이 있습니다', $writer],
        ], $this->recipients($app));
    }

    /**
     * 한 사람에게 실패해도 다른 사람은 받는다. 채널이 하나뿐인 상태에서 그 하나가
     * 터지면 Notifier 는 예외를 올리는데(아무 데도 못 갔다는 뜻이다), 그것이 그대로
     * 올라가면 뒤 사람은 부르지도 못하고 댓글 등록 화면이 오류가 된다 — 댓글은 이미
     * 저장된 뒤라 사람은 같은 댓글을 한 번 더 쓴다. 실패는 조용히 사라지지도 않는다.
     */
    #[DataProvider('connectionProvider')]
    public function testATargetThatFailsDoesNotSilenceTheOther(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        // 한 주소로만 실패하는 메일 전송기. CollectingMailer 는 final 이라 여기서 새로 만든다.
        $mailer = new class implements MailerInterface {
            /** @var list<array{to:string,subject:string,body:string}> */
            public array $messages = [];

            public function send(string $to, string $subject, string $body): void
            {
                if ($to === 'replied@example.com') {
                    throw new \RuntimeException('메일 서버가 거절했습니다');
                }
                $this->messages[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
            }
        };
        $app->setMailer($mailer);
        $this->turnOn($app, ['mail']);
        $this->spaceOutIds($app);

        [, , $postId, , $replyId] = $this->seedReplyThread($app);
        $logged = [];

        // 먼저 불리는 쪽(부모 댓글 작성자)이 실패한다.
        $this->serviceLogging($app, $logged)->notifyComment($postId, $replyId);

        self::assertSame(['writer@example.com'], array_column($mailer->messages, 'to'),
            '앞사람이 실패해도 뒷사람에게는 간다');
        self::assertCount(1, $logged);
        self::assertStringContainsString('보내지 못했습니다', $logged[0]);
    }

    /**
     * 받을 사람이 있었는데 한 사람도 부르지 못하면 그 사실을 적는다. 조용히 끝내면
     * 운영자에게는 "알림이 안 온다"는 사실만 남고 이유가 없다. 반대로 받을 사람이
     * 처음부터 없었던 평범한 경우(비회원 글에 비회원 댓글)에는 적지 않는다 — 그 줄이
     * 늘 찍히면 진짜 이유를 적은 줄을 덮는다.
     */
    #[DataProvider('connectionProvider')]
    public function testItSaysSoWhenEveryTargetWasDropped(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $this->collectMail($app);
        $this->turnOn($app, ['mail', 'inbox']);
        $this->spaceOutIds($app);

        $writer = $this->seedMember($app, 'writer@example.com', self::WRITER);
        $postId = $this->seedPost($app, $writer, '차단된 회원의 글');
        $commentId = $this->seedComment($app, $postId, null, self::ACTOR, null);
        $app->users()->setStatus((int) $writer, 'blocked');
        $logged = [];
        $service = $this->serviceLogging($app, $logged);

        $service->notifyComment($postId, $commentId);

        self::assertCount(1, $logged);
        self::assertStringContainsString('활성 회원이 아니어서', $logged[0]);

        // 비회원이 쓴 글에 비회원이 단 댓글 — 받을 사람이 처음부터 없다.
        $guestPostId = $this->seedPost($app, null, '손님이 쓴 글');
        $service->notifyComment($guestPostId, $this->seedComment($app, $guestPostId, null, self::ACTOR, null));

        self::assertCount(1, $logged, '받을 사람이 없던 평범한 경우는 적지 않는다');
    }

    /**
     * 발송기는 App 이 **지금** 들고 있는 것이어야 한다. 이 서비스가 만들어질 때의 사본을
     * 쥐고 있으면, setMailer()·setAligo() 로 발송기를 갈아 끼운 뒤에도 여기만 옛 사본을
     * 쓴다 — 시험이 가짜로 바꿔 둔 메일러·알리고 대신 진짜가 쓰이는 길이다.
     */
    #[DataProvider('connectionProvider')]
    public function testItUsesTheNotifierTheAppHasNow(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $this->collectMail($app);
        $this->turnOn($app, ['mail']);
        $this->spaceOutIds($app);

        $writer = $this->seedMember($app, 'writer@example.com', self::WRITER);
        $postId = $this->seedPost($app, $writer, '알림이 붙을 글');
        $commentId = $this->seedComment($app, $postId, null, self::ACTOR, null);
        // 서비스를 먼저 만들어 두고, 그 뒤에 메일러를 갈아 끼운다.
        $service = $app->notificationService();
        $later = $this->collectMail($app);

        $service->notifyComment($postId, $commentId);

        self::assertCount(1, $later->messages, '갈아 끼운 메일러로 나가야 한다');
    }

    /**
     * 차단·탈퇴 회원은 없는 회원과 같게 다룬다 — 이 저장소가 다른 곳에서도 지키는
     * 규칙이다(CommentService::listByAuthor, UserRepository::searchActive). 로그인을
     * 막아 둔 사람의 알림함은 읽을 사람이 없고, 내보낸 사람에게 메일·문자를 계속
     * 보내면 사이트가 그 사람에게 말을 거는 꼴이 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testBlockedAndWithdrawnMembersAreLeftOut(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $mailer = $this->collectMail($app);
        $this->turnOn($app, ['mail', 'inbox']);
        $this->spaceOutIds($app);

        [$writer, $replied, $postId, , $replyId] = $this->seedReplyThread($app);
        $app->users()->setStatus((int) $writer, 'blocked');
        $app->users()->withdraw((int) $replied, null);

        $app->notificationService()->notifyComment($postId, $replyId);

        self::assertSame([], $this->inbox($app));
        self::assertSame([], $mailer->messages);
    }

    /**
     * 관리자가 이 알림의 채널을 하나도 켜 두지 않았으면 아무 일도 일어나지 않는다.
     * 알림함도 채널 가운데 하나이므로 예외가 아니다 — 켜고 끄는 화면이 알림함에만
     * 닿지 않으면 관리자가 끈 알림이 계속 쌓인다.
     */
    #[DataProvider('connectionProvider')]
    public function testNothingGoesOutWhenEveryChannelIsOff(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $mailer = $this->collectMail($app);
        $this->turnOn($app, []);
        $this->spaceOutIds($app);

        $writer = $this->seedMember($app, 'writer@example.com', self::WRITER);
        $postId = $this->seedPost($app, $writer, '알림이 붙을 글');
        $commentId = $this->seedComment($app, $postId, null, self::ACTOR, null);

        $app->notificationService()->notifyComment($postId, $commentId);

        self::assertSame([], $this->inbox($app));
        self::assertSame([], $mailer->messages);
    }

    /** 글쓴이가 부모 댓글도 쓴 사람이면 두 자격이 겹친다. 그래도 한 번만 간다. */
    #[DataProvider('connectionProvider')]
    public function testOnePersonWearingBothHatsIsNotifiedOnce(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $mailer = $this->collectMail($app);
        $this->turnOn($app, ['mail', 'inbox']);
        $this->spaceOutIds($app);

        $writer = $this->seedMember($app, 'writer@example.com', self::WRITER);
        $postId = $this->seedPost($app, $writer, '내 글에 내가 단 댓글');
        $parentId = $this->seedComment($app, $postId, $writer, self::WRITER, null);
        $replyId = $this->seedComment($app, $postId, null, self::ACTOR, $parentId);

        $app->notificationService()->notifyComment($postId, $replyId);

        self::assertSame([[$writer, 'reply', $postId, $replyId]], $this->inbox($app));
        self::assertCount(1, $mailer->messages);
    }

    /** 댓글을 쓴 사람에게는 자기 댓글로 알리지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testTheCommentWriterIsNeverNotified(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $mailer = $this->collectMail($app);
        $this->turnOn($app, ['mail', 'inbox']);
        $this->spaceOutIds($app);

        $writer = $this->seedMember($app, 'writer@example.com', self::WRITER);
        $postId = $this->seedPost($app, $writer, '내 글에 내가 단 댓글');
        $commentId = $this->seedComment($app, $postId, $writer, self::WRITER, null);

        $app->notificationService()->notifyComment($postId, $commentId);

        self::assertSame([], $this->inbox($app));
        self::assertSame([], $mailer->messages);
    }

    /**
     * **한 댓글의 팬아웃 전체가 하나의 시간 상한을 나눠 쓴다.** 받을 사람이 둘이면
     * notify() 가 두 번 불리는데, 그 둘이 각자 상한을 갖는다면 사람 수만큼 곱해져
     * 댓글 쓴 사람이 기다리는 시간에는 여전히 끝이 없다. 상한을 쥔 것은 요청당 하나인
     * 발송기이므로, 이 자리가 발송기를 사람마다 새로 만들기 시작하면 상한이 뜻을 잃는다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheWholeFanOutSharesOneSendingTimeLimit(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $this->turnOn($app, ['mail']);
        $this->spaceOutIds($app);
        $now = 0.0;
        $sent = [];
        // 부를 때마다 10초를 쓰는 채널. 느린 알리고·SMTP 를 대신한다.
        $record = function (string $to) use (&$now, &$sent): void {
            $now += 10.0;
            $sent[] = $to;
        };
        $channel = new class ($record) implements ChannelInterface {
            public function __construct(private \Closure $record)
            {
            }

            public function key(): string
            {
                return 'mail';
            }

            public function available(string $event, Recipient $to): bool
            {
                return true;
            }

            public function send(string $event, Recipient $to, array $vars): void
            {
                ($this->record)((string) $to->email);
            }
        };
        (new \ReflectionProperty(App::class, 'notifier'))->setValue($app, new Notifier(
            $app->notifySettings(), [$channel], static function (): void {
            }, function () use (&$now): float {
                return $now;
            }));
        [, , $postId, , $replyId] = $this->seedReplyThread($app);

        $app->notificationService()->notifyComment($postId, $replyId);

        self::assertCount(1, $sent, '한 사람에게 10초를 쓴 뒤 두 번째 사람은 시작하지 않는다');
    }

    /**
     * **엔진이 채널을 도로 끄면 댓글 알림은 그냥 멈춘다.** 승인 템플릿이 카카오 승인을
     * 잃으면 channelsFor() 는 알림톡을 빼고, 알림톡 하나만 켜 둔 사이트에서는 켤 채널이
     * 하나도 남지 않는다. 관리자는 아무것도 건드리지 않았고, 이 호출부는 돌려받은 값을
     * 보지 않는다(설계대로다) — 그래서 운영자 로그 한 줄이 유일한 신호다. 그 줄이
     * 없으면 "댓글 알림이 안 온다"는 사실만 남고 이유가 아무 데도 없다.
     */
    #[DataProvider('connectionProvider')]
    public function testItSaysSoWhenTheEngineRevokedTheOnlyChannel(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $this->bootAligo($app);
        $app->aligo()->settings->setEnabled('at', true);
        $app->db()->insert('alimtalk_templates', ['tpl_code' => 'T1', 'senderkey' => 'SK1',
            'name' => '새 댓글', 'content' => '#{고객명}님 #{제목} 에 새 댓글이 있습니다',
            'status' => 'A', 'insp_status' => 'APR', 'enabled' => 1,
            'fetched_at' => '2026-09-17 10:00:00']);
        $this->turnOn($app, ['alimtalk'],
            ['tpl_code' => 'T1', 'var_map' => ['고객명' => '이름', '제목' => '글제목']]);
        $this->spaceOutIds($app);
        $writer = $this->seedMember($app, 'writer@example.com', self::WRITER);
        $app->users()->updatePhone((int) $writer, '01011112222');
        $postId = $this->seedPost($app, $writer, '알림이 멈출 글');
        $commentId = $this->seedComment($app, $postId, null, self::ACTOR, null);
        // 카카오 승인이 풀려 Templates::fetch() 가 이 템플릿을 껐다.
        $app->db()->update('alimtalk_templates', ['enabled' => 0], 'tpl_code = :code', ['code' => 'T1']);

        $logged = $this->captureErrorLog(function () use ($app, $postId, $commentId): void {
            $app->notificationService()->notifyComment($postId, $commentId);
        });

        self::assertSame([], $this->jobs($app), '보낼 수 없는 채널로 발송을 만들지는 않는다');
        self::assertSame([], $this->inbox($app));
        self::assertStringContainsString('더는 쓸 수 없는 상태', $logged);
        self::assertStringContainsString('comment_new', $logged);
    }

    /** 이 알림으로 켤 채널. 관리자 화면이 저장하는 그 길로 저장한다. */
    private function turnOn(App $app, array $channels, array $extra = []): void
    {
        $input = ['mail' => '0', 'alimtalk' => '0', 'sms' => '0', 'inbox' => '0'];
        foreach ($channels as $channel) {
            $input[$channel] = '1';
        }
        $app->notifySettings()->save('comment_new', $input + $extra);
    }

    /** 가짜 전송기를 문 알리고 한 벌. 계정은 저장돼 있고 채널 스위치는 아직 꺼져 있다. */
    private function bootAligo(App $app): FakeAligoTransport
    {
        $transport = new FakeAligoTransport();
        // setAligo() 는 알림 설정과 발송기를 함께 끊으므로 채널 설정보다 먼저 와야 한다.
        $app->setAligo(new AligoService($app->db(), $transport, new SecretCipher('s')));
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);

        return $transport;
    }

    private function smsOk(): string
    {
        return (string) json_encode(
            ['result_code' => 1, 'msg_id' => 'M1', 'success_cnt' => 1, 'error_cnt' => 0]);
    }

    private function alimtalkOk(): string
    {
        return (string) json_encode(['code' => 0, 'info' => ['mid' => 'A1', 'scnt' => 1, 'fcnt' => 0]]);
    }

    private function collectMail(App $app): CollectingMailer
    {
        $mailer = new CollectingMailer();
        $app->setMailer($mailer);

        return $mailer;
    }

    /**
     * App 이 만드는 것과 **같은 조립**에 로그 받는 곳만 바꿔 끼운 서비스. 로그는 이
     * 서비스가 조용히 끝내는 두 경우(사람별 실패, 전원 제외)에 운영자가 받는 유일한
     * 진단이라, 그것이 실제로 적히는지 보려면 받아 볼 자리가 있어야 한다.
     */
    private function serviceLogging(App $app, array &$lines): NotificationService
    {
        return new NotificationService($app->notifications(), $app->posts(), $app->comments(),
            $app->users(), $app->cmsService(), self::URL, fn (): Notifier => $app->notifier(),
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            });
    }

    /**
     * 글쓴이·부모 댓글 작성자·손님의 답글까지 한 벌.
     *
     * @return array{0:string,1:string,2:int,3:int,4:int} [글쓴이, 부모 댓글 작성자, 글번호, 부모 댓글번호, 답글번호]
     */
    private function seedReplyThread(App $app): array
    {
        $writer = $this->seedMember($app, 'writer@example.com', self::WRITER);
        $replied = $this->seedMember($app, 'replied@example.com', self::REPLIED);
        $postId = $this->seedPost($app, $writer, '두 사람이 받을 글');
        $parentId = $this->seedComment($app, $postId, $replied, self::REPLIED, null);
        $replyId = $this->seedComment($app, $postId, null, self::ACTOR, $parentId);

        return [$writer, $replied, $postId, $parentId, $replyId];
    }

    /**
     * 글번호·댓글번호·회원번호가 서로 다른 값이 되게 미끼 행을 먼저 만든다.
     *
     * 이것이 없으면 첫 글도 1번, 첫 댓글도 1번, 첫 회원도 1번이라 셋을 맞바꿔도 어떤
     * 단언도 구별하지 못한다 — 글번호와 댓글번호를 맞바꾸는 변이가 스위트 전체를 통과한
     * 것이 정확히 그 때문이었다. 미끼는 다른 글에 달아 두므로 시험 대상의 알림에는
     * 끼어들지 않는다.
     */
    private function spaceOutIds(App $app): void
    {
        $postId = 0;
        for ($i = 0; $i < 3; $i++) {
            $postId = $this->seedPost($app, null, '자리를 벌리는 글 ' . $i);
        }
        for ($i = 0; $i < 7; $i++) {
            $this->seedComment($app, $postId, null, '자리를 벌리는 손님', null);
        }
    }

    /** @return list<array{0:string,1:string,2:int,3:?int}> 쌓인 순서대로 [회원, 종류, 글번호, 댓글번호] */
    private function inbox(App $app): array
    {
        $rows = $app->db()->select('SELECT user_id, kind, post_id, comment_id FROM '
            . $app->db()->q('notifications') . ' ORDER BY id');

        return array_map(static fn (array $row): array => [
            (string) $row['user_id'], (string) $row['kind'],
            (int) $row['post_id'], $row['comment_id'] === null ? null : (int) $row['comment_id'],
        ], $rows);
    }

    private function jobs(App $app): array
    {
        return $app->db()->select('SELECT * FROM ' . $app->db()->q('message_jobs') . ' ORDER BY id');
    }

    /** @return list<array{0:string,1:string,2:?string}> 발송 순서대로 [번호, 본문, 회원번호] */
    private function recipients(App $app): array
    {
        $rows = $app->db()->select('SELECT phone, body, user_id FROM '
            . $app->db()->q('message_recipients') . ' ORDER BY id');

        return array_map(static fn (array $row): array => [
            (string) $row['phone'], (string) $row['body'],
            $row['user_id'] === null ? null : (string) $row['user_id'],
        ], $rows);
    }

    private function seedMember(App $app, string $email, string $name): string
    {
        return (string) $app->users()->create($email,
            password_hash('member-password-1', PASSWORD_DEFAULT), $name, false);
    }

    /** $authorId 가 null 이면 손님이 쓴 글이다. */
    private function seedPost(App $app, ?string $authorId, string $title): int
    {
        return $app->posts()->create([
            'board_id'    => $this->boardId($app),
            'title'       => $title,
            'content'     => '본문입니다.',
            'author_id'   => $authorId,
            'author_name' => $authorId === null ? '손님' : self::WRITER,
        ]);
    }

    /** $authorId 가 null 이면 손님이 쓴 댓글이다. */
    private function seedComment(App $app, int $postId, ?string $authorId, string $authorName, ?int $parentId): int
    {
        return $app->comments()->create([
            'board_id'    => $this->boardId($app),
            'post_id'     => $postId,
            'parent_id'   => $parentId,
            'content'     => '댓글입니다.',
            'author_id'   => $authorId,
            'author_name' => $authorName,
        ]);
    }

    private function boardId(App $app): int
    {
        $board = $app->boards()->findByKey('free');
        if ($board === null) {
            $board = $app->boardService()->create($this->adminAcl(),
                ['board_key' => 'free', 'name' => '자유게시판', 'perm_comment' => 'guest']);
        }

        return (int) $board['id'];
    }
}
