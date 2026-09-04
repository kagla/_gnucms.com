<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BoardSearchTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testBoardShowsVisiblePostAndCommentSearch(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->createBoard($app);

        $body = $this->body($this->get($app, '/boards/free'));

        self::assertStringContainsString('class="inline-search board-search"', $body);
        self::assertStringContainsString('class="board-search-area"', $body);
        self::assertStringContainsString('name="scope"', $body);
        self::assertStringContainsString('class="board-search-select-icon"', $body);
        self::assertStringContainsString('<option value="posts" selected>게시글</option>', $body);
        self::assertStringContainsString('<option value="comments">댓글</option>', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testCommentSearchShowsExcerptAndLinksToComment(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->createBoard($app);
        $post = $app->postService()->create($this->adminAcl(), 'free', [
            'title' => '검색 결과가 속한 글', 'content' => '게시글 본문',
        ]);
        $boardId = (int) $app->boardService()->getEntity($this->adminAcl(), 'free')['id'];
        $commentId = $app->comments()->create($this->comment(
            $boardId, (int) $post['id'], '<p>댓글에서만 찾는 바늘입니다.</p>'
        ));

        $response = $this->get($app, '/boards/free', ['scope' => 'comments', 'q' => '바늘']);
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('댓글 검색 결과 <strong>1</strong>개', $body);
        self::assertStringContainsString('댓글에서만 찾는 바늘입니다.', $body);
        self::assertStringContainsString('검색 결과가 속한 글', $body);
        self::assertStringContainsString('/posts/' . $post['id'] . '#comment-' . $commentId, $body);
        self::assertStringContainsString('<option value="comments" selected>댓글</option>', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testCommentSearchExcludesDeletedAndSecretContent(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->createBoard($app);
        $acl = $this->adminAcl();
        $publicPost = $app->postService()->create($acl, 'free', [
            'title' => '공개 글', 'content' => '본문',
        ]);
        $secretPost = $app->postService()->create($acl, 'free', [
            'title' => '비밀 글', 'content' => '본문', 'is_secret' => true,
        ]);
        $comments = $app->comments();
        $boardId = (int) $app->boardService()->getEntity($acl, 'free')['id'];
        $comments->create($this->comment($boardId, (int) $publicPost['id'], '공개 표적 댓글'));
        $comments->create($this->comment($boardId, (int) $publicPost['id'], '비밀 표적 댓글') + ['is_secret' => true]);
        $deleted = $comments->create($this->comment($boardId, (int) $publicPost['id'], '삭제 표적 댓글'));
        $comments->softDelete($deleted);
        $comments->create($this->comment($boardId, (int) $secretPost['id'], '비밀글 표적 댓글'));

        $body = $this->body($this->get($app, '/boards/free', ['scope' => 'comments', 'q' => '표적']));

        self::assertStringContainsString('댓글 검색 결과 <strong>1</strong>개', $body);
        self::assertStringContainsString('공개 표적 댓글', $body);
        self::assertStringNotContainsString('비밀 표적 댓글', $body);
        self::assertStringNotContainsString('삭제 표적 댓글', $body);
        self::assertStringNotContainsString('비밀글 표적 댓글', $body);
    }

    private function createBoard(App $app): void
    {
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유게시판', 'use_secret' => true,
        ]);
    }

    private function comment(int $boardId, int $postId, string $content): array
    {
        return [
            'board_id' => $boardId,
            'post_id' => $postId,
            'parent_id' => null,
            'content' => $content,
            'author_id' => '1',
            'author_name' => '작성자',
        ];
    }
}
