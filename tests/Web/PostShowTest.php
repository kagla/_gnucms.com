<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\Tests\Support\WebTestCase;

final class PostShowTest extends WebTestCase
{
    /** @dataProvider connectionProvider */
    public function testPostAndCommentTreeAreRendered(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key'    => 'free',
            'name'         => '자유게시판',
            'perm_comment' => 'guest',
        ]);
        $post = $app->postService()->create($acl, 'free', ['title' => '제목', 'content' => '본문입니다']);

        $parent = $app->commentService()->create($acl, $post['id'], ['content' => '부모 댓글']);
        $app->commentService()->create($acl, $post['id'], [
            'content'   => '자식 댓글',
            'parent_id' => $parent['id'],
        ]);

        $response = $this->get($app, '/p/' . $post['id']);
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('본문입니다', $body);
        self::assertStringContainsString('부모 댓글', $body);
        self::assertStringContainsString('자식 댓글', $body);
    }

    /** @dataProvider connectionProvider */
    public function testViewCountIncreases(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        $post = $app->postService()->create($acl, 'free', ['title' => '제목', 'content' => '본문']);

        $this->get($app, '/p/' . $post['id']);

        self::assertSame(1, (int) $app->posts()->find((int) $post['id'])['view_count']);
    }

    /** @dataProvider connectionProvider */
    public function testHtmlInContentIsEscaped(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        $post = $app->postService()->create($acl, 'free', [
            'title'   => '제목',
            'content' => '<script>alert(1)</script>',
        ]);

        $body = $this->body($this->get($app, '/p/' . $post['id']));

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    /** @dataProvider connectionProvider */
    public function testMissingPostRendersNotFoundPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        self::assertSame(404, $this->get($app, '/p/99999')->getStatusCode());
    }

    /**
     * 비밀글 판정은 게시판 읽기 권한과 별개다. 비회원(게스트)이 남의 비밀글을 열면
     * 403 이어야 한다 — 로그인해도 소용없다는 뜻. board.perm_read 자체는 guest 라서
     * 게시판 읽기는 통과하고, PostService::loadForRead() 의 비밀글 검사가 막는다.
     *
     * @dataProvider connectionProvider
     */
    public function testSecretPostIsDeniedToGuestWith403(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key'  => 'free',
            'name'       => '자유게시판',
            'use_secret' => true,
        ]);
        $post = $app->postService()->create($acl, 'free', [
            'title'     => '비밀글',
            'content'   => '아무나 보면 안 됨',
            'is_secret' => true,
        ]);

        $response = $this->get($app, '/p/' . $post['id']);

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * perm_read = admin 인 게시판은 게스트에게 401 이어야 한다 — 로그인하면
     * 될 수도 있다는 뜻. 이 판정은 BoardService::getEntity() -> Acl::assertCanRead()
     * 에서 나온다.
     *
     * @dataProvider connectionProvider
     */
    public function testPostInAdminOnlyBoardIsDeniedToGuestWith401(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'secret',
            'name'      => '관리자전용',
            'perm_read' => 'admin',
        ]);
        $post = $app->postService()->create($acl, 'secret', ['title' => '제목', 'content' => '본문']);

        $response = $this->get($app, '/p/' . $post['id']);

        self::assertSame(401, $response->getStatusCode());
    }
}
