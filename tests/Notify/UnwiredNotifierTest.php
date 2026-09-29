<?php

declare(strict_types=1);

namespace GnuCms\Tests\Notify;

use GnuCms\Notify\Recipient;
use GnuCms\Notify\UnwiredNotifier;
use GnuCms\Tests\Support\CollectingMailer;
use PHPUnit\Framework\TestCase;

/**
 * 발송기를 못 받은 서비스가 무엇을 하고, 무엇을 남기는가.
 *
 * 경고 기록이 프로세스 전역(static)이라 시험마다 **서로 다른 서비스 이름**을 쓴다.
 * 그러지 않으면 먼저 돈 시험이 이름을 등록해 버려 뒤 시험이 순서에 따라 달라진다.
 */
final class UnwiredNotifierTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    private function notifier(?CollectingMailer $mailer, string $owner): UnwiredNotifier
    {
        return new UnwiredNotifier($mailer, $owner, function (string $line): void {
            $this->logged[] = $line;
        });
    }

    private function to(): Recipient
    {
        return Recipient::forEmail('a@example.com', '홍길동');
    }

    private function vars(): array
    {
        return ['사이트명' => '우리 사이트', '이름' => '홍길동',
            '링크' => 'https://example.test/reset', '유효시간' => '1시간'];
    }

    /** 기본값이 메일인 알림은 옛날과 똑같이 메일 한 통으로 나간다. */
    public function testSendsTheMailTheDefaultsAskFor(): void
    {
        $mailer = new CollectingMailer();

        $sent = $this->notifier($mailer, 'Owner\\A')->notify('password_reset', $this->to(), $this->vars());

        self::assertTrue($sent);
        self::assertCount(1, $mailer->messages);
        self::assertSame('[우리 사이트] 비밀번호 재설정', $mailer->messages[0]['subject']);
        self::assertSame('a@example.com', $mailer->messages[0]['to']);
    }

    /** 기본값이 "채널 없음"인 알림(welcome)은 이 길에서도 나가지 않는다. */
    public function testSendsWelcomeMailByDefault(): void
    {
        $mailer = new CollectingMailer();

        $sent = $this->notifier($mailer, 'Owner\\B')->notify('welcome', $this->to(), $this->vars());

        self::assertTrue($sent);
        self::assertCount(1, $mailer->messages);
    }

    /**
     * 배선이 빠졌다는 사실은 운영자 로그에 남는다. 이것이 없으면 대체 경로는 제대로
     * 배선된 것과 겉으로 구별되지 않고, 기능이 조용히 꺼진 채 나간다.
     */
    public function testSaysOnceThatTheServiceHasNoNotifier(): void
    {
        $notifier = $this->notifier(new CollectingMailer(), 'Owner\\C');

        $notifier->notify('password_reset', $this->to(), $this->vars());

        self::assertCount(1, $this->logged);
        self::assertStringContainsString('Owner\\C', $this->logged[0]);
        self::assertStringContainsString('배선되지 않았습니다', $this->logged[0]);
        // 진단은 이 서비스가 **실제로 할 일**을 말해야 한다. 이쪽은 메일로는 보낸다.
        self::assertStringContainsString('기본값(메일)으로만 보냅니다', $this->logged[0]);
    }

    /** 발송마다 되풀이하지 않는다 — 같은 사실을 반복해 적으면 읽어야 할 다른 줄을 덮는다. */
    public function testDoesNotRepeatTheLineForTheSameService(): void
    {
        $mailer = new CollectingMailer();
        $this->notifier($mailer, 'Owner\\D')->notify('password_reset', $this->to(), $this->vars());
        $this->notifier($mailer, 'Owner\\D')->notify('email_verify', $this->to(), $this->vars());

        self::assertCount(1, $this->logged);
        self::assertCount(2, $mailer->messages, '메일은 두 통 다 나가야 한다');
    }

    /** 서비스가 다르면 따로 적는다 — 어느 배선이 빠졌는지가 이 줄의 전부다. */
    public function testEachServiceGetsItsOwnLine(): void
    {
        $mailer = new CollectingMailer();
        $this->notifier($mailer, 'Owner\\E')->notify('password_reset', $this->to(), $this->vars());
        $this->notifier($mailer, 'Owner\\F')->notify('password_reset', $this->to(), $this->vars());

        self::assertCount(2, $this->logged);
    }

    /**
     * **canReach() 가 답을 잘못하면 사람이 자기 계정 밖에 갇힌다.** 가입 화면은 이 답을
     * 보고 가입을 받을지 정한다(R156·R159) — 인증 링크가 아무 데도 갈 수 없는데 참이라고
     * 답하면, 그 사람은 계정을 만든 채 인증 메일을 영영 기다리게 된다. 이 길에는 채널이
     * 메일 하나뿐이므로 답을 가르는 것도 셋뿐이다: 메일러가 있는가, 이 알림의 기본값에
     * 메일이 있는가, 이 수신자에게 주소가 있는가.
     */
    public function testCanReachIsTrueOnlyWhenTheMailCanActuallyGo(): void
    {
        $notifier = $this->notifier(new CollectingMailer(), 'Owner\\H');

        self::assertTrue($notifier->canReach('password_reset', $this->to()));
        self::assertTrue($notifier->canReach('welcome', $this->to()));
        // 주소가 없으면 메일 채널이 스스로 못 간다고 답한다.
        self::assertFalse($notifier->canReach('password_reset',
            Recipient::forUser(['id' => '1', 'display_name' => '홍길동'])));
        // 묻기만 한 자리라 배선 경고는 남기지 않는다 — 경고는 실제로 나갈 때 적는다.
        self::assertSame([], $this->logged);
    }

    /** 메일러조차 없으면 어떤 알림도 닿을 수 없다. 이 길에는 다른 채널이 없다. */
    public function testCanReachIsFalseWithoutAMailer(): void
    {
        self::assertFalse($this->notifier(null, 'Owner\\I')->canReach('password_reset', $this->to()));
        self::assertSame([], $this->logged);
    }

    /** 메일러조차 없는 서비스(LinkingService)도 한 줄은 남긴다. 보낼 길이 없다는 사실이 사라지면 안 된다. */
    public function testStillSaysSoWhenThereIsNoMailerEither(): void
    {
        $sent = $this->notifier(null, 'Owner\\G')->notify('password_reset', $this->to(), $this->vars());

        self::assertFalse($sent);
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('Owner\\G', $this->logged[0]);
        // 메일러가 없으니 메일로도 안 나간다. 틀린 진단은 읽는 사람을 없는 문제로 보낸다.
        self::assertStringContainsString('아무 데도 나가지 않습니다', $this->logged[0]);
        self::assertStringNotContainsString('기본값(메일)으로만 보냅니다', $this->logged[0]);
    }
}
