<?php

declare(strict_types=1);

namespace GnuCms\Tests\Notify;

use GnuCms\Error\DomainError;
use GnuCms\Notify\Events;
use GnuCms\Notify\MailBodies;
use PHPUnit\Framework\TestCase;

/**
 * 옮겨 온 다섯 통의 문구는 한 글자도 달라지면 안 된다 — 지금 AccountService·
 * SocialAuthService 가 실제로 보내는 메일이고, 계획 5 가 그 호출 자리를 이 클래스로
 * 바꿀 때 기존 메일 테스트가 그대로 통과해야 한다. 그래서 기대값을 여기에 문자열
 * 그대로 박아 둔다: 본문을 손대면 여기서 먼저 깨진다.
 */
final class MailBodiesTest extends TestCase
{
    public function testSignupAttemptKeepsTheWordingItReplaces(): void
    {
        self::assertSame([
            'subject' => '[우리 커뮤니티] 가입 시도 안내',
            'body' => "이미 가입된 계정입니다.\n\n로그인: https://example.com/login",
        ], MailBodies::render('signup_attempt',
            ['사이트명' => '우리 커뮤니티', '링크' => 'https://example.com/login']));
    }

    public function testPasswordResetKeepsTheWordingItReplaces(): void
    {
        self::assertSame([
            'subject' => '[우리 커뮤니티] 비밀번호 재설정',
            'body' => "아래 링크에서 비밀번호를 다시 설정해 주세요.\n\n"
                . "https://example.com/reset-password?token=t\n\n이 링크는 1시간 동안 유효합니다.",
        ], MailBodies::render('password_reset', ['사이트명' => '우리 커뮤니티', '이름' => '홍길동',
            '링크' => 'https://example.com/reset-password?token=t', '유효시간' => '1시간']));
    }

    public function testPasswordChangedKeepsTheWordingItReplaces(): void
    {
        self::assertSame([
            'subject' => '[우리 커뮤니티] 비밀번호가 변경되었습니다',
            'body' => "회원님의 비밀번호가 방금 변경되었습니다.\n\n"
                . "본인이 바꾼 것이 아니라면 아래에서 즉시 비밀번호를 다시 설정하세요.\n\n"
                . "https://example.com/forgot-password\n\n변경 시각: 2026-09-18 03:04:05 (UTC)",
        ], MailBodies::render('password_changed', ['사이트명' => '우리 커뮤니티', '이름' => '홍길동',
            '링크' => 'https://example.com/forgot-password', '일시' => '2026-09-18 03:04:05 (UTC)']));
    }

    public function testEmailVerifyKeepsTheWordingItReplaces(): void
    {
        self::assertSame([
            'subject' => '[우리 커뮤니티] 이메일 인증',
            'body' => "우리 커뮤니티 가입을 완료하려면 아래 링크를 열어 주세요.\n\n"
                . "https://example.com/verify-email?token=t\n\n이 링크는 24시간 동안 유효합니다.",
        ], MailBodies::render('email_verify', ['사이트명' => '우리 커뮤니티', '이름' => '홍길동',
            '링크' => 'https://example.com/verify-email?token=t', '유효시간' => '24시간']));
    }

    public function testSocialEmailVerifyKeepsTheWordingItReplaces(): void
    {
        self::assertSame([
            'subject' => '[우리 커뮤니티] 소셜 로그인 이메일 확인',
            'body' => "소셜 로그인을 완료하려면 아래 링크를 열어 주세요.\n\n"
                . "https://example.com/auth/complete?token=t\n\n이 링크는 30분 동안 유효합니다.",
        ], MailBodies::render('social_email_verify', ['사이트명' => '우리 커뮤니티',
            '링크' => 'https://example.com/auth/complete?token=t', '유효시간' => '30분']));
    }

    /** 코어에 없던 두 통. 문구는 새로 쓰되 변수 자리는 카탈로그와 맞아야 한다. */
    public function testNewBodiesUseTheirEventVariables(): void
    {
        $welcome = MailBodies::render('welcome', ['사이트명' => '우리 커뮤니티', '이름' => '홍길동']);
        self::assertSame('[우리 커뮤니티] 가입을 환영합니다', $welcome['subject']);
        self::assertStringContainsString('홍길동', $welcome['body']);
        self::assertStringContainsString('우리 커뮤니티', $welcome['body']);

        $comment = MailBodies::render('comment_new', ['사이트명' => '우리 커뮤니티', '이름' => '홍길동',
            '글제목' => '첫 글', '작성자' => '김철수', '링크' => 'https://example.com/p/7']);
        self::assertStringContainsString('우리 커뮤니티', $comment['subject']);
        self::assertStringContainsString('첫 글', $comment['body']);
        self::assertStringContainsString('김철수', $comment['body']);
        self::assertStringContainsString('https://example.com/p/7', $comment['body']);
    }

    /**
     * 메일을 켤 수 있는 이벤트는 일곱 개 전부다(NotifySettings 는 메일을 막지 않는다).
     * 본문이 없는 이벤트가 하나라도 있으면 관리자가 켠 메일이 발송 시점에 터진다.
     */
    public function testEveryCatalogueEventHasABody(): void
    {
        foreach (array_keys(Events::ALL) as $event) {
            self::assertTrue(MailBodies::has($event), $event . ' 의 메일 본문이 없습니다');
            $rendered = MailBodies::render($event, array_combine(
                Events::variables($event),
                array_map(static fn (string $name): string => '값:' . $name, Events::variables($event))
            ));
            self::assertNotSame('', $rendered['subject'], $event);
            self::assertNotSame('', $rendered['body'], $event);
        }
    }

    /** 채널 문맥은 메일 본문에도 닿지 못한다. */
    public function testContextValuesNeverReachTheMail(): void
    {
        $rendered = MailBodies::render('comment_new', ['사이트명' => '우리 커뮤니티', '이름' => '홍길동',
            '글제목' => '첫 글', '작성자' => '김철수', '링크' => 'https://example.com/p/7',
            '_post_id' => '99999', '_comment_id' => '88888', '_kind' => 'reply']);

        self::assertStringNotContainsString('99999', $rendered['subject'] . $rendered['body']);
        self::assertStringNotContainsString('88888', $rendered['subject'] . $rendered['body']);
        self::assertStringNotContainsString('reply', $rendered['subject'] . $rendered['body']);
    }

    /** 모르는 이벤트는 has() 가 먼저 거절한다. 그래도 render() 를 부르면 조용히 빈
     *  메일을 만들지 않고 거절한다. */
    public function testUnknownEventIsRefused(): void
    {
        self::assertFalse(MailBodies::has('no_such_event'));

        $this->expectException(DomainError::class);
        MailBodies::render('no_such_event', []);
    }

    /**
     * 값이 빠져도 메일 자체는 나간다 — 비밀번호 재설정 메일이 변수 하나 때문에 통째로
     * 사라지는 편이 더 나쁘다. 문자·알림톡과 일부러 다르게 둔 선택이다.
     *
     * **빈 자리가 진짜로 비어 있는지까지 본다.** 예전에는 '#{' 가 없다는 것만 봤는데,
     * 그 단언은 빠진 값을 무엇으로 채우든 통과한다 — 이벤트 키로 채워
     * 「이 링크는 password_reset 동안 유효합니다.」라고 적어도 초록이었다. 여기서
     * 지키려는 것은 "치환이 일어났다"가 아니라 "빠진 값 자리에 아무것도 없다"이므로
     * 만들어진 본문을 글자 그대로 박아 둔다.
     */
    public function testMissingVariablesLeaveTheirPlaceEmptyRatherThanFailing(): void
    {
        $rendered = MailBodies::render('password_reset', ['사이트명' => '우리 커뮤니티']);

        self::assertSame('[우리 커뮤니티] 비밀번호 재설정', $rendered['subject']);
        self::assertSame("아래 링크에서 비밀번호를 다시 설정해 주세요.\n\n\n\n"
            . '이 링크는  동안 유효합니다.', $rendered['body']);
        self::assertStringNotContainsString('#{', $rendered['body']);
        // 이벤트 키나 변수 이름이 그 자리에 흘러드는 일도 없어야 한다 — 값이 없는 것과
        // 이름이 있는 것은 다른 사실이고, 사람이 읽는 메일에 변수 이름이 나오면 그건
        // 깨진 메일이다. ('링크'는 본문 문장에도 들어 있는 낱말이라 여기서 묻지 않는다.)
        self::assertStringNotContainsString('password_reset', $rendered['body']);
        self::assertStringNotContainsString('유효시간', $rendered['body']);
    }
}
