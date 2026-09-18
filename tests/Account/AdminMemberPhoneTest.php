<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\AccountService;
use GnuCms\Account\AdminService;
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
use GnuCms\Repository\BoardRepository;
use GnuCms\Repository\CommentRepository;
use GnuCms\Repository\PostRepository;
use GnuCms\Service\BoardService;
use GnuCms\Tests\Support\CollectingMailer;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 관리자 회원 수정 화면도 회원정보 수정과 같은 signup_phone 세 정책을 따라야
 * 한다(AdminService::updateMember() 가 AccountService::phoneForEdit() 를 그대로
 * 빌려 쓴다) — 여기서는 그 위임이 실제로 세 정책 모두를 지키는지 확인한다.
 */
final class AdminMemberPhoneTest extends DatabaseTestCase
{
    #[DataProvider('connectionProvider')]
    public function testAdminCanSetAndClearAMembersNumber(array $config): void
    {
        [$admin, $db, $memberId] = $this->bootWithMember($config, 'optional');

        $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '010-1234-5678']));
        self::assertSame('01012345678', $this->phoneOf($db, $memberId));

        $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '']));
        self::assertNull($this->phoneOf($db, $memberId));
    }

    /** THE TRAP, 관리자 화면에도 같은 핀: off 인 동안은 새 값도, 빈 값도 저장된 번호를 건드리지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testStoredNumberSurvivesAPolicySwitchToOff(array $config): void
    {
        [$admin, $db, $memberId, $cms] = $this->bootWithMember($config, 'optional');
        $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '010-1234-5678']));
        self::assertSame('01012345678', $this->phoneOf($db, $memberId));

        $cms->saveWritingSettings($this->adminAcl(), $this->writingInput(['signup_phone' => 'off']));

        $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '010-9999-8888']));
        self::assertSame('01012345678', $this->phoneOf($db, $memberId), '정책이 꺼진 동안은 새 값도 쓰지 않아야 한다');

        $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '']));
        self::assertSame('01012345678', $this->phoneOf($db, $memberId), '빈 값 제출도 off 에서는 지우는 뜻이 아니다');
    }

    #[DataProvider('connectionProvider')]
    public function testRequiredPolicyRejectsAnEmptySubmit(array $config): void
    {
        [$admin, $db, $memberId] = $this->bootWithMember($config, 'required');
        $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '010-1234-5678']));

        try {
            $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '']));
            self::fail('required 정책에서는 빈 번호를 거절해야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('phone', $e->details());
        }
        self::assertSame('01012345678', $this->phoneOf($db, $memberId));
    }

    #[DataProvider('connectionProvider')]
    public function testABadNumberIsRefused(array $config): void
    {
        [$admin, , $memberId] = $this->bootWithMember($config, 'optional');

        $this->expectException(DomainError::class);
        $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '02-1234-5678']));
    }

    /** freshDatabase() 로 DB 를 만들고 signup_phone 을 $policy 로 저장한 뒤, 활성 회원 한 명과 AdminService 를 돌려준다. */
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
            new ContentImageService(sys_get_temp_dir() . '/' . GNUCMS_ID . '-admin-member-phone-test'),
            $consentUses,
            $consents
        );
        $accountService = new AccountService(
            $users,
            new TokenService(new TokenRepository($db)),
            new CollectingMailer(),
            'https://example.test',
            $cms,
            $consents
        );
        $boards = new BoardService(
            $db,
            new BoardRepository($db),
            new PostRepository($db),
            new CommentRepository($db)
        );
        $admin = new AdminService($db, $users, $boards, $accountService);

        $memberId = $users->create(
            'member@example.com',
            password_hash('member-password-123', PASSWORD_DEFAULT),
            'member',
            false
        );
        $users->verifyEmail($memberId);

        return [$admin, $db, $memberId, $cms];
    }

    /** updateMember() 이 요구하는 이메일·표시 이름을 채운다. */
    private function member(array $override): array
    {
        return array_replace([
            'email' => 'member@example.com',
            'display_name' => 'member',
            'status' => 'active',
        ], $override);
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
