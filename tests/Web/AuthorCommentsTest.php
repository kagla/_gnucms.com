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

    /**
     * 글이 지워지면 그 글에 달린 댓글도 회원 댓글 목록에서 빠져야 한다.
     * 안 그러면 지워진 글의 제목/댓글 내용이 그대로 보이는데 링크는 404 가 난다.
     */
    #[DataProvider('connectionProvider')]
    public function testCommentsOnDeletedPostsAreHidden(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $keptPost = $app->postService()->create($acl, 'free', ['title' => '남는 글', 'content' => '본문입니다']);
        $goneWasPost = $app->postService()->create($acl, 'free', ['title' => '지워질 글', 'content' => '본문입니다']);
        $memberId = $app->users()->create('writer@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '댓쓴사람');
        $app->users()->verifyEmail($memberId);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'writer@example.com', 'password' => 'member-password-123',
        ]);
        $this->post($app, '/posts/' . $keptPost['id'] . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '남는 댓글입니다',
        ]);
        $this->post($app, '/posts/' . $goneWasPost['id'] . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '지워진 글의 댓글입니다',
        ]);

        $app->postService()->delete($this->adminAcl(), (int) $goneWasPost['id'], null);

        $body = $this->body($this->get($app, '/comments', ['author' => (string) $memberId]));

        self::assertStringContainsString('남는 댓글입니다', $body);
        self::assertStringContainsString('남는 글', $body);
        self::assertStringNotContainsString('지워진 글의 댓글입니다', $body);
        self::assertStringNotContainsString('지워질 글', $body);
    }

    /**
     * 비밀 댓글은 본문 대신 '비밀 댓글' 이라고만 보여야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testSecretCommentBodyIsNotLeaked(array $dbConfig): void
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
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '아무도 몰래 남긴 비밀 댓글 본문',
            'is_secret' => '1',
        ]);

        $body = $this->body($this->get($app, '/comments', ['author' => (string) $memberId]));

        self::assertStringContainsString('비밀 댓글', $body);
        self::assertStringNotContainsString('아무도 몰래 남긴 비밀 댓글 본문', $body);
    }

    /**
     * 읽기 권한이 회원 이상인 게시판의 댓글은 게스트에게 보이면 안 된다.
     * 회원 댓글 목록은 항상 게스트 권한으로 렌더링되기 때문이다.
     */
    #[DataProvider('connectionProvider')]
    public function testCommentsInUnreadableBoardsAreHidden(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $app->boardService()->create($acl, ['board_key' => 'members', 'name' => '회원전용', 'perm_read' => 'member']);
        $publicPost = $app->postService()->create($acl, 'free', ['title' => '공개 글', 'content' => '본문입니다']);
        $membersPost = $app->postService()->create($acl, 'members', ['title' => '회원 전용 글', 'content' => '본문입니다']);
        $memberId = $app->users()->create('writer@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '댓쓴사람');
        $app->users()->verifyEmail($memberId);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'writer@example.com', 'password' => 'member-password-123',
        ]);
        $this->post($app, '/posts/' . $publicPost['id'] . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '공개 게시판 댓글입니다',
        ]);
        $this->post($app, '/posts/' . $membersPost['id'] . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '회원 게시판 댓글입니다',
        ]);

        // 로그아웃해서 게스트로 요청한다 — /comments 는 항상 요청 시점의 신원을 쓴다.
        $this->post($app, '/logout', ['csrf_token' => $_SESSION['csrf_token'] ?? '']);
        $body = $this->body($this->get($app, '/comments', ['author' => (string) $memberId]));

        self::assertStringContainsString('공개 게시판 댓글입니다', $body);
        self::assertStringNotContainsString('회원 게시판 댓글입니다', $body);
    }
}
