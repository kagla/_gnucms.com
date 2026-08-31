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
        }
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
