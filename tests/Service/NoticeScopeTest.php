<?php

declare(strict_types=1);

namespace GnuCms\Tests\Service;

use GnuCms\Auth\Acl;
use GnuCms\Auth\Identity;
use GnuCms\Error\DomainError;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NoticeScopeTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testAdminCanPinToThisBoardOrEveryBoard(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);

        $plain = $app->postService()->create($acl, 'free', [
            'title' => '보통 글', 'content' => '본문입니다', 'notice' => 'none',
        ]);
        $board = $app->postService()->create($acl, 'free', [
            'title' => '게시판 공지', 'content' => '본문입니다', 'notice' => 'board',
        ]);
        $global = $app->postService()->create($acl, 'free', [
            'title' => '전체 공지', 'content' => '본문입니다', 'notice' => 'global',
        ]);

        self::assertFalse($plain['is_notice']);
        self::assertTrue($board['is_notice']);
        self::assertSame('board', $board['notice_scope']);
        self::assertTrue($global['is_notice']);
        self::assertSame('global', $global['notice_scope']);
    }

    #[DataProvider('connectionProvider')]
    public function testUpdateCanRaiseAndLowerANotice(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $post = $app->postService()->create($acl, 'free', ['title' => '글', 'content' => '본문입니다']);

        $raised = $app->postService()->update($acl, $post['id'], [
            'title' => '글', 'content' => '본문입니다', 'notice' => 'global',
        ]);
        self::assertTrue($raised['is_notice']);
        self::assertSame('global', $raised['notice_scope']);

        $lowered = $app->postService()->update($acl, $post['id'], [
            'title' => '글', 'content' => '본문입니다', 'notice' => 'none',
        ]);
        self::assertFalse($lowered['is_notice']);
        self::assertSame('board', $lowered['notice_scope'], '공지를 내리면 범위도 기본으로 돌아간다');
    }

    #[DataProvider('connectionProvider')]
    public function testMemberCannotPinANotice(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'member',
        ]);
        $member = new Acl(Identity::user('7', '회원사람', false));

        try {
            $app->postService()->create($member, 'free', [
                'title' => '몰래 공지', 'content' => '본문입니다', 'notice' => 'global',
            ]);
            self::fail('관리자가 아니면 공지를 올릴 수 없어야 한다');
        } catch (DomainError $e) {
            self::assertContains($e->status(), [401, 403]);
            self::assertSame('전역 관리자만 할 수 있습니다.', $e->getMessage());
        }
    }

    /** 게시판 관리자는 그 게시판 공지까지만 올릴 수 있다. 전체 공지는 사이트 관리자만. */
    #[DataProvider('connectionProvider')]
    public function testBoardManagerCannotPinGlobally(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'managers' => ['7'],
        ]);
        $manager = new Acl(Identity::user('7', '게시판지기', false));

        $board = $app->postService()->create($manager, 'free', [
            'title' => '게시판 공지', 'content' => '본문입니다', 'notice' => 'board',
        ]);
        self::assertTrue($board['is_notice']);
        self::assertSame('board', $board['notice_scope']);

        try {
            $app->postService()->create($manager, 'free', [
                'title' => '몰래 전체 공지', 'content' => '본문입니다', 'notice' => 'global',
            ]);
            self::fail('게시판 관리자는 전체 공지를 올릴 수 없어야 한다');
        } catch (DomainError $e) {
            self::assertContains($e->status(), [401, 403]);
            self::assertSame('전역 관리자만 할 수 있습니다.', $e->getMessage());
        }
    }

    /**
     * update() 의 공지 가드가 실제로 걸려 있는지 못박는다. 자기 글이라도
     * notice=global / is_notice=1 을 보내면 회원은 막혀야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testNonAdminCannotSetNoticeOnOwnPostViaUpdate(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'member',
        ]);
        $member = new Acl(Identity::user('7', '회원사람', false));
        $post = $app->postService()->create($member, 'free', [
            'title' => '글', 'content' => '본문입니다',
        ]);

        foreach ([['notice' => 'global'], ['is_notice' => '1']] as $input) {
            try {
                $app->postService()->update($member, $post['id'], array_merge(
                    ['title' => '글', 'content' => '본문입니다'],
                    $input
                ));
                self::fail('회원은 자기 글이라도 공지로 만들 수 없어야 한다');
            } catch (DomainError $e) {
                self::assertContains($e->status(), [401, 403]);
            }
        }
    }

    /**
     * create() 의 게시판 공지 가드를 못박는다. 관리자도 게시판 관리자도 아닌
     * 평범한 회원은 notice=board 로도, 옛 입력 is_notice=1 로도 공지를
     * 올릴 수 없어야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testPlainMemberCannotPinABoardNoticeOnCreate(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'member',
        ]);
        $member = new Acl(Identity::user('7', '회원사람', false));

        foreach ([['notice' => 'board'], ['is_notice' => '1']] as $input) {
            try {
                $app->postService()->create($member, 'free', array_merge(
                    ['title' => '몰래 게시판 공지', 'content' => '본문입니다'],
                    $input
                ));
                self::fail('평범한 회원은 게시판 공지를 올릴 수 없어야 한다');
            } catch (DomainError $e) {
                self::assertContains($e->status(), [401, 403]);
                self::assertSame('이 게시판의 관리자만 할 수 있습니다.', $e->getMessage());
            }
        }
    }

    /**
     * "사이트 관리자만 바꿀 수 있습니다" 라는 문구가 실제로 지켜지는지 못박는다.
     * 저장된 글이 이미 전체 공지라면, 게시판 관리자가 notice=none 을 보내
     * 내리려 해도 막혀야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testBoardManagerCannotLowerAStoredGlobalNotice(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'managers' => ['7'],
        ]);
        $manager = new Acl(Identity::user('7', '게시판지기', false));
        $global = $app->postService()->create($this->adminAcl(), 'free', [
            'title' => '전체 공지', 'content' => '본문입니다', 'notice' => 'global',
        ]);

        try {
            $app->postService()->update($manager, $global['id'], [
                'title' => '전체 공지', 'content' => '본문입니다', 'notice' => 'none',
            ]);
            self::fail('게시판 관리자는 전체 공지를 내릴 수 없어야 한다');
        } catch (DomainError $e) {
            self::assertContains($e->status(), [401, 403]);
        }

        // 공지 칸을 건드리지 않고 제목·내용만 고치는 것은 여전히 된다.
        $updated = $app->postService()->update($manager, $global['id'], [
            'title' => '전체 공지(고침)', 'content' => '고친 본문입니다',
        ]);
        self::assertTrue($updated['is_notice']);
        self::assertSame('global', $updated['notice_scope']);
    }

    #[DataProvider('connectionProvider')]
    public function testUnknownNoticeValueIsTreatedAsNotANotice(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);

        $post = $app->postService()->create($acl, 'free', [
            'title' => '글', 'content' => '본문입니다', 'notice' => '엉뚱한값',
        ]);

        self::assertFalse($post['is_notice']);
    }

    #[DataProvider('connectionProvider')]
    public function testOldIsNoticeInputStillWorks(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);

        // notice 가 없으면 옛 입력을 본다. 옛 폼과 테스트가 깨지지 않게.
        $post = $app->postService()->create($acl, 'free', [
            'title' => '옛 방식 공지', 'content' => '본문입니다', 'is_notice' => '1',
        ]);

        self::assertTrue($post['is_notice']);
        self::assertSame('board', $post['notice_scope']);
    }
}
