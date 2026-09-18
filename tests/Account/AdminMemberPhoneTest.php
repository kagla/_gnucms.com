<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\AdminService;
use GnuCms\Account\ConsentRepository;
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
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 관리자 회원 수정은 signup_phone 정책을 보지 않는다 — 그 정책은 가입 화면이
 * 무엇을 물을지를 정할 뿐, 관리자가 무엇을 관리할 수 있는지는 정하지 않는다.
 * 정책이 off(심지어 required)여도 관리자는 늘 번호를 넣고 지울 수 있어야 한다 —
 * off 인 동안 번호를 지우고 싶은 회원은 관리자에게 요청할 수밖에 없는데, 그
 * 관리자조차 못 지우면 번호가 누구에게도 닿지 않는 곳에 갇힌다.
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

    /**
     * 이전 라운드에서는 "off 인 동안 관리자도 못 고친다"를 핀으로 박았는데, 그건
     * 틀린 규칙이었다 — off 인 동안 번호를 지우고 싶은 회원은 관리자에게 요청할
     * 수밖에 없다. 그래서 지금은 정반대를 확인한다: off 여도 관리자는 새 번호를
     * 넣을 수 있고, 지울 수도 있다.
     */
    #[DataProvider('connectionProvider')]
    public function testAdminCanChangeTheNumberEvenWhenThePolicyIsOff(array $config): void
    {
        [$admin, $db, $memberId, $cms] = $this->bootWithMember($config, 'optional');
        $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '010-1234-5678']));
        self::assertSame('01012345678', $this->phoneOf($db, $memberId));

        $cms->saveWritingSettings($this->adminAcl(), $this->writingInput(['signup_phone' => 'off']));

        $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '010-9999-8888']));
        self::assertSame('01099998888', $this->phoneOf($db, $memberId), 'off 여도 관리자는 새 번호를 넣을 수 있어야 한다');

        $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '']));
        self::assertNull($this->phoneOf($db, $memberId), 'off 여도 관리자는 번호를 지울 수 있어야 한다');
    }

    /**
     * required 정책도 관리자를 막지 않는다 — 번호가 없는 예전 회원을 관리자가
     * 저장하려는데 필수 정책 때문에 막히면, 번호 말고는 아무것도 안 바꾸려는
     * 관리자 작업까지 함께 막힌다.
     */
    #[DataProvider('connectionProvider')]
    public function testAdminMayLeaveAMemberWithNoNumberEvenUnderRequiredPolicy(array $config): void
    {
        [$admin, $db, $memberId] = $this->bootWithMember($config, 'required');

        $admin->updateMember($this->adminAcl(), $memberId, $this->member(['phone' => '']));
        self::assertNull($this->phoneOf($db, $memberId));
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
        $boards = new BoardService(
            $db,
            new BoardRepository($db),
            new PostRepository($db),
            new CommentRepository($db)
        );
        $admin = new AdminService($db, $users, $boards);

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
