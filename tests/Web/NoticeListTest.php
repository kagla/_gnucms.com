<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NoticeListTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testGlobalNoticeShowsOnEveryBoardAndBoardNoticeOnlyOnItsOwn(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $app->boardService()->create($acl, ['board_key' => 'qna', 'name' => '질문']);

        $app->postService()->create($acl, 'free', [
            'title' => '점검 안내', 'content' => '본문입니다', 'notice' => 'global',
        ]);
        $app->postService()->create($acl, 'free', [
            'title' => '자유 규칙', 'content' => '본문입니다', 'notice' => 'board',
        ]);

        $free = $this->body($this->get($app, '/boards/free'));
        self::assertStringContainsString('점검 안내', $free);
        self::assertStringContainsString('자유 규칙', $free);
        self::assertStringContainsString('전체 공지', $free);

        $qna = $this->body($this->get($app, '/boards/qna'));
        self::assertStringContainsString('점검 안내', $qna, '전체 공지는 다른 게시판에도 보인다');
        self::assertStringNotContainsString('자유 규칙', $qna, '게시판 공지는 자기 게시판에만');
    }

    #[DataProvider('connectionProvider')]
    public function testGlobalNoticeInAnUnreadableBoardIsHidden(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'staff', 'name' => '내부', 'perm_read' => 'admin',
        ]);
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $app->postService()->create($acl, 'staff', [
            'title' => '내부 전용 공지', 'content' => '본문입니다', 'notice' => 'global',
        ]);

        // 손님으로 자유게시판을 본다. 읽을 수 없는 게시판의 공지는 제목도 새면 안 된다.
        $body = $this->body($this->get($app, '/boards/free'));

        self::assertStringNotContainsString('내부 전용 공지', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testNoticeIsNotRepeatedInTheNormalList(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $app->postService()->create($acl, 'free', [
            'title' => '한 번만 보이는 공지', 'content' => '본문입니다', 'notice' => 'global',
        ]);

        $body = $this->body($this->get($app, '/boards/free'));

        self::assertSame(1, substr_count($body, '한 번만 보이는 공지'));
    }
}
