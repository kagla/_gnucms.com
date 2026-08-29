<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\AccountService;
use GnuCms\Account\TokenRepository;
use GnuCms\Account\TokenService;
use GnuCms\Account\UserRepository;
use GnuCms\Account\ConsentRepository;
use GnuCms\Cms\CmsRepository;
use GnuCms\Cms\CmsService;
use GnuCms\Error\DomainError;
use GnuCms\Tests\Support\CollectingMailer;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AccountServiceTest extends DatabaseTestCase
{
    #[DataProvider('connectionProvider')]
    public function testFirstOwnerSkipsEmailAndFollowingMemberRequiresVerification(array $config): void
    {
        [$service, $mailer, , $consents] = $this->service($config);
        $owner = $service->register([
            'email' => 'owner@example.com',
            'password' => 'safe-password-123',
            'password_confirmation' => 'safe-password-123',
        ]);
        self::assertTrue($owner['email_verified']);
        self::assertTrue($owner['is_admin']);
        self::assertTrue($owner['newly_created']);
        self::assertCount(0, $mailer->messages);
        self::assertTrue($service->authenticate([
            'email' => 'owner@example.com', 'password' => 'safe-password-123',
        ])['is_admin']);

        $created = $service->register([
            'email' => 'USER@example.com', 'password' => 'member-password-123',
            'password_confirmation' => 'member-password-123', 'agree_terms' => '1', 'agree_privacy' => '1',
        ]);
        self::assertFalse($created['email_verified']);
        self::assertFalse($created['is_admin']);
        self::assertCount(1, $mailer->messages);
        self::assertCount(2, $consents->forUser($created['id']));

        try {
            $service->authenticate(['email' => 'user@example.com', 'password' => 'member-password-123']);
            self::fail('인증 전에는 로그인할 수 없어야 한다.');
        } catch (DomainError $e) {
            self::assertStringContainsString('인증', $e->details()['email']);
        }

        $token = $this->tokenFrom($mailer->messages[0]['body']);
        $service->verifyEmail($token);
        $loggedIn = $service->authenticate(['email' => 'user@example.com', 'password' => 'member-password-123']);
        self::assertSame('user', $loggedIn['display_name']);
        self::assertFalse($service->identityForSession($loggedIn['id'], 0)->isGuest());

        $this->expectException(DomainError::class);
        $service->verifyEmail($token);
    }

    #[DataProvider('connectionProvider')]
    public function testOnlyFirstRegistrationBecomesAdmin(array $config): void
    {
        [$service] = $this->service($config);
        $first = $service->register([
            'email' => 'first@example.com', 'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ]);
        $second = $service->register([
            'email' => 'second@example.com', 'password' => 'password-456',
            'password_confirmation' => 'password-456', 'agree_terms' => '1', 'agree_privacy' => '1',
        ]);

        self::assertTrue($first['is_admin']);
        self::assertFalse($second['is_admin']);
    }

    #[DataProvider('connectionProvider')]
    public function testDuplicateSignupDoesNotRevealExistingAccount(array $config): void
    {
        [$service, $mailer] = $this->service($config);
        $input = [
            'email' => 'member@example.com',
            'password' => 'safe-password-123',
            'password_confirmation' => 'safe-password-123',
        ];
        $first = $service->register($input);
        $input['agree_terms'] = '1';
        $input['agree_privacy'] = '1';
        $second = $service->register($input);

        self::assertSame($first['id'], $second['id']);
        self::assertFalse($second['newly_created']);
        self::assertCount(1, $mailer->messages);

        $input['email'] = 'other@example.com';
        $input['password_confirmation'] = 'different-password';
        try {
            $service->register($input);
            self::fail('비밀번호 확인 불일치는 거부되어야 한다.');
        } catch (DomainError $e) {
            self::assertArrayHasKey('password_confirmation', $e->details());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testPasswordResetChangesPasswordAndInvalidatesSession(array $config): void
    {
        [$service, $mailer] = $this->service($config);
        $service->register([
            'email' => 'member@example.com',
            'password' => 'old-password-123',
            'password_confirmation' => 'old-password-123',
        ]);
        $user = $service->authenticate(['email' => 'member@example.com', 'password' => 'old-password-123']);

        $service->requestPasswordReset('member@example.com');
        $resetToken = $this->tokenFrom($mailer->messages[0]['body']);
        $service->resetPassword([
            'token' => $resetToken,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ]);

        self::assertTrue($service->identityForSession($user['id'], $user['session_epoch'])->isGuest());
        self::assertSame($user['id'], $service->authenticate([
            'email' => 'member@example.com',
            'password' => 'new-password-456',
        ])['id']);
    }

    #[DataProvider('connectionProvider')]
    public function testAdminEmailLoginAndPasswordChange(array $config): void
    {
        [$service, , $users] = $this->service($config);
        $id = $users->create('admin@example.com', password_hash('temporary-password', PASSWORD_DEFAULT), '관리자', true);
        $users->verifyEmail($id);

        $user = $service->authenticate(['email' => 'admin@example.com', 'password' => 'temporary-password']);
        $service->changePassword($id, [
            'current_password' => 'temporary-password',
            'password' => 'changed-password-123',
            'password_confirmation' => 'changed-password-123',
        ]);

        self::assertTrue($user['is_admin']);
        self::assertTrue($service->identityForSession($id, $user['session_epoch'])->isGuest());
        self::assertSame($id, $service->authenticate([
            'email' => 'admin@example.com', 'password' => 'changed-password-123',
        ])['id']);
    }

    #[DataProvider('connectionProvider')]
    public function testVerificationMailUsesConfiguredSiteName(array $config): void
    {
        [$service, $mailer, , , $cms] = $this->service($config);
        $service->register([
            'email' => 'owner@example.com', 'password' => 'safe-password-123',
            'password_confirmation' => 'safe-password-123',
        ]);
        $cms->saveSettings(['site_name' => '우리 커뮤니티']);

        $service->register([
            'email' => 'member@example.com', 'password' => 'member-password-123',
            'password_confirmation' => 'member-password-123', 'agree_terms' => '1', 'agree_privacy' => '1',
        ]);

        self::assertCount(1, $mailer->messages);
        self::assertSame('[우리 커뮤니티] 이메일 인증', $mailer->messages[0]['subject']);
        self::assertStringContainsString('우리 커뮤니티 가입을 완료하려면', $mailer->messages[0]['body']);
        self::assertStringNotContainsString(GNUCMS, $mailer->messages[0]['subject']);
    }

    private function service(array $config): array
    {
        $db = $this->freshDatabase($config);
        $mailer = new CollectingMailer();
        $users = new UserRepository($db);
        $cmsRepository = new CmsRepository($db);
        foreach ([['terms', '이용약관'], ['privacy', '개인정보 처리방침']] as $legal) {
            $cmsRepository->createPage([
                'slug' => $legal[0], 'title' => $legal[1], 'content' => $legal[1] . ' 본문',
                'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0,
                // 가입 동의 항목이라는 표시. 이 표시가 붙은 내용만 가입 화면에 나온다.
                'consent_key' => $legal[0], 'consent_order' => 0,
            ]);
        }
        $consents = new ConsentRepository($db);
        $service = new AccountService(
            $users,
            new TokenService(new TokenRepository($db)),
            $mailer,
            'https://example.test',
            new CmsService($cmsRepository),
            $consents
        );

        return [$service, $mailer, $users, $consents, $cmsRepository];
    }

    private function tokenFrom(string $body): string
    {
        self::assertSame(1, preg_match('/[?&]token=([^\s]+)/', $body, $matches));

        return rawurldecode($matches[1]);
    }
}
