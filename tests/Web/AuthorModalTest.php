<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** 목록의 글쓴이 모달: 회원만 누를 수 있고, dialog 는 페이지에 하나뿐이다. */
final class AuthorModalTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testMemberAuthorIsButtonAndGuestAuthorIsNot(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['guest_write_enabled' => '1']);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유', 'perm_write' => 'guest']);
        // 회원 글: adminAcl 의 신원이 글쓴이가 된다.
        $app->postService()->create($acl, 'free', ['title' => '회원 글', 'content' => '본문입니다']);
        // 비회원 글
        $this->get($app, '/boards/free/new');
        $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '손님 글', 'content' => '본문입니다',
            'author_name' => '지나가던손님', 'password' => 'guest-pass-123',
        ]);

        $body = $this->body($this->get($app, '/posts'));

        self::assertStringContainsString('data-author-name="관리자"', $body);
        self::assertStringNotContainsString('data-author-name="지나가던손님"', $body);
        self::assertStringContainsString('지나가던손님', $body, '비회원 이름은 글자로는 보인다');
        // dialog 는 페이지에 하나뿐이다.
        self::assertSame(1, substr_count($body, 'id="author-modal"'));
    }

    #[DataProvider('connectionProvider')]
    public function testBoardListAlsoGetsTheModal(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $app->postService()->create($acl, 'free', ['title' => '회원 글', 'content' => '본문입니다']);

        $body = $this->body($this->get($app, '/boards/free'));

        self::assertStringContainsString('data-author-id=', $body);
        self::assertSame(1, substr_count($body, 'id="author-modal"'));
    }
}
