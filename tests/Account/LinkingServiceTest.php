<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\IdentityRepository;
use GnuCms\Account\LinkingService;
use GnuCms\Account\UserRepository;
use GnuCms\Oauth\SocialProfile;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class LinkingServiceTest extends DatabaseTestCase
{
    #[DataProvider('connectionProvider')]
    public function testExistingSocialIdentityLogsIntoItsUser(array $config): void
    {
        [$service, $users, $identities] = $this->services($config);
        $id = $users->create('member@example.com', password_hash('password123', PASSWORD_DEFAULT), '기존 회원');
        $users->verifyEmail($id);
        $identities->attach($id, 'github', '42');

        $user = $service->resolve(new SocialProfile('github', '42', null, false, '바뀐 이름'));

        self::assertSame($id, $user['id']);
        self::assertSame('기존 회원', $user['display_name']);
    }

    #[DataProvider('connectionProvider')]
    public function testVerifiedEmailAutomaticallyLinksExistingAccount(array $config): void
    {
        [$service, $users, $identities] = $this->services($config);
        $id = $users->create('member@example.com', password_hash('password123', PASSWORD_DEFAULT), '회원');

        $user = $service->resolve(new SocialProfile('google', 'google-1', 'MEMBER@example.com', true, 'Google 회원'));

        self::assertSame($id, $user['id']);
        self::assertSame($id, (int) $identities->findUser('google', 'google-1')['id']);
    }

    #[DataProvider('connectionProvider')]
    public function testVerifiedEmailCreatesSocialOnlyAccount(array $config): void
    {
        [$service, $users] = $this->services($config);

        $user = $service->resolve(new SocialProfile('google', 'google-2', 'new@example.com', true, '새 회원'));
        $stored = $users->findById($user['id']);

        self::assertNull($stored['password_hash']);
        self::assertSame(1, (int) $stored['email_verified']);
        self::assertSame('새 회원', $stored['display_name']);
        self::assertSame(1, (int) $stored['is_admin']);
    }

    #[DataProvider('connectionProvider')]
    public function testUnverifiedEmailNeverAutomaticallyLinks(array $config): void
    {
        [$service, $users, $identities] = $this->services($config);
        $id = $users->create('owner@example.com', password_hash('password123', PASSWORD_DEFAULT), '주인');

        $result = $service->resolve(new SocialProfile('naver', 'naver-1', 'owner@example.com', false, '공격자'));

        self::assertNull($result);
        self::assertNull($identities->findUser('naver', 'naver-1'));
        self::assertSame(0, $identities->countForUser($id));
    }

    #[DataProvider('connectionProvider')]
    public function testConfirmedPendingEmailCompletesConnection(array $config): void
    {
        [$service, $users, $identities] = $this->services($config);
        $id = $users->create('owner@example.com', password_hash('password123', PASSWORD_DEFAULT), '주인');

        $user = $service->completeVerifiedEmail(
            new SocialProfile('kakao', 'kakao-1', null, false, '카카오 회원'),
            'owner@example.com'
        );

        self::assertSame($id, $user['id']);
        self::assertSame(1, $identities->countForUser($id));
    }

    private function services(array $config): array
    {
        $db = $this->freshDatabase($config);
        $users = new UserRepository($db);
        $identities = new IdentityRepository($db);
        $consents = new \GnuCms\Account\ConsentRepository($db);
        $cms = new \GnuCms\Cms\CmsService(
            new \GnuCms\Cms\CmsRepository($db),
            new \GnuCms\Cms\HtmlSanitizer(),
            new \GnuCms\Cms\ContentImageService(sys_get_temp_dir() . '/' . GNUCMS_ID . '-linking-test'),
            new \GnuCms\Cms\ConsentUseRepository($db),
            $consents
        );
        return [new LinkingService($db, $users, $identities, $cms, $consents), $users, $identities];
    }
}
