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
}
