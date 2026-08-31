<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** 사이트 설정의 쓰기 규칙(본문 최소 글자수)이 글 저장에 걸리는지. */
final class WriteRulesTest extends WebTestCase
{
    private function guestBoardApp(array $dbConfig, string $minChars): \GnuCms\App
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['post_min_chars' => $minChars]);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'guest',
        ]);

        return $app;
    }

    #[DataProvider('connectionProvider')]
    public function testShortContentIsRejectedWhenMinimumIsSet(array $dbConfig): void
    {
        $app = $this->guestBoardApp($dbConfig, '10');
        $this->get($app, '/boards/free/new');

        $response = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '제목',
            // 태그와 공백을 빼면 4자다. 편집기가 감싼 태그로 길이를 속일 수 없어야 한다.
            'content' => '<p>  본문임다  </p>',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('10자 이상', $this->body($response));
    }

    #[DataProvider('connectionProvider')]
    public function testLongEnoughContentPasses(array $dbConfig): void
    {
        $app = $this->guestBoardApp($dbConfig, '10');
        $this->get($app, '/boards/free/new');

        $response = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '제목',
            'content' => '<p>열 글자는 넘는 본문입니다</p>',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ]);

        self::assertSame(303, $response->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testZeroMeansNoMinimum(array $dbConfig): void
    {
        $app = $this->guestBoardApp($dbConfig, '0');
        $this->get($app, '/boards/free/new');

        $response = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '제목', 'content' => '짧다',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ]);

        self::assertSame(303, $response->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testShortCommentIsRejectedWhenCommentMinimumIsSet(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['comment_min_chars' => '5']);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'guest', 'perm_comment' => 'guest',
        ]);
        $post = $app->postService()->create($this->adminAcl(), 'free', ['title' => '글', 'content' => '본문입니다']);
        $this->get($app, '/posts/' . $post['id']);

        $short = $this->post($app, '/posts/' . $post['id'] . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'author_name' => '손님', 'password' => 'comment-pass-1', 'content' => '<p>넵</p>',
        ]);
        self::assertSame(422, $short->getStatusCode());
        self::assertStringContainsString('5자 이상', $this->body($short));

        $ok = $this->post($app, '/posts/' . $post['id'] . '/comments', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'author_name' => '손님', 'password' => 'comment-pass-1', 'content' => '<p>충분히 긴 댓글입니다</p>',
        ]);
        self::assertSame(303, $ok->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testEditorsCarryTheMinimumSoTheyCanWarnBeforeSubmit(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['post_min_chars' => '10', 'comment_min_chars' => '5']);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'guest', 'perm_comment' => 'guest',
        ]);
        // 최소 글자수가 10자이므로 씨앗 글도 그만큼 길어야 한다.
        $post = $app->postService()->create($this->adminAcl(), 'free', ['title' => '글', 'content' => '열 글자가 넘는 본문입니다']);

        // 편집기가 textarea 를 숨겨 브라우저 검사가 못 도므로, 제출 전 알림에 쓸 값을 칸에 실어 보낸다.
        self::assertStringContainsString('data-min-chars="10"', $this->body($this->get($app, '/boards/free/new')));
        self::assertStringContainsString('data-min-chars="5"', $this->body($this->get($app, '/posts/' . $post['id'])));
    }
}

