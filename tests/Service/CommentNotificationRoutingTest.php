<?php

declare(strict_types=1);

namespace GnuCms\Tests\Service;

use GnuCms\App;
use GnuCms\Mail\MailerInterface;
use GnuCms\Tests\Support\CollectingMailer;
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
 * 채널은 흉내 내지 않고 진짜 메일 채널로 확인한다. 알림함만 보면 "이벤트로 나갔는지"와
 * "예전처럼 표에 적었는지"를 구별할 수 없기 때문이다 — 메일은 오직 발송기를 거쳐야만
 * 나간다.
 */
final class CommentNotificationRoutingTest extends WebTestCase
{
    private const SITE = '우리 커뮤니티';
    private const URL = 'https://example.test';

    /**
     * 글쓴이에게 가는 알림 한 통에 글·댓글·사이트가 모두 실려 나간다. 이 다섯 값은
     * 카탈로그(Events::variables)가 선언한 것이고, 관리자가 문자 본문이나 알림톡 변수에
     * 이어 붙일 수 있는 값이라 하나라도 비면 그 자리에 빈칸이 나간다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheCommentEventCarriesThePostAndSiteVariables(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $mailer = $this->collectMail($app);
        $this->saveSiteSettings($app, ['site_name' => self::SITE]);
        $this->turnOn($app, ['mail']);

        $writer = $this->seedMember($app, 'writer@example.com', '글쓴이');
        $postId = $this->seedPost($app, $writer, '알림이 붙을 글');
        $commentId = $this->seedComment($app, $postId, null, '손님', null);

        $app->notificationService()->notifyComment($postId, $commentId);

        self::assertCount(1, $mailer->messages);
        self::assertSame('writer@example.com', $mailer->messages[0]['to']);
        self::assertSame('[' . self::SITE . '] 새 댓글이 달렸습니다', $mailer->messages[0]['subject']);
        $body = $mailer->messages[0]['body'];
        self::assertStringContainsString('글쓴이님', $body, '이름');
        self::assertStringContainsString('손님님', $body, '작성자');
        self::assertStringContainsString('알림이 붙을 글', $body, '글제목');
        self::assertStringContainsString(self::URL . '/posts/' . $postId . '#comment-' . $commentId, $body, '링크');
    }

    /**
     * 받을 사람이 둘이면 알림도 둘이다. 그리고 **둘의 종류가 다르다** — 부모 댓글
     * 작성자에게는 "내 댓글에 답글", 글쓴이에게는 "내 글에 댓글". 이벤트는 comment_new
     * 하나뿐이라 이 구분은 채널 문맥(_kind)으로만 건너가고, 한쪽으로 뭉개면 회원의
     * 알림함 문구가 통째로 틀린다.
     */
    #[DataProvider('connectionProvider')]
    public function testEachTargetGetsItsOwnNotificationWithItsOwnKind(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $mailer = $this->collectMail($app);
        $this->turnOn($app, ['mail', 'inbox']);

        $writer = $this->seedMember($app, 'writer@example.com', '글쓴이');
        $replied = $this->seedMember($app, 'replied@example.com', '댓글쓴이');
        $postId = $this->seedPost($app, $writer, '두 사람이 받을 글');
        $parentId = $this->seedComment($app, $postId, $replied, '댓글쓴이', null);
        $replyId = $this->seedComment($app, $postId, null, '손님', $parentId);

        $app->notificationService()->notifyComment($postId, $replyId);

        self::assertSame([
            [$replied, 'reply'],
            [$writer, 'comment'],
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

        $writer = $this->seedMember($app, 'writer@example.com', '글쓴이');
        $postId = $this->seedPost($app, $writer, '알림이 붙을 글');
        $commentId = $this->seedComment($app, $postId, null, '손님', null);

        $app->notificationService()->notifyComment($postId, $commentId);

        self::assertSame([[$writer, 'comment']], $this->inbox($app));
        self::assertSame([], $mailer->messages, '켜지 않은 채널로는 나가지 않는다');
    }

    /**
     * 한 사람에게 실패해도 다른 사람은 받는다. 채널이 하나뿐인 상태에서 그 하나가
     * 터지면 Notifier 는 예외를 올리는데(아무 데도 못 갔다는 뜻이다), 그것이 그대로
     * 올라가면 뒤 사람은 부르지도 못하고 댓글 등록 화면이 오류가 된다 — 댓글은 이미
     * 저장된 뒤라 사람은 같은 댓글을 한 번 더 쓴다.
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

        $writer = $this->seedMember($app, 'writer@example.com', '글쓴이');
        $replied = $this->seedMember($app, 'replied@example.com', '댓글쓴이');
        $postId = $this->seedPost($app, $writer, '두 사람이 받을 글');
        $parentId = $this->seedComment($app, $postId, $replied, '댓글쓴이', null);
        $replyId = $this->seedComment($app, $postId, null, '손님', $parentId);

        // 먼저 불리는 쪽(부모 댓글 작성자)이 실패한다.
        $app->notificationService()->notifyComment($postId, $replyId);

        self::assertSame(['writer@example.com'], array_column($mailer->messages, 'to'),
            '앞사람이 실패해도 뒷사람에게는 간다');
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

        $writer = $this->seedMember($app, 'writer@example.com', '글쓴이');
        $postId = $this->seedPost($app, $writer, '알림이 붙을 글');
        $commentId = $this->seedComment($app, $postId, null, '손님', null);
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

        $writer = $this->seedMember($app, 'writer@example.com', '글쓴이');
        $replied = $this->seedMember($app, 'replied@example.com', '댓글쓴이');
        $postId = $this->seedPost($app, $writer, '두 사람이 받을 글');
        $parentId = $this->seedComment($app, $postId, $replied, '댓글쓴이', null);
        $replyId = $this->seedComment($app, $postId, null, '손님', $parentId);
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

        $writer = $this->seedMember($app, 'writer@example.com', '글쓴이');
        $postId = $this->seedPost($app, $writer, '알림이 붙을 글');
        $commentId = $this->seedComment($app, $postId, null, '손님', null);

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

        $writer = $this->seedMember($app, 'writer@example.com', '글쓴이');
        $postId = $this->seedPost($app, $writer, '내 글에 내가 단 댓글');
        $parentId = $this->seedComment($app, $postId, $writer, '글쓴이', null);
        $replyId = $this->seedComment($app, $postId, null, '손님', $parentId);

        $app->notificationService()->notifyComment($postId, $replyId);

        self::assertSame([[$writer, 'reply']], $this->inbox($app));
        self::assertCount(1, $mailer->messages);
    }

    /** 댓글을 쓴 사람에게는 자기 댓글로 알리지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testTheCommentWriterIsNeverNotified(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['app' => ['url' => self::URL]]);
        $mailer = $this->collectMail($app);
        $this->turnOn($app, ['mail', 'inbox']);

        $writer = $this->seedMember($app, 'writer@example.com', '글쓴이');
        $postId = $this->seedPost($app, $writer, '내 글에 내가 단 댓글');
        $commentId = $this->seedComment($app, $postId, $writer, '글쓴이', null);

        $app->notificationService()->notifyComment($postId, $commentId);

        self::assertSame([], $this->inbox($app));
        self::assertSame([], $mailer->messages);
    }

    /** 이 알림으로 켤 채널. 관리자 화면이 저장하는 그 길로 저장한다. */
    private function turnOn(App $app, array $channels): void
    {
        $input = ['mail' => '0', 'alimtalk' => '0', 'sms' => '0', 'inbox' => '0'];
        foreach ($channels as $channel) {
            $input[$channel] = '1';
        }
        $app->notifySettings()->save('comment_new', $input);
    }

    private function collectMail(App $app): CollectingMailer
    {
        $mailer = new CollectingMailer();
        $app->setMailer($mailer);

        return $mailer;
    }

    /** @return list<array{0:string,1:string}> 알림함에 쌓인 순서대로 [회원 번호, 종류] */
    private function inbox(App $app): array
    {
        $rows = $app->db()->select('SELECT user_id, kind FROM ' . $app->db()->q('notifications')
            . ' ORDER BY id');

        return array_map(
            static fn (array $row): array => [(string) $row['user_id'], (string) $row['kind']],
            $rows
        );
    }

    private function seedMember(App $app, string $email, string $name): string
    {
        return (string) $app->users()->create($email,
            password_hash('member-password-1', PASSWORD_DEFAULT), $name, false);
    }

    private function seedPost(App $app, string $authorId, string $title): int
    {
        return $app->posts()->create([
            'board_id'    => $this->boardId($app),
            'title'       => $title,
            'content'     => '본문입니다.',
            'author_id'   => $authorId,
            'author_name' => '글쓴이',
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
