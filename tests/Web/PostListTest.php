<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\App;
use ApiBoard\Tests\Support\WebTestCase;

final class PostListTest extends WebTestCase
{
    private function seed(App $app, int $count): void
    {
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판', 'per_page' => 2]);

        for ($i = 1; $i <= $count; $i++) {
            $app->postService()->create($acl, 'free', [
                'title'   => '글 제목 ' . $i,
                'content' => '내용 ' . $i,
            ]);
        }
    }

    /** @dataProvider connectionProvider */
    public function testPostsAreListed(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 1);

        $response = $this->get($app, '/b/free');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('글 제목 1', $this->body($response));
        self::assertStringContainsString('자유게시판', $this->body($response));
    }

    /** @dataProvider connectionProvider */
    public function testSecondPageShowsOlderPosts(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 3);

        $body = $this->body($this->get($app, '/b/free', ['page' => '2']));

        self::assertStringContainsString('글 제목 1', $body);
        self::assertStringNotContainsString('글 제목 3', $body);
    }

    /** @dataProvider connectionProvider */
    public function testSearchFiltersPosts(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 3);

        $body = $this->body($this->get($app, '/b/free', ['q' => '제목 2']));

        self::assertStringContainsString('글 제목 2', $body);
        self::assertStringNotContainsString('글 제목 1', $body);
    }

    /** @dataProvider connectionProvider */
    public function testUnknownBoardRendersNotFoundPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $response = $this->get($app, '/b/없는게시판');

        self::assertSame(404, $response->getStatusCode());
    }

    /** @dataProvider connectionProvider */
    public function testUnreadableBoardRendersUnauthorizedPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'secret',
            'name'      => '관리자전용',
            'perm_read' => 'admin',
        ]);

        $response = $this->get($app, '/b/secret');

        self::assertSame(401, $response->getStatusCode());
    }
}
