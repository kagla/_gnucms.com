<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Support\Clock;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WriteRateLimitWebTest extends WebTestCase
{
    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    #[DataProvider('connectionProvider')]
    public function testRepeatedGuestPostReturns429AndRetryAfter(array $dbConfig): void
    {
        Clock::freeze('2026-09-02 00:00:00');
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings([
            'guest_write_enabled' => '1',
            'post_rate_interval' => '30', 'post_rate_10m' => '0', 'post_rate_day' => '0',
        ]);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'guest',
        ]);
        $this->get($app, '/boards/free/new');
        $base = [
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '본문',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ];

        $first = $this->post($app, '/boards/free/new', $base + ['title' => '첫 글'], [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $second = $this->post($app, '/boards/free/new', $base + ['title' => '두 번째 글'], [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);

        self::assertSame(303, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertSame('30', $second->getHeaderLine('Retry-After'));
        self::assertStringContainsString('너무 빠르게 등록', $this->body($second));
        self::assertSame(1, (int) $app->db()->selectOne('SELECT COUNT(*) AS c FROM posts')['c']);
    }

    #[DataProvider('connectionProvider')]
    public function testRepeatedGuestCommentReturns429(array $dbConfig): void
    {
        Clock::freeze('2026-09-02 00:00:00');
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings([
            'guest_write_enabled' => '1',
            'comment_rate_interval' => '5', 'comment_rate_10m' => '0', 'comment_rate_day' => '0',
        ]);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_comment' => 'guest',
        ]);
        $post = $app->postService()->create($this->adminAcl(), 'free', ['title' => '글', 'content' => '본문']);
        $this->get($app, '/posts/' . $post['id']);
        $base = [
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '댓글',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ];

        $first = $this->post($app, '/posts/' . $post['id'] . '/comments', $base, [
            'REMOTE_ADDR' => '203.0.113.20',
        ]);
        $second = $this->post($app, '/posts/' . $post['id'] . '/comments', $base, [
            'REMOTE_ADDR' => '203.0.113.20',
        ]);

        self::assertSame(303, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertSame('5', $second->getHeaderLine('Retry-After'));
        self::assertSame(1, (int) $app->db()->selectOne('SELECT COUNT(*) AS c FROM comments')['c']);
    }
}
