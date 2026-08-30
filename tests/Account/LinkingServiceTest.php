<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\ConsentTrace;
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
        // 소셜 프로필 이름의 공백은 걷어 낸다 — 표시 이름은 한글·영문·숫자만 받는다.
        self::assertSame('새회원', $stored['display_name']);
        self::assertSame(1, (int) $stored['is_admin']);
    }

    /** 필수·선택 약관이 붙은 상태에서 소셜 가입해도 동의 증적이 남는다. */
    #[DataProvider('connectionProvider')]
    public function testSocialSignupRecordsRequiredConsentsWithTrace(array $config): void
    {
        [$service, $users, , , $consents, $cmsRepository, $consentUses] = $this->services($config);
        // '첫 사람' 자리는 site_state 깃발로 정해진다. createRegistered() 로 만들어야
        // 그 깃발이 꽂혀, 뒤이은 소셜 가입자가 관리자로 올라가지 않고 동의를 남긴다.
        $users->createRegistered('owner@example.com', password_hash('password123', PASSWORD_DEFAULT), '주인');
        foreach ([['terms', '이용약관', true], ['marketing', '마케팅 정보 수신', false]] as $order => $doc) {
            $id = $cmsRepository->createPage([
                'slug' => $doc[0], 'title' => $doc[1], 'content' => $doc[1] . ' 본문',
                'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0,
                'sort_order' => 0, 'is_consent' => 1,
            ]);
            $consentUses->attach('signup', $id, $doc[2], $order);
        }

        $trace = new ConsentTrace('198.51.100.9', 'Mozilla/Test');
        $user = $service->resolve(
            new SocialProfile('google', 'google-3', 'social@example.com', true, '소셜 회원'),
            $trace
        );

        $rows = $consents->forSubject('user', (int) $user['id']);
        self::assertCount(2, $rows);
        $agreed = [];
        foreach ($rows as $row) {
            self::assertSame('signup', $row['scope']);
            self::assertSame('198.51.100.9', $row['agreed_ip']);
            $agreed[$row['consent_type']] = (int) $row['agreed'];
        }
        self::assertSame(['terms' => 1, 'marketing' => 0], $agreed);
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
        $cmsRepository = new \GnuCms\Cms\CmsRepository($db);
        $consentUses = new \GnuCms\Cms\ConsentUseRepository($db);
        $cms = new \GnuCms\Cms\CmsService(
            $cmsRepository,
            new \GnuCms\Cms\HtmlSanitizer(),
            new \GnuCms\Cms\ContentImageService(sys_get_temp_dir() . '/' . GNUCMS_ID . '-linking-test'),
            $consentUses,
            $consents
        );
        return [
            new LinkingService($db, $users, $identities, $cms, $consents),
            $users, $identities, $cms, $consents, $cmsRepository, $consentUses,
        ];
    }
}
