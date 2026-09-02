<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Auth\Acl;
use GnuCms\Auth\Identity;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/** 댓글 쓰기(라우트·폼·권한)와 본문 편집기 이미지 업로드. */
final class CommentWriteTest extends WebTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    #[DataProvider('connectionProvider')]
    public function testGuestCanWriteCommentWhenBoardAllowsIt(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $postId = $this->seed($app, 'guest');

        $this->get($app, '/posts/' . $postId);
        $response = $this->post($app, '/posts/' . $postId . '/comments', [
            'csrf_token'  => $_SESSION['csrf_token'] ?? '',
            'author_name' => '지나가던 사람',
            'password'    => 'comment-pass-1',
            'content'     => '반갑습니다',
        ], ['REMOTE_ADDR' => '198.51.100.42']);

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('#comments', $response->getHeaderLine('Location'));

        $body = $this->body($this->get($app, '/posts/' . $postId));
        self::assertStringContainsString('반갑습니다', $body);
        self::assertStringContainsString('지나가던 사람', $body);
        self::assertStringContainsString('198.51.xxx.42', $body);
        self::assertStringNotContainsString('198.51.100.42', $body);
        self::assertMatchesRegularExpression('/<time[^>]*>[^<]+<\/time>\s*<span class="author-ip">198\.51\.xxx\.42<\/span>/', $body);
        $stored = $app->db()->selectOne('SELECT author_ip FROM ' . $app->db()->table('comments') . ' WHERE post_id = ?', [$postId]);
        self::assertSame('198.51.100.42', $stored['author_ip']);
    }

    /** 권한이 없으면 폼 자체가 나오지 않아야 한다. 눌러도 막히는 것보다 낫다. */
    #[DataProvider('connectionProvider')]
    public function testCommentFormIsHiddenWithoutPermission(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $postId = $this->seed($app, 'member');

        $body = $this->body($this->get($app, '/posts/' . $postId));

        self::assertStringNotContainsString('id="comment-form"', $body);
        self::assertStringContainsString('로그인', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testValidationErrorRedisplaysPostWithMessage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $postId = $this->seed($app, 'guest');

        $this->get($app, '/posts/' . $postId);
        $response = $this->post($app, '/posts/' . $postId . '/comments', [
            'csrf_token'  => $_SESSION['csrf_token'] ?? '',
            'author_name' => '',
            'password'    => '',
            'content'     => '',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('id="comment-form"', $this->body($response));
        self::assertStringContainsString('validator-hint', $this->body($response));
    }

    #[DataProvider('connectionProvider')]
    public function testCsrfTokenIsRequired(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $postId = $this->seed($app, 'guest');

        $response = $this->post($app, '/posts/' . $postId . '/comments', [
            'csrf_token'  => 'wrong',
            'author_name' => '아무개',
            'password'    => 'pass-1234',
            'content'     => '내용',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testReplyIsNestedUnderItsParent(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $postId = $this->seed($app, 'guest');
        $parent = $app->commentService()->create($this->adminAcl(), $postId, ['content' => '부모 댓글']);

        $this->get($app, '/posts/' . $postId);
        $this->post($app, '/posts/' . $postId . '/comments', [
            'csrf_token'  => $_SESSION['csrf_token'] ?? '',
            'author_name' => '답글쓴이',
            'password'    => 'pass-1234',
            'content'     => '답글입니다',
            'parent_id'   => (string) $parent['id'],
        ]);

        $body = $this->body($this->get($app, '/posts/' . $postId));

        self::assertStringContainsString('comment-thread-sub', $body);
        self::assertStringContainsString('답글입니다', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCannotWriteASecretCommentOrReply(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'secret-reply', 'name' => '비밀답글 제한',
            'perm_write' => 'guest', 'perm_comment' => 'guest', 'use_secret' => '1',
        ]);
        $post = $app->postService()->create($this->adminAcl(), 'secret-reply', [
            'title' => '글', 'content' => '본문',
        ]);
        $parent = $app->commentService()->create($this->adminAcl(), (int) $post['id'], [
            'content' => '부모 댓글',
        ]);
        $this->get($app, '/posts/' . $post['id']);

        $response = $this->post($app, '/posts/' . $post['id'] . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'],
            'author_name' => '비회원',
            'password' => 'comment-pass-1',
            'content' => '비밀 댓글 시도',
            'is_secret' => '1',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('비회원은 댓글과 답글을 비밀로 작성할 수 없습니다', $this->body($response));
        self::assertStringNotContainsString('type="checkbox" name="is_secret"', $this->body($response));

        $reply = $this->post($app, '/posts/' . $post['id'] . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'],
            'author_name' => '비회원',
            'password' => 'comment-pass-1',
            'content' => '비밀 답글 시도',
            'parent_id' => (string) $parent['id'],
            'is_secret' => '1',
        ]);
        self::assertSame(422, $reply->getStatusCode());
    }

/**
     * 답글의 답글에 제한이 없어야 한다. 중첩 파셜에 권한 값을 넘기지 않으면
     * 두 번째 단계부터 답글 버튼이 사라진다 — 실제로 그렇게 빠뜨린 적이 있다.
     */
    #[DataProvider('connectionProvider')]
    public function testRepliesNestWithoutDepthLimit(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $postId = $this->seed($app, 'guest');

        $parent = null;
        for ($depth = 1; $depth <= 4; $depth++) {
            $comment = $app->commentService()->create($this->adminAcl(), $postId, array_filter([
                'content'   => '깊이 ' . $depth,
                'parent_id' => $parent,
            ]));
            self::assertSame($depth - 1, $comment['depth']);
            $parent = $comment['id'];
        }

        $body = $this->body($this->get($app, '/posts/' . $postId));

        for ($depth = 1; $depth <= 4; $depth++) {
            self::assertStringContainsString('깊이 ' . $depth, $body);
        }
        // 클래스 속성만 센다. 답글 폼을 옮기는 스크립트에도 같은 낱말이 나온다.
        self::assertSame(3, substr_count($body, 'class="comment-thread comment-thread-sub"'));
        // 모든 단계에 답글 버튼이 있어야 계속 이어 달 수 있다.
        self::assertSame(4, substr_count($body, 'data-reply='));
    }

    /** 댓글도 HTML 을 허용하되 위험한 것은 지운다. */
    #[DataProvider('connectionProvider')]
    public function testCommentHtmlIsSanitized(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $postId = $this->seed($app, 'guest');

        $this->get($app, '/posts/' . $postId);
        $this->post($app, '/posts/' . $postId . '/comments', [
            'csrf_token'  => $_SESSION['csrf_token'] ?? '',
            'author_name' => '아무개',
            'password'    => 'pass-1234',
            'content'     => '<strong>굵게</strong><script>alert(9)</script>',
        ]);

        $body = $this->body($this->get($app, '/posts/' . $postId));

        self::assertStringContainsString('<strong>굵게</strong>', $body);
        self::assertStringNotContainsString('alert(9)', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testSecretCommentShowsLockImmediatelyBeforeItsContent(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $postId = $this->seed($app, 'guest');
        $app->commentService()->create($this->adminAcl(), $postId, [
            'content' => '비밀스러운 댓글',
            'is_secret' => '1',
        ]);

        $body = $this->body($this->get($app, '/posts/' . $postId));

        self::assertMatchesRegularExpression(
            '#chat-bubble-secret[^>]*>\s*<span class="chat-lock".*?</span>\s*<div class="chat-bubble-content">#s',
            $body
        );
    }

    #[DataProvider('connectionProvider')]
    public function testLegacyGuestSecretCommentAuthorCanReopenItWithCommentPassword(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $postId = $this->seed($app, 'guest');
        $this->get($app, '/posts/' . $postId);
        $created = $this->post($app, '/posts/' . $postId . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'],
            'author_name' => '비회원 댓글쓴이',
            'password' => 'comment-pass-1',
            'content' => '작성자만 볼 비밀 댓글',
        ]);
        self::assertSame(303, $created->getStatusCode());

        $comments = $app->commentService()->listComments($this->adminAcl(), $postId, null);
        $commentId = (int) $comments[0]['id'];
        // 새 비회원 비밀댓글은 금지하지만, 기존에 저장된 데이터의 열람 길은 유지한다.
        $app->db()->execute(
            'UPDATE ' . $app->db()->q('comments') . ' SET is_secret = 1 WHERE id = ?',
            [$commentId]
        );

        // 작성 직후에는 같은 브라우저를 세션 소유권으로 알아본다.
        $ownView = $this->body($this->get($app, '/posts/' . $postId));
        self::assertStringContainsString('작성자만 볼 비밀 댓글', $ownView);

        // 새 브라우저처럼 소유권 세션을 지우면 가리고, 비밀번호 확인 길을 내준다.
        session_start();
        unset($_SESSION['secret_comments']);
        session_write_close();
        $masked = $this->body($this->get($app, '/posts/' . $postId));
        self::assertStringContainsString('비밀 댓글입니다.', $masked);
        self::assertStringContainsString('/comments/' . $commentId . '/password', $masked);

        $passwordForm = $this->get($app, '/comments/' . $commentId . '/password');
        self::assertSame(200, $passwordForm->getStatusCode());
        self::assertStringContainsString('댓글 작성 비밀번호 또는 비회원 원글의 비밀번호', $this->body($passwordForm));

        $unlocked = $this->post($app, '/comments/' . $commentId . '/password', [
            'csrf_token' => $_SESSION['csrf_token'],
            'password' => 'comment-pass-1',
        ]);
        self::assertSame(303, $unlocked->getStatusCode());
        self::assertStringContainsString('#comment-' . $commentId, $unlocked->getHeaderLine('Location'));
        self::assertStringContainsString(
            '작성자만 볼 비밀 댓글',
            $this->body($this->get($app, '/posts/' . $postId))
        );
    }

    #[DataProvider('connectionProvider')]
    public function testGuestPostAuthorCanSeeSecretCommentsWithPostOwnership(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['guest_write_enabled' => '1']);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'guest-post',
            'name' => '비회원 원글',
            'perm_write' => 'guest',
            'perm_comment' => 'guest',
        ]);
        $this->get($app, '/boards/guest-post/new');
        $created = $this->post($app, '/boards/guest-post/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'author_name' => '비회원 원글쓴이',
            'password' => 'post-pass-123',
            'title' => '비회원 원글',
            'content' => '본문',
        ]);
        self::assertSame(303, $created->getStatusCode());
        preg_match('#/posts/(\d+)#', $created->getHeaderLine('Location'), $matches);
        $postId = (int) ($matches[1] ?? 0);

        $comment = $app->commentService()->create(new Acl(Identity::guest()), $postId, [
            'content' => '원글쓴이에게만 보일 댓글',
            'author_name' => '비회원 댓글쓴이',
            'password' => 'comment-owner-123',
        ]);
        // 과거 비회원 비밀댓글 데이터도 원글 작성자가 확인할 수 있어야 한다.
        $app->db()->execute(
            'UPDATE ' . $app->db()->q('comments') . ' SET is_secret = 1 WHERE id = ?',
            [(int) $comment['id']]
        );

        $postAuthorView = $this->body($this->get($app, '/posts/' . $postId));
        self::assertStringContainsString('원글쓴이에게만 보일 댓글', $postAuthorView);
        self::assertStringContainsString('data-guest-edit="' . $comment['id'] . '"', $postAuthorView);
        self::assertSame(404, $this->get($app, '/comments/' . $comment['id'] . '/edit')->getStatusCode(),
            '원글 작성자는 내용을 볼 수 있어도 다른 사람의 댓글 수정 화면에는 들어가면 안 된다');

        // 다른 브라우저에서는 원글 비밀번호로 다시 원글 작성자임을 확인한다.
        session_start();
        unset($_SESSION['secret_posts']);
        session_write_close();
        self::assertStringContainsString(
            '비밀 댓글입니다.',
            $this->body($this->get($app, '/posts/' . $postId))
        );
        $unlocked = $this->post($app, '/comments/' . $comment['id'] . '/password', [
            'csrf_token' => $_SESSION['csrf_token'],
            'password' => 'post-pass-123',
        ]);
        self::assertSame(303, $unlocked->getStatusCode());
        self::assertStringContainsString(
            '원글쓴이에게만 보일 댓글',
            $this->body($this->get($app, '/posts/' . $postId))
        );

        // 수정은 원글 비밀번호가 아니라 댓글 비밀번호로 별도 확인해야 한다.
        $wrongOwner = $this->post($app, '/comments/' . $comment['id'] . '/ownership', [
            'csrf_token' => $_SESSION['csrf_token'],
            'password' => 'post-pass-123',
        ]);
        self::assertSame(422, $wrongOwner->getStatusCode());
        self::assertStringContainsString('비밀번호가 올바르지 않습니다', $this->body($wrongOwner));

        $verifiedOwner = $this->post($app, '/comments/' . $comment['id'] . '/ownership', [
            'csrf_token' => $_SESSION['csrf_token'],
            'password' => 'comment-owner-123',
        ]);
        self::assertSame(200, $verifiedOwner->getStatusCode());
        self::assertStringContainsString('edit_comment=' . $comment['id'], $this->body($verifiedOwner));
        self::assertSame(200, $this->get($app, '/comments/' . $comment['id'] . '/edit')->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testMemberCanWriteSecretCommentsAndReplies(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        // 이 테스트는 연속 작성 제한이 아니라 비밀 댓글의 부모·답글 관계를 검증한다.
        $app->cms()->saveSettings([
            'comment_rate_interval' => '0', 'comment_rate_10m' => '0', 'comment_rate_day' => '0',
        ]);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'members-secret', 'name' => '회원 비밀댓글',
            'perm_write' => 'guest', 'perm_comment' => 'guest', 'use_secret' => '1',
        ]);
        $post = $app->postService()->create($this->adminAcl(), 'members-secret', [
            'title' => '글', 'content' => '본문',
        ]);
        $member = new Acl(Identity::user('42', '회원', false));
        $comment = $app->commentService()->create($member, (int) $post['id'], [
            'content' => '회원 비밀댓글', 'is_secret' => '1',
        ]);
        $reply = $app->commentService()->create($member, (int) $post['id'], [
            'content' => '회원 비밀답글', 'parent_id' => $comment['id'], 'is_secret' => '1',
        ]);

        self::assertTrue($comment['is_secret']);
        self::assertTrue($reply['is_secret']);
    }

    #[DataProvider('connectionProvider')]
    public function testSecretReplyIsVisibleToParentAuthorAndPostAuthor(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'secret-tree', 'name' => '비밀답글',
            'perm_write' => 'guest', 'perm_comment' => 'guest', 'use_secret' => '1',
        ]);
        $postAuthor = new Acl(Identity::user('40', '원글쓴이', false));
        $parentAuthor = new Acl(Identity::user('41', '댓글쓴이', false));
        $replyAuthor = new Acl(Identity::user('42', '답글쓴이', false));
        $outsider = new Acl(Identity::user('43', '다른 회원', false));
        $post = $app->postService()->create($postAuthor, 'secret-tree', ['title' => '글', 'content' => '본문']);
        $parent = $app->commentService()->create($parentAuthor, (int) $post['id'], ['content' => '부모 댓글']);
        $app->commentService()->create($replyAuthor, (int) $post['id'], [
            'content' => '부모에게 보이는 비밀답글', 'parent_id' => $parent['id'], 'is_secret' => '1',
        ]);

        self::assertStringContainsString('부모에게 보이는 비밀답글', $app->commentService()->listComments($parentAuthor, (int) $post['id'], null)[0]['children'][0]['content']);
        self::assertStringContainsString('부모에게 보이는 비밀답글', $app->commentService()->listComments($postAuthor, (int) $post['id'], null)[0]['children'][0]['content']);
        self::assertStringContainsString('부모에게 보이는 비밀답글', $app->commentService()->listComments($replyAuthor, (int) $post['id'], null)[0]['children'][0]['content']);
        self::assertSame('비밀 댓글입니다.', $app->commentService()->listComments($outsider, (int) $post['id'], null)[0]['children'][0]['content']);
    }

    #[DataProvider('connectionProvider')]
    public function testEditorImageUploadFollowsBoardPermission(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['guest_write_enabled' => '1']);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'open', 'name' => '열린게시판', 'perm_write' => 'guest',
        ]);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'closed', 'name' => '닫힌게시판', 'perm_write' => 'admin',
        ]);

        $this->get($app, '/login');
        $token = (string) ($_SESSION['csrf_token'] ?? '');
        $key = str_repeat('a', 32);
        $query = '?csrf_token=' . rawurlencode($token) . '&image_key=' . $key;

        $ok = $this->upload($app, '/boards/open/editor/images' . $query, ['upload' => $this->png()]);
        self::assertSame(200, $ok->getStatusCode());
        $payload = json_decode($this->body($ok), true);
        self::assertSame(1, $payload['uploaded'] ?? null);
        self::assertStringContainsString('/media/editor/' . $key . '/', (string) ($payload['url'] ?? ''));

        $failedUpload = new UploadedFile('', '사진.png', 'image/png', null, UPLOAD_ERR_CANT_WRITE);
        $failed = $this->upload($app, '/boards/open/editor/images' . $query, ['upload' => $failedUpload]);
        self::assertSame(422, $failed->getStatusCode());
        self::assertSame(
            '서버가 이미지 파일을 저장하지 못했습니다. 저장 공간과 폴더 권한을 확인해 주세요.',
            json_decode($this->body($failed), true)['error']['message'] ?? null
        );

        // Acl 은 게스트에게 401(로그인 필요), 로그인했는데 권한이 없으면 403 을 준다.
        // 어느 쪽이든 편집기가 JSON 200 으로 성공처럼 받아서는 안 된다.
        $denied = $this->upload(
            $app,
            '/boards/closed/editor/images' . $query,
            ['upload' => $this->png()],
            ['Accept' => 'application/json']
        );
        self::assertSame(401, $denied->getStatusCode());
        self::assertStringContainsString('application/json', $denied->getHeaderLine('Content-Type'));
        self::assertSame('로그인이 필요합니다.', json_decode($this->body($denied), true)['error']['message'] ?? null);
    }

    private function png(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cmt');
        file_put_contents($tmp, base64_decode(self::PNG));

        return new UploadedFile($tmp, '사진.png', 'image/png', filesize($tmp) ?: null, UPLOAD_ERR_OK);
    }

    private function seed(App $app, string $permComment): int
    {
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'free', 'name' => '자유게시판', 'perm_comment' => $permComment,
        ]);
        $post = $app->postService()->create($acl, 'free', ['title' => '글', 'content' => '본문']);

        return (int) $post['id'];
    }

    #[DataProvider('connectionProvider')]
    public function testEditorEmptyShellIsRejectedAsEmptyContent(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $postId = $this->seed($app, 'guest');
        $this->get($app, '/posts/' . $postId);

        // CKEditor 는 빈 입력에도 <p><br></p> 같은 껍데기를 남긴다. 필수 검사를 통과하면 안 된다.
        $response = $this->post($app, '/posts/' . $postId . '/comments', [
            'csrf_token'  => $_SESSION['csrf_token'] ?? '',
            'author_name' => '지나가던 사람',
            'password'    => 'comment-pass-1',
            'content'     => '<p><br>&nbsp;</p>',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('내용을 입력해 주세요', $this->body($response));
    }
}
