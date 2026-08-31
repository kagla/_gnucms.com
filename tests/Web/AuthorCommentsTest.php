<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AuthorCommentsTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testMembersCommentsAreListedWithTheirPostTitles(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $post = $app->postService()->create($acl, 'free', ['title' => '이야기 글', 'content' => '본문입니다']);
        $memberId = $app->users()->create('writer@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '댓쓴사람');
        $app->users()->verifyEmail($memberId);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'writer@example.com', 'password' => 'member-password-123',
        ]);
        $this->post($app, '/posts/' . $post['id'] . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '반가운 댓글입니다',
        ]);

        $body = $this->body($this->get($app, '/comments', ['author' => (string) $memberId]));

        self::assertStringContainsString('댓쓴사람 님의 댓글', $body);
        self::assertStringContainsString('반가운 댓글입니다', $body);
        self::assertStringContainsString('이야기 글', $body);
        self::assertStringContainsString('#comment-', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testDeletedCommentsAndUnknownAuthorAreHandled(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $post = $app->postService()->create($acl, 'free', ['title' => '이야기 글', 'content' => '본문입니다']);
        $memberId = $app->users()->create('writer@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '댓쓴사람');
        $app->users()->verifyEmail($memberId);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'writer@example.com', 'password' => 'member-password-123',
        ]);
        $this->post($app, '/posts/' . $post['id'] . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '지울 댓글입니다',
        ]);
        $comment = $app->comments()->findByPost($post['id'])[0];
        $app->comments()->softDelete((int) $comment['id']);

        $mine = $this->body($this->get($app, '/comments', ['author' => (string) $memberId]));
        self::assertStringNotContainsString('지울 댓글입니다', $mine);
        self::assertStringContainsString('남긴 댓글이 없습니다', $mine);

        $unknown = $this->get($app, '/comments', ['author' => '99999']);
        self::assertSame(200, $unknown->getStatusCode());
        self::assertStringContainsString('회원을 찾을 수 없습니다', $this->body($unknown));
    }
}
