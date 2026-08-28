<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;

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

        $response = $this->get($app, '/posts/' . $post['id']);
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

        $this->get($app, '/posts/' . $post['id']);

        self::assertSame(1, (int) $app->posts()->find((int) $post['id'])['view_count']);
    }

    /**
     * 본문은 편집기가 보내는 HTML 을 허용한다. 대신 저장과 출력 두 곳에서 정화하므로
     * 서식은 살아남고 스크립트·이벤트 속성은 사라져야 한다.
     *
     * @dataProvider connectionProvider
     */
    public function testDangerousHtmlIsStrippedWhileFormattingSurvives(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        $post = $app->postService()->create($acl, 'free', [
            'title'   => '제목',
            'content' => '<p>안녕 <strong>굵게</strong></p>'
                . '<script>alert(1)</script>'
                . '<img src="x" onerror="alert(1)">'
                . '<a href="javascript:alert(1)">링크</a>',
        ]);

        $body = $this->body($this->get($app, '/posts/' . $post['id']));

        self::assertStringContainsString('<strong>굵게</strong>', $body);
        // 레이아웃 자체가 <script> 를 쓰므로 태그가 아니라 심어 둔 값으로 판단한다.
        self::assertStringNotContainsString('alert(1)', $body);
        self::assertStringNotContainsString('onerror', $body);
        self::assertStringNotContainsString('javascript:', $body);
    }

    /** 평문으로 저장된 옛 글도 줄바꿈을 지키며 그대로 보여야 한다. */
    #[\PHPUnit\Framework\Attributes\DataProvider('connectionProvider')]
    public function testPlainTextPostStillRendersWithLineBreaks(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        $post = $app->postService()->create($acl, 'free', [
            'title'   => '제목',
            'content' => "첫 줄\n둘째 줄",
        ]);

        $body = $this->body($this->get($app, '/posts/' . $post['id']));

        self::assertStringContainsString('첫 줄', $body);
        self::assertStringContainsString('<br', $body);
    }

    /** @dataProvider connectionProvider */
    public function testMissingPostRendersNotFoundPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        self::assertSame(404, $this->get($app, '/posts/99999')->getStatusCode());
    }

    /** @dataProvider connectionProvider */
    public function testLegacyPostUrlRedirectsPermanentlyToCanonicalUrl(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        $response = $this->get($app, '/p/2', ['from' => 'bookmark']);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/posts/2?from=bookmark', $response->getHeaderLine('Location'));
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

        $response = $this->get($app, '/posts/' . $post['id']);

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

        $response = $this->get($app, '/posts/' . $post['id']);

        self::assertSame(401, $response->getStatusCode());
    }
}
