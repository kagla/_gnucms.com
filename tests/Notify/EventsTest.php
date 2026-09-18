<?php

declare(strict_types=1);

namespace GnuCms\Tests\Notify;

use GnuCms\Notify\Events;
use GnuCms\Notify\Recipient;
use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    public function testCarriesTheSevenCoreEvents(): void
    {
        self::assertSame([
            'password_reset', 'password_changed', 'welcome', 'comment_new',
            'email_verify', 'signup_attempt', 'social_email_verify',
        ], array_keys(Events::ALL));
    }

    public function testOnlyFourEventsCanGoToAPhone(): void
    {
        foreach (['password_reset', 'password_changed', 'welcome', 'comment_new'] as $key) {
            self::assertTrue(Events::phoneCapable($key), $key);
        }
        foreach (['email_verify', 'signup_attempt', 'social_email_verify'] as $key) {
            self::assertFalse(Events::phoneCapable($key), $key);
        }
    }

    public function testEveryEventDeclaresItsVariables(): void
    {
        foreach (Events::ALL as $key => $event) {
            self::assertNotSame('', $event['label'], $key);
            self::assertContains('사이트명', $event['vars'], $key);
        }
        self::assertContains('링크', Events::variables('password_reset'));
    }

    public function testRecipientReadsWhatTheUserRowHas(): void
    {
        $to = Recipient::forUser(['id' => '7', 'display_name' => '홍길동',
            'email' => 'a@example.com', 'phone' => '01012345678']);

        self::assertSame('7', $to->userId);
        self::assertSame('홍길동', $to->name);
        self::assertSame('a@example.com', $to->email);
        self::assertSame('01012345678', $to->phone);
    }

    public function testRecipientWithoutAPhoneIsNull(): void
    {
        $to = Recipient::forUser(['id' => '7', 'display_name' => '홍길동',
            'email' => 'a@example.com', 'phone' => '']);
        self::assertNull($to->phone);

        $guest = Recipient::forEmail('b@example.com');
        self::assertNull($guest->phone);
        self::assertNull($guest->userId);
        self::assertSame('b@example.com', $guest->email);
    }

    /**
     * 실제 발송문에 쓰이는 값은 전부 vars 에 있어야 한다. email_verify·social_email_verify
     * 는 "이 링크는 N시간(분) 동안 유효합니다"를 본문에 쓰고(AccountService::sendVerification,
     * SocialAuthService::sendPendingEmail), password_changed 는 재설정 링크를 함께
     * 보낸다(AccountService::notifyPasswordChanged 의 /forgot-password 링크).
     */
    public function testEveryEventVariableCoversItsRealMailBody(): void
    {
        self::assertContains('유효시간', Events::variables('email_verify'));
        self::assertContains('유효시간', Events::variables('social_email_verify'));
        self::assertContains('링크', Events::variables('password_changed'));
    }

    /**
     * phone[]=x 처럼 배열로 들어오면 (string) 캐스팅이 경고를 내고 "Array" 라는 값을
     * 그대로 담아 보낸다 — AccountController·AdminController·AccountService 가 이미
     * 세 번 겪은 결함과 같은 모양이다. Recipient 는 스칼라가 아니면 값이 없는 것으로
     * 본다.
     */
    public function testRecipientTreatsNonScalarEmailAndPhoneAsAbsent(): void
    {
        $to = Recipient::forUser(['id' => '7', 'display_name' => '홍길동',
            'email' => ['a@example.com'], 'phone' => ['01012345678']]);

        self::assertNull($to->email);
        self::assertNull($to->phone);
        self::assertSame('7', $to->userId);
        self::assertSame('홍길동', $to->name);
    }

    public function testRecipientTreatsNonScalarIdAndNameAsAbsent(): void
    {
        $to = Recipient::forUser(['id' => ['7'], 'display_name' => ['홍길동'],
            'email' => 'a@example.com', 'phone' => null]);

        self::assertNull($to->userId);
        self::assertSame('', $to->name);
        self::assertSame('a@example.com', $to->email);
    }

    public function testExistsIsTrueOnlyForCatalogueKeys(): void
    {
        self::assertTrue(Events::exists('password_reset'));
        self::assertFalse(Events::exists('promotional_sms'));
    }

    public function testLabelsMapsEveryKeyToItsLabel(): void
    {
        $labels = Events::labels();
        self::assertSame(array_keys(Events::ALL), array_keys($labels));
        self::assertSame('비밀번호 재설정', $labels['password_reset']);
    }

    /**
     * 관리자가 저장해 둔 설정이 업그레이드로 없어진 이벤트를 가리킬 수 있다(예:
     * promotional_sms 가 카탈로그에서 빠진 경우). 예외 없이 "아무 것도 없다"로
     * 답해야 한다 — Task 2 가 이 값을 설정 화면에서 그대로 물어본다.
     */
    public function testUnknownEventKeyIsSafeEverywhere(): void
    {
        self::assertFalse(Events::exists('promotional_sms'));
        self::assertSame([], Events::variables('promotional_sms'));
        self::assertFalse(Events::phoneCapable('promotional_sms'));
    }
}
