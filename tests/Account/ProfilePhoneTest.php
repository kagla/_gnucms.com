<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\AccountService;
use GnuCms\Account\ConsentRepository;
use GnuCms\Account\TokenRepository;
use GnuCms\Account\TokenService;
use GnuCms\Account\UserRepository;
use GnuCms\Auth\Acl;
use GnuCms\Auth\Identity;
use GnuCms\Cms\CmsRepository;
use GnuCms\Cms\CmsService;
use GnuCms\Cms\ConsentUseRepository;
use GnuCms\Cms\ContentImageService;
use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Tests\Support\CollectingMailer;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 회원정보 수정 화면에서 휴대폰번호를 넣고 지우는지. 가입 화면과 달리 signup_phone
 * 정책과 무관하게 이미 있는 번호를 고칠 수 있어야 하지만, off 인 동안은 칸 자체를
 * 건드리지 않아야 한다 — off 가 "null 을 쓴다"는 뜻이면 정책을 끄는 순간 모든
 * 회원의 저장된 번호가 다음 프로필 저장 때마다 조용히 지워진다.
 */
final class ProfilePhoneTest extends DatabaseTestCase
{
    #[DataProvider('connectionProvider')]
    public function testMemberCanSetAndClearTheirNumber(array $config): void
    {
        [$service, $db, $userId] = $this->bootWithMember($config, 'optional');

        $service->updateProfile($userId, $this->profile(['phone' => '010-1234-5678']));
        self::assertSame('01012345678', $this->phoneOf($db, $userId));

        $service->updateProfile($userId, $this->profile(['phone' => '']));
        self::assertNull($this->phoneOf($db, $userId));
    }

    /**
     * THE TRAP, 고정 핀. optional 로 번호를 저장한 뒤 정책을 off 로 바꾸고 다시
     * 저장해도(새 번호를 넣든, 빈 값을 보내든) 기존 번호가 그대로 남아 있어야
     * 한다. off 는 "칸을 건드리지 않는다"는 뜻이지 "지운다"는 뜻이 아니다.
     */
    #[DataProvider('connectionProvider')]
    public function testStoredNumberSurvivesAPolicySwitchToOff(array $config): void
    {
        [$service, $db, $userId, $cms] = $this->bootWithMember($config, 'optional');
        $service->updateProfile($userId, $this->profile(['phone' => '010-1234-5678']));
        self::assertSame('01012345678', $this->phoneOf($db, $userId));

        // CmsService 는 settings() 를 메모리에 캐시하므로, 캐시를 비우는 공개
        // API(saveWritingSettings())로 정책을 바꿔야 $service 가 새 값을 본다 —
        // 리포지토리를 직접 건드리면 이 테스트가 실제로는 확인하지 않는 것이 된다.
        $cms->saveWritingSettings($this->adminAcl(), $this->writingInput(['signup_phone' => 'off']));

        $service->updateProfile($userId, $this->profile(['phone' => '010-9999-8888']));
        self::assertSame('01012345678', $this->phoneOf($db, $userId), '정책이 꺼진 동안은 새 값도 쓰지 않아야 한다');

        $service->updateProfile($userId, $this->profile(['phone' => '']));
        self::assertSame('01012345678', $this->phoneOf($db, $userId), '빈 값 제출도 off 에서는 지우는 뜻이 아니다');
    }

    #[DataProvider('connectionProvider')]
    public function testRequiredPolicyRejectsAnEmptySubmit(array $config): void
    {
        [$service, $db, $userId] = $this->bootWithMember($config, 'required');
        $service->updateProfile($userId, $this->profile(['phone' => '010-1234-5678']));

        try {
            $service->updateProfile($userId, $this->profile(['phone' => '']));
            self::fail('required 정책에서는 빈 번호를 거절해야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('phone', $e->details());
        }
        self::assertSame('01012345678', $this->phoneOf($db, $userId), '거절됐으니 기존 번호가 남아 있어야 한다');
    }

    #[DataProvider('connectionProvider')]
    public function testABadNumberIsRefused(array $config): void
    {
        [$service, , $userId] = $this->bootWithMember($config, 'optional');

        $this->expectException(DomainError::class);
        $service->updateProfile($userId, $this->profile(['phone' => '02-1234-5678']));
    }

    /**
     * freshDatabase() 로 DB 를 만들고 signup_phone 을 $policy 로 저장한 뒤, 활성
     * 회원 한 명과 서비스를 돌려준다. CmsService 도 함께 돌려주는 건, 테스트 중에
     * 정책을 바꿔야 할 때 이 서비스가 들고 있는 캐시를 공개 API 로 비우기 위해서다.
     */
    private function bootWithMember(array $config, string $policy): array
    {
        $db = $this->freshDatabase($config);
        $cmsRepository = new CmsRepository($db);
        $cmsRepository->saveSettings(['signup_phone' => $policy]);
        $consentUses = new ConsentUseRepository($db);
        $consents = new ConsentRepository($db);
        $users = new UserRepository($db);
        $cms = new CmsService(
            $cmsRepository,
            new HtmlSanitizer(),
            new ContentImageService(sys_get_temp_dir() . '/' . GNUCMS_ID . '-profile-phone-test'),
            $consentUses,
            $consents
        );
        $service = new AccountService(
            $users,
            new TokenService(new TokenRepository($db)),
            new CollectingMailer(),
            'https://example.test',
            $cms,
            $consents
        );
        $userId = $users->create(
            'member@example.com',
            password_hash('member-password-123', PASSWORD_DEFAULT),
            '회원',
            false
        );
        $users->verifyEmail($userId);

        return [$service, $db, $userId, $cms];
    }

    /** updateProfile() 이 요구하는 표시 이름을 채운다. */
    private function profile(array $override): array
    {
        return array_replace(['display_name' => '회원'], $override);
    }

    private function phoneOf(Connection $db, int $userId): ?string
    {
        return $db->selectOne('SELECT phone FROM ' . $db->table('users') . ' WHERE id = ?', [$userId])['phone'];
    }

    private function adminAcl(): Acl
    {
        return new Acl(Identity::user('1', '관리자', true));
    }

    /** saveWritingSettings() 는 전체 입력을 요구하므로, 나머지 필드는 기본값으로 채우고 필요한 값만 덮어쓴다. */
    private function writingInput(array $overrides = []): array
    {
        return array_replace([
            'guest_write_enabled' => '0',
            'post_min_chars' => '0',
            'comment_min_chars' => '0',
            'post_rate_interval' => '30',
            'post_rate_10m' => '5',
            'post_rate_day' => '20',
            'comment_rate_interval' => '5',
            'comment_rate_10m' => '20',
            'comment_rate_day' => '100',
            'attach_max_mb' => '5',
            'attach_limit' => '5',
            'signup_phone' => 'off',
        ], $overrides);
    }
}
