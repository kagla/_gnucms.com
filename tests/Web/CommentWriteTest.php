<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
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
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('#comments', $response->getHeaderLine('Location'));

        $body = $this->body($this->get($app, '/posts/' . $postId));
        self::assertStringContainsString('반갑습니다', $body);
        self::assertStringContainsString('지나가던 사람', $body);
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
    public function testEditorImageUploadFollowsBoardPermission(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
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

        // Acl 은 게스트에게 401(로그인 필요), 로그인했는데 권한이 없으면 403 을 준다.
        // 어느 쪽이든 편집기가 JSON 200 으로 성공처럼 받아서는 안 된다.
        $denied = $this->upload($app, '/boards/closed/editor/images' . $query, ['upload' => $this->png()]);
        self::assertSame(401, $denied->getStatusCode());
        self::assertStringNotContainsString('"uploaded"', $this->body($denied));
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
}
