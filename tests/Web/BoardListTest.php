<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\Tests\Support\WebTestCase;

final class BoardListTest extends WebTestCase
{
    /** @dataProvider connectionProvider */
    public function testReadableBoardsAreListed(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free',
            'name'      => '자유게시판',
        ]);

        $response = $this->get($app, '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('자유게시판', $this->body($response));
    }

    /** @dataProvider connectionProvider */
    public function testUnreadableBoardIsHidden(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'secret',
            'name'      => '관리자전용',
            'perm_read' => 'admin',
        ]);

        $body = $this->body($this->get($app, '/'));

        self::assertStringNotContainsString('관리자전용', $body);
    }

    /** @dataProvider connectionProvider */
    public function testEmptyStateIsShown(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        self::assertStringContainsString('게시판이 없습니다', $this->body($this->get($app, '/')));
    }
}
