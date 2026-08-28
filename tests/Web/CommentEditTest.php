<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\App;
use ApiBoard\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** 댓글 수정과 삭제. 비회원 댓글은 비밀번호로 주인을 확인한다. */
final class CommentEditTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testGuestEditsOwnCommentWithPassword(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [$postId, $commentId] = $this->seedGuestComment($app);

        $form = $this->get($app, '/comments/' . $commentId . '/edit');
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('name="password"', $this->body($form));
        self::assertStringContainsString('원래 댓글', $this->body($form));

        $response = $this->post($app, '/comments/' . $commentId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'password'   => 'comment-pass-1',
            'content'    => '고친 댓글',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/posts/' . $postId . '#comment-' . $commentId, $response->getHeaderLine('Location'));

        $body = $this->body($this->get($app, '/posts/' . $postId));
        self::assertStringContainsString('고친 댓글', $body);
        self::assertStringNotContainsString('원래 댓글', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testWrongPasswordComesBackToTheForm(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [$postId, $commentId] = $this->seedGuestComment($app);

        $this->get($app, '/comments/' . $commentId . '/edit');
        $response = $this->post($app, '/comments/' . $commentId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'password'   => '틀린비밀번호',
            'content'    => '고친 댓글',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('name="password"', $this->body($response));
        self::assertStringContainsString('원래 댓글', $this->body($this->get($app, '/posts/' . $postId)));
    }

    #[DataProvider('connectionProvider')]
    public function testDeleteRemovesTheCommentAndItsCount(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [$postId, $commentId] = $this->seedGuestComment($app);

        $this->get($app, '/comments/' . $commentId . '/edit');
        $response = $this->post($app, '/comments/' . $commentId . '/delete', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'password'   => 'comment-pass-1',
        ]);

        self::assertSame(303, $response->getStatusCode());
        $body = $this->body($this->get($app, '/posts/' . $postId));
        self::assertStringNotContainsString('원래 댓글', $body);
        self::assertSame(0, (int) $app->db()->selectOne(
            'SELECT comment_count AS c FROM ' . $app->db()->q('posts') . ' WHERE id = ?',
            [$postId]
        )['c'], '지운 댓글은 개수에서도 빠져야 한다');
    }

    #[DataProvider('connectionProvider')]
    public function testDeleteWithWrongPasswordKeepsTheComment(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [$postId, $commentId] = $this->seedGuestComment($app);

        $this->get($app, '/comments/' . $commentId . '/edit');
        $response = $this->post($app, '/comments/' . $commentId . '/delete', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'password'   => '틀림',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('원래 댓글', $this->body($this->get($app, '/posts/' . $postId)));
    }

    #[DataProvider('connectionProvider')]
    public function testCsrfTokenIsRequiredToDelete(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [$postId, $commentId] = $this->seedGuestComment($app);

        self::assertSame(
            403,
            $this->post($app, '/comments/' . $commentId . '/delete', ['csrf_token' => 'wrong'])->getStatusCode()
        );
        self::assertStringContainsString('원래 댓글', $this->body($this->get($app, '/posts/' . $postId)));
    }

    /** 남의 비밀 댓글은 수정 화면으로도 새어 나가면 안 된다. */
    #[DataProvider('connectionProvider')]
    public function testSecretCommentOfAnotherMemberIsNotReadableThroughTheEditForm(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유게시판',
            'perm_write' => 'guest', 'perm_comment' => 'guest', 'use_secret' => '1',
        ]);
        // 글쓴이도 관리자도 아닌 다른 회원이 남긴 비밀 댓글
        $post = $app->postService()->create($this->adminAcl(), 'free', ['title' => '글', 'content' => '본문']);
        $comment = $app->commentService()->create(
            new \ApiBoard\Auth\Acl(\ApiBoard\Auth\Identity::user('42', '다른 회원', false)),
            (int) $post['id'],
            ['content' => '비밀스러운 이야기', 'is_secret' => '1']
        );

        $response = $this->get($app, '/comments/' . $comment['id'] . '/edit');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('비밀스러운 이야기', $this->body($response));
    }

    #[DataProvider('connectionProvider')]
    public function testCommentShowsAnEditLink(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [$postId, $commentId] = $this->seedGuestComment($app);

        self::assertStringContainsString(
            '/comments/' . $commentId . '/edit',
            $this->body($this->get($app, '/posts/' . $postId))
        );
    }

    /**
     * 글 화면에서 바로 고칠 수 있어야 한다.
     * 편집기에 넣을 원래 내용을 따로 담아 두지 않으면, 화면에 보이는 축소본 주소가
     * 그대로 저장되어 본문이 망가진다.
     */
    #[DataProvider('connectionProvider')]
    public function testPostPageCarriesWhatInlineEditingNeeds(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        [$postId, $commentId] = $this->seedGuestComment($app);

        $body = $this->body($this->get($app, '/posts/' . $postId));

        self::assertStringContainsString('data-edit="' . $commentId . '"', $body);
        self::assertStringContainsString('data-source="' . $commentId . '"', $body);
        // 고칠 때 삭제도 같이 할 수 있어야 한다.
        self::assertStringContainsString('data-delete-action="/comments/' . $commentId . '/delete"', $body);
        self::assertStringContainsString('data-delete', $body);
        // 스크립트가 없어도 따로 만든 수정 화면으로 갈 수 있어야 한다.
        self::assertStringContainsString('href="/comments/' . $commentId . '/edit"', $body);
    }

    /** @return array{0:int,1:int} 글 번호와 댓글 번호 */
    private function seedGuestComment(App $app): array
    {
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유게시판', 'perm_write' => 'guest', 'perm_comment' => 'guest',
        ]);
        $post = $app->postService()->create($this->adminAcl(), 'free', ['title' => '글', 'content' => '본문']);
        $postId = (int) $post['id'];

        $this->get($app, '/posts/' . $postId);
        $this->post($app, '/posts/' . $postId . '/comments', [
            'csrf_token'  => $_SESSION['csrf_token'] ?? '',
            'author_name' => '손님',
            'password'    => 'comment-pass-1',
            'content'     => '원래 댓글',
        ]);

        $commentId = (int) $app->db()->selectOne('SELECT MAX(id) AS id FROM ' . $app->db()->q('comments'))['id'];

        return [$postId, $commentId];
    }
}
