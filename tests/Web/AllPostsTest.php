<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AllPostsTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testAllPostsListsOnlyReadableBoardsAndSearches(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        $app->boardService()->create($acl, ['board_key' => 'secret', 'name' => '관리자전용', 'perm_read' => 'admin']);
        $app->postService()->create($acl, 'free', ['title' => '공개 글 하나', 'content' => '내용']);
        $app->postService()->create($acl, 'free', ['title' => '공개 글 둘', 'content' => '사과']);
        $app->postService()->create($acl, 'secret', ['title' => '비공개 글', 'content' => '내용']);

        $response = $this->get($app, '/posts');
        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);
        self::assertStringContainsString('공개 글 하나', $body);
        self::assertStringContainsString('공개 글 둘', $body);
        self::assertStringNotContainsString('비공개 글', $body, '읽을 수 없는 게시판의 글은 안 나온다');
        self::assertStringContainsString('자유게시판', $body, '게시판 이름 배지가 붙는다');
        self::assertStringContainsString('aria-current="page"', $body);
        self::assertMatchesRegularExpression('#href="/posts"[^>]*>전체 글#', $body, '상단 탭에 전체 글이 있다');

        $found = $this->body($this->get($app, '/posts', ['q' => '사과']));
        self::assertStringContainsString('공개 글 둘', $found);
        self::assertStringNotContainsString('공개 글 하나', $found);
        self::assertStringContainsString('검색 결과 1건', $found);
    }

    #[DataProvider('connectionProvider')]
    public function testAllPostsPaginates(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        for ($i = 1; $i <= 21; $i++) {
            $app->postService()->create($acl, 'free', ['title' => '글 ' . $i, 'content' => '내용']);
        }
        $first = $this->body($this->get($app, '/posts'));
        self::assertStringContainsString('글 21', $first);
        self::assertStringNotContainsString('>글 1<', $first, '21번째 글은 둘째 쪽으로 넘어간다');
        self::assertStringContainsString('href="/posts?page=2"', $first);
        $second = $this->body($this->get($app, '/posts', ['page' => '2']));
        self::assertStringContainsString('>글 1 ', $second);
    }

    #[DataProvider('connectionProvider')]
    public function testAuthorFilterShowsOnlyThatMembersPosts(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        // adminAcl() 의 회원 번호가 '1' 로 고정돼 있다. 빈 DB에서 첫 회원도 1번을
        // 받으므로, 자리를 하나 채워 두지 않으면 관리자 글의 author_id 와 회원 번호가
        // 우연히 같아져 거르기 테스트가 뜻대로 되지 않는다.
        $app->users()->create('placeholder@example.com', password_hash('placeholder-password-123', PASSWORD_DEFAULT), '자리채우기');
        $memberId = $app->users()->create('writer@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '글쓴사람');
        $app->users()->verifyEmail($memberId);

        $app->postService()->create($acl, 'free', ['title' => '관리자 글', 'content' => '본문입니다']);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'writer@example.com', 'password' => 'member-password-123',
        ]);
        $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '회원 글', 'content' => '본문입니다',
        ]);

        $body = $this->body($this->get($app, '/posts', ['author' => (string) $memberId]));

        self::assertStringContainsString('회원 글', $body);
        self::assertStringNotContainsString('관리자 글', $body);
        self::assertStringContainsString('글쓴사람 님의 글', $body);
        // 검색창에서 다시 찾아도 글쓴이 거르기가 풀리면 안 된다 — 검색 폼이 author 를 함께 실어 날라야 한다.
        self::assertMatchesRegularExpression(
            '#<form class="header-search"[^>]*>.*?<input type="hidden" name="author" value="' . $memberId . '">#s',
            $body
        );
    }

    /**
     * 차단된 회원은 없는 회원과 같이 다룬다. 안 그러면 차단된 회원의 번호를 아는 사람이
     * 여전히 "○○ 님의 글" 로 그 사람 글만 모아 볼 수 있다.
     */
    #[DataProvider('connectionProvider')]
    public function testBlockedAuthorFallsBackToTheWholeList(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $app->postService()->create($acl, 'free', ['title' => '관리자 글', 'content' => '본문입니다']);
        $memberId = $app->users()->create('writer@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '차단될사람');
        $app->users()->verifyEmail($memberId);
        $app->users()->setStatus($memberId, 'blocked');

        $body = $this->body($this->get($app, '/posts', ['author' => (string) $memberId]));

        self::assertStringContainsString('관리자 글', $body);
        self::assertStringNotContainsString('님의 글', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testUnknownAuthorFallsBackToTheWholeList(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $app->postService()->create($acl, 'free', ['title' => '관리자 글', 'content' => '본문입니다']);

        $body = $this->body($this->get($app, '/posts', ['author' => '99999']));

        self::assertStringContainsString('관리자 글', $body);
        self::assertStringNotContainsString('님의 글', $body);
    }
}
