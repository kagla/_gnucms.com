<?php

declare(strict_types=1);

namespace GnuCms\Tests\Mail;

use GnuCms\Auth\Acl;
use GnuCms\Auth\Identity;
use GnuCms\Mail\MailSettingsRepository;
use GnuCms\Mail\MailSettingsService;
use GnuCms\Mail\SecretCipher;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MailSettingsServiceTest extends DatabaseTestCase
{
    #[DataProvider('connectionProvider')]
    public function testPresetIsSavedAndPasswordIsEncrypted(array $config): void
    {
        $db = $this->freshDatabase($config);
        $repository = new MailSettingsRepository($db);
        $service = new MailSettingsService($repository, new SecretCipher('test-secret'), 'no-reply@example.com');
        $acl = new Acl(Identity::user('1', '관리자', true));

        $service->save($acl, [
            'enabled' => '1', 'provider' => 'naver', 'host' => 'wrong.example', 'port' => '1',
            'encryption' => 'ssl', 'username' => 'owner', 'password' => 'app-password-value',
            'from_email' => 'owner@naver.com', 'from_name' => '사이트 운영자',
        ]);

        $stored = $repository->all();
        self::assertSame('smtp.naver.com', $stored['host']);
        self::assertSame('587', $stored['port']);
        self::assertSame('tls', $stored['encryption']);
        self::assertNotSame('app-password-value', $stored['password']);
        self::assertStringStartsWith('v1:', $stored['password']);
        self::assertSame('app-password-value', $service->runtime()['password']);
        self::assertSame('', $service->formValues($acl)['password']);
        self::assertTrue($service->formValues($acl)['password_set']);
    }
}
