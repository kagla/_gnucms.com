<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Auth;

use PHPUnit\Framework\TestCase;
use ApiBoard\Auth\Acl;
use ApiBoard\Auth\Identity;
use ApiBoard\Error\DomainError;

final class AclTest extends TestCase
{
    public function testGlobalAdminPassesEverything(): void
    {
        $acl = new Acl(Identity::user('root', '관리자', true));
        $board = $this->board(['perm_read' => 'admin', 'perm_write' => 'admin', 'perm_comment' => 'admin']);

        $this->assertTrue($acl->isGlobalAdmin());
        $this->assertTrue($acl->isAdminFor($board));
        $this->assertTrue($acl->canRead($board));
        $this->assertTrue($acl->canWrite($board));
        $this->assertTrue($acl->canComment($board));
    }

    public function testBoardManagerIsAdminForThatBoardOnly(): void
    {
        $acl = new Acl(Identity::user('user-1', '운영자', false));
        $managed = $this->board(['managers' => ['user-1']]);
        $other = $this->board(['managers' => ['user-9']]);

        $this->assertTrue($acl->isBoardManager($managed));
        $this->assertTrue($acl->isAdminFor($managed));
        $this->assertFalse($acl->isAdminFor($other));
    }

    public function testBoardManagerIsNotGlobalAdmin(): void
    {
        $acl = new Acl(Identity::user('user-1', '운영자', false));

        $this->assertFalse($acl->isGlobalAdmin());
        $this->expectException(DomainError::class);
        $acl->assertGlobalAdmin();
    }

    public function testGuestCanReadGuestBoard(): void
    {
        $acl = new Acl(Identity::guest());

        $this->assertTrue($acl->canRead($this->board(['perm_read' => 'guest'])));
    }

    public function testGuestCannotReadMemberBoard(): void
    {
        $acl = new Acl(Identity::guest());

        $this->assertFalse($acl->canRead($this->board(['perm_read' => 'member'])));
    }

    public function testMemberCanWriteMemberBoardButNotAdminBoard(): void
    {
        $acl = new Acl(Identity::user('user-1', '회원', false));

        $this->assertTrue($acl->canWrite($this->board(['perm_write' => 'member'])));
        $this->assertFalse($acl->canWrite($this->board(['perm_write' => 'admin'])));
    }

    public function testGuestCanWriteGuestBoard(): void
    {
        $acl = new Acl(Identity::guest());

        $this->assertTrue($acl->canWrite($this->board(['perm_write' => 'guest'])));
    }

    public function testOwnsMatchesAuthorId(): void
    {
        $acl = new Acl(Identity::user('user-1', '회원', false));

        $this->assertTrue($acl->owns($this->resource('user-1'), null));
        $this->assertFalse($acl->owns($this->resource('user-2'), null));
    }

    public function testGuestNeverOwnsMemberResource(): void
    {
        $acl = new Acl(Identity::guest());

        $this->assertFalse($acl->owns($this->resource('user-1'), null));
    }

    public function testOwnsGuestResourceWithCorrectPassword(): void
    {
        $acl = new Acl(Identity::guest());
        $resource = $this->guestResource('1234');

        $this->assertTrue($acl->owns($resource, '1234'));
        $this->assertFalse($acl->owns($resource, '9999'));
        $this->assertFalse($acl->owns($resource, null));
    }

    public function testLoggedInUserCanAlsoProveGuestResourceWithPassword(): void
    {
        $acl = new Acl(Identity::user('user-1', '회원', false));

        $this->assertTrue($acl->owns($this->guestResource('1234'), '1234'));
    }

    public function testCanModifyForOwnerManagerAndStranger(): void
    {
        $board = $this->board(['managers' => ['mgr-1']]);
        $resource = $this->resource('user-1');

        $this->assertTrue((new Acl(Identity::user('user-1', '본인', false)))->canModify($board, $resource, null));
        $this->assertTrue((new Acl(Identity::user('mgr-1', '운영자', false)))->canModify($board, $resource, null));
        $this->assertTrue((new Acl(Identity::user('root', '관리자', true)))->canModify($board, $resource, null));
        $this->assertFalse((new Acl(Identity::user('user-2', '남', false)))->canModify($board, $resource, null));
    }

    public function testAssertGivesUnauthorizedForGuestAndForbiddenForMember(): void
    {
        $board = $this->board(['perm_write' => 'admin']);

        try {
            (new Acl(Identity::guest()))->assertCanWrite($board);
            $this->fail('게스트는 401 이어야 한다');
        } catch (DomainError $e) {
            $this->assertSame(401, $e->status());
        }

        try {
            (new Acl(Identity::user('user-1', '회원', false)))->assertCanWrite($board);
            $this->fail('회원은 403 이어야 한다');
        } catch (DomainError $e) {
            $this->assertSame(403, $e->status());
        }
    }

    public function testUnknownPermissionLevelDeniesEveryone(): void
    {
        $acl = new Acl(Identity::user('user-1', '회원', false));

        $this->assertFalse($acl->canRead($this->board(['perm_read' => 'nonsense'])));
    }

    private function board(array $overrides = []): array
    {
        return array_merge([
            'id'           => 1,
            'board_key'    => 'free',
            'managers'     => [],
            'perm_read'    => 'guest',
            'perm_write'   => 'member',
            'perm_comment' => 'member',
        ], $overrides);
    }

    private function resource(string $authorId): array
    {
        return ['author_id' => $authorId, 'guest_password' => null];
    }

    private function guestResource(string $password): array
    {
        return ['author_id' => null, 'guest_password' => password_hash($password, PASSWORD_DEFAULT)];
    }
}
