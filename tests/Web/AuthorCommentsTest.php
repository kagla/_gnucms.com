<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AuthorCommentsTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testAllCommentsAreListedAndLinkedFromAllPosts(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $post = $app->postService()->create($acl, 'free', ['title' => '전체 댓글 대상 글', 'content' => '본문입니다']);
        $app->commentService()->create($acl, (int) $post['id'], ['content' => '전체 목록에 보이는 댓글']);

        $posts = $this->body($this->get($app, '/posts'));
        self::assertStringContainsString('href="/comments"', $posts);
        self::assertStringContainsString('전체 댓글', $posts);

        $comments = $this->body($this->get($app, '/comments'));
        self::assertStringContainsString('<h1>전체 댓글</h1>', $comments);
        self::assertStringContainsString('href="/posts"', $comments);
        self::assertStringContainsString('전체 글', $comments);
        self::assertStringContainsString('전체 목록에 보이는 댓글', $comments);
        self::assertStringContainsString('전체 댓글 대상 글', $comments);
        self::assertStringContainsString('관리자', $comments);
    }

    #[DataProvider('connectionProvider')]
    public function testAllCommentsHideSecretPostsAndMemberOnlyBoardsFromGuests(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유', 'use_secret' => true]);
        $app->boardService()->create($acl, ['board_key' => 'members', 'name' => '회원전용', 'perm_read' => 'member']);

        $publicPost = $app->postService()->create($acl, 'free', [
            'title' => '공개 글', 'content' => '본문입니다',
        ]);
        $secretPost = $app->postService()->create($acl, 'free', [
            'title' => '감춰야 할 비밀글', 'content' => '본문입니다', 'is_secret' => '1',
        ]);
        $memberPost = $app->postService()->create($acl, 'members', [
            'title' => '감춰야 할 회원글', 'content' => '본문입니다',
        ]);
        $app->commentService()->create($acl, (int) $publicPost['id'], ['content' => '보여야 할 공개 댓글']);
        $app->commentService()->create($acl, (int) $secretPost['id'], ['content' => '감춰야 할 비밀글 댓글']);
        $app->commentService()->create($acl, (int) $memberPost['id'], ['content' => '감춰야 할 회원글 댓글']);

        // 로그인하지 않은 요청이므로 공개 글의 댓글만 볼 수 있어야 한다.
        $comments = $this->body($this->get($app, '/comments'));

        self::assertStringContainsString('보여야 할 공개 댓글', $comments);
        self::assertStringNotContainsString('감춰야 할 비밀글', $comments);
        self::assertStringNotContainsString('감춰야 할 회원글', $comments);
    }

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
     * 비밀글에 달린 댓글은 회원 댓글 목록에서 빠져야 한다.
     * 게스트는 /posts/{id} 에서 비밀글 자체를 403 으로 못 보는데, /comments?author= 가
     * 그 안의 댓글 본문을 그대로 흘리면 글은 못 보면서 답만 보이는 구멍이 생긴다.
     */
    #[DataProvider('connectionProvider')]
    public function testCommentsOnSecretPostsAreHidden(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유', 'use_secret' => true]);
        $memberId = $app->users()->create('writer@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '댓쓴사람');
        $app->users()->verifyEmail($memberId);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'writer@example.com', 'password' => 'member-password-123',
        ]);

        // 비밀글은 글쓴이 본인이나 관리자만 댓글을 달 수 있으므로, 두 글 모두 이 회원이 쓴다.
        $normalCreate = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '평범한 글', 'content' => '본문입니다',
        ]);
        preg_match('#/posts/(\d+)#', $normalCreate->getHeaderLine('Location'), $m1);
        $normalPostId = (int) $m1[1];

        $secretCreate = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '비밀 문의', 'content' => '본문입니다', 'is_secret' => '1',
        ]);
        preg_match('#/posts/(\d+)#', $secretCreate->getHeaderLine('Location'), $m2);
        $secretPostId = (int) $m2[1];

        $this->post($app, '/posts/' . $normalPostId . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '평범한 댓글입니다',
        ]);
        $this->post($app, '/posts/' . $secretPostId . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '비밀글에 남긴 댓글 본문',
        ]);

        // 로그아웃해서 게스트로 요청한다 — /comments 는 항상 요청 시점의 신원을 쓴다.
        $this->post($app, '/logout', ['csrf_token' => $_SESSION['csrf_token'] ?? '']);
        $body = $this->body($this->get($app, '/comments', ['author' => (string) $memberId]));

        self::assertStringContainsString('평범한 댓글입니다', $body);
        self::assertStringNotContainsString('비밀글에 남긴 댓글 본문', $body);
    }

    /**
     * 읽기 권한이 회원 이상인 게시판의 댓글은 게스트에게 보이면 안 된다.
     * 회원 댓글 목록은 요청자 권한으로 그려지므로, 로그아웃한 게스트로 요청하면
     * 회원 전용 게시판의 댓글은 그 권한 기준으로 걸러진다.
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

    /**
     * 차단된 회원은 없는 회원과 같게 다뤄야 한다.
     * CommentService::listByAuthor() 는 status === 'active' 가 아니면 회원을 못 찾은
     * 것으로 치는데, 댓글 목록 경로에는 이를 확인하는 테스트가 없었다.
     */
    #[DataProvider('connectionProvider')]
    public function testBlockedAuthorIsTreatedAsUnknown(array $dbConfig): void
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
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '차단 전에 남긴 댓글입니다',
        ]);

        $app->users()->setStatus($memberId, 'blocked');

        $body = $this->body($this->get($app, '/comments', ['author' => (string) $memberId]));

        self::assertStringContainsString('회원을 찾을 수 없습니다', $body);
        self::assertStringNotContainsString('차단 전에 남긴 댓글입니다', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testImageOnlyCommentShowsAPlaceholderInsteadOfAnEmptyLine(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $post = $app->postService()->create($acl, 'free', ['title' => '사진 글', 'content' => '본문입니다']);
        $memberId = $app->users()->create('shot@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '사진쓴사람');
        $app->users()->verifyEmail($memberId);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'shot@example.com', 'password' => 'member-password-123',
        ]);
        // 사진만 있는 댓글. 태그를 걷으면 글자가 하나도 남지 않는다.
        $this->post($app, '/posts/' . $post['id'] . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'],
            'content' => '<p><img alt="사진.jpg" src="/media/editor/2026/08/abc.jpg"></p>',
        ]);

        $body = $this->body($this->get($app, '/comments', ['author' => (string) $memberId]));

        self::assertStringContainsString('사진', $body);
        self::assertStringContainsString('사진 글', $body, '어느 글에 남긴 댓글인지도 함께 보인다');
        // 빈 줄이 아니라 무엇인지 말해 주는 자리표시가 있어야 한다.
        self::assertStringNotContainsString('<span class="author-comment-text"></span>', $body);
    }
}
