<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Api;

use ApiBoard\App;
use PHPUnit\Framework\Attributes\DataProvider;
use ApiBoard\Tests\Support\ApiTestCase;

final class CommentApiTest extends ApiTestCase
{
    #[DataProvider('connectionProvider')]
    public function testCreateRootComment(array $config): void
    {
        [$app, $postId] = $this->setUpPost($config);

        $response = $this->call($app, 'POST', '/posts/' . $postId . '/comments',
            ['content' => '첫 댓글'], $this->tokenFor($app, 'user-2', '댓글러', false));

        $this->assertSame(201, $response->status());
        $this->assertSame('첫 댓글', $response->payload()['data']['content']);
        $this->assertSame(0, $response->payload()['data']['depth']);
    }

    #[DataProvider('connectionProvider')]
    public function testRepliesNestWithoutDepthLimit(array $config): void
    {
        [$app, $postId] = $this->setUpPost($config);
        $token = $this->tokenFor($app, 'user-2', '댓글러', false);

        $parentId = null;
        for ($i = 1; $i <= 30; $i++) {
            $body = ['content' => '댓글 ' . $i];
            if ($parentId !== null) {
                $body['parent_id'] = $parentId;
            }
            $parentId = (int) $this->call($app, 'POST', '/posts/' . $postId . '/comments', $body, $token)
                ->payload()['data']['id'];
        }

        $tree = $this->call($app, 'GET', '/posts/' . $postId . '/comments')->payload()['data'];

        $node = $tree[0];
        $depth = 0;
        while ($node['children'] !== []) {
            $node = $node['children'][0];
            $depth++;
        }
        $this->assertSame(29, $depth);
        $this->assertSame('댓글 30', $node['content']);
    }

    #[DataProvider('connectionProvider')]
    public function testCommentCountTracksCreateAndDelete(array $config): void
    {
        [$app, $postId] = $this->setUpPost($config);
        $token = $this->tokenFor($app, 'user-2', '댓글러', false);

        $a = (int) $this->call($app, 'POST', '/posts/' . $postId . '/comments', ['content' => 'a'], $token)
            ->payload()['data']['id'];
        $this->call($app, 'POST', '/posts/' . $postId . '/comments', ['content' => 'b'], $token);

        $this->assertSame(2, $this->call($app, 'GET', '/posts/' . $postId)->payload()['data']['comment_count']);

        $this->call($app, 'DELETE', '/comments/' . $a, [], $token);

        $this->assertSame(1, $this->call($app, 'GET', '/posts/' . $postId)->payload()['data']['comment_count']);
    }

    #[DataProvider('connectionProvider')]
    public function testDeletedCommentWithReplyBecomesPlaceholder(array $config): void
    {
        [$app, $postId] = $this->setUpPost($config);
        $token = $this->tokenFor($app, 'user-2', '댓글러', false);

        $parent = (int) $this->call($app, 'POST', '/posts/' . $postId . '/comments', ['content' => '부모'], $token)
            ->payload()['data']['id'];
        $this->call($app, 'POST', '/posts/' . $postId . '/comments',
            ['content' => '자식', 'parent_id' => $parent], $token);

        $this->call($app, 'DELETE', '/comments/' . $parent, [], $token);

        $tree = $this->call($app, 'GET', '/posts/' . $postId . '/comments')->payload()['data'];
        $this->assertTrue($tree[0]['deleted']);
        $this->assertSame('삭제된 댓글입니다.', $tree[0]['content']);
        $this->assertSame('자식', $tree[0]['children'][0]['content']);
    }

    #[DataProvider('connectionProvider')]
    public function testDeletedLeafCommentDisappears(array $config): void
    {
        [$app, $postId] = $this->setUpPost($config);
        $token = $this->tokenFor($app, 'user-2', '댓글러', false);
        $id = (int) $this->call($app, 'POST', '/posts/' . $postId . '/comments', ['content' => '외톨이'], $token)
            ->payload()['data']['id'];

        $this->call($app, 'DELETE', '/comments/' . $id, [], $token);

        $this->assertSame([], $this->call($app, 'GET', '/posts/' . $postId . '/comments')->payload()['data']);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCommentUsesPasswordForOwnership(array $config): void
    {
        [$app, $postId] = $this->setUpPost($config, ['perm_comment' => 'guest']);

        $id = (int) $this->call($app, 'POST', '/posts/' . $postId . '/comments', [
            'content' => '비회원 댓글', 'author_name' => '손님', 'password' => '1234',
        ])->payload()['data']['id'];

        $this->assertSame(401, $this->call($app, 'DELETE', '/comments/' . $id, ['password' => '9999'])->status());
        $this->assertSame(204, $this->call($app, 'DELETE', '/comments/' . $id, ['password' => '1234'])->status());
    }

    #[DataProvider('connectionProvider')]
    public function testParentFromAnotherPostIsRejected(array $config): void
    {
        [$app, $postId] = $this->setUpPost($config);
        $token = $this->tokenFor($app, 'user-2', '댓글러', false);
        $otherPostId = (int) $this->call($app, 'POST', '/boards/free/posts',
            ['title' => '다른 글', 'content' => '본문'], $token)->payload()['data']['id'];
        $foreignComment = (int) $this->call($app, 'POST', '/posts/' . $otherPostId . '/comments',
            ['content' => '남의 글 댓글'], $token)->payload()['data']['id'];

        $response = $this->call($app, 'POST', '/posts/' . $postId . '/comments',
            ['content' => '끼워넣기', 'parent_id' => $foreignComment], $token);

        $this->assertSame(422, $response->status());
    }

    #[DataProvider('connectionProvider')]
    public function testSecretCommentIsMaskedForStrangers(array $config): void
    {
        [$app, $postId] = $this->setUpPost($config);
        $writer = $this->tokenFor($app, 'user-2', '댓글러', false);
        $this->call($app, 'POST', '/posts/' . $postId . '/comments',
            ['content' => '비밀 내용', 'is_secret' => true], $writer);

        $stranger = $this->tokenFor($app, 'user-9', '남', false);
        $masked = $this->call($app, 'GET', '/posts/' . $postId . '/comments', [], $stranger)->payload()['data'][0];
        $this->assertSame('비밀 댓글입니다.', $masked['content']);

        $own = $this->call($app, 'GET', '/posts/' . $postId . '/comments', [], $writer)->payload()['data'][0];
        $this->assertSame('비밀 내용', $own['content']);

        // 원글 작성자도 볼 수 있다.
        $author = $this->tokenFor($app, 'user-1', '홍길동', false);
        $byAuthor = $this->call($app, 'GET', '/posts/' . $postId . '/comments', [], $author)->payload()['data'][0];
        $this->assertSame('비밀 내용', $byAuthor['content']);
    }

    #[DataProvider('connectionProvider')]
    public function testCommentsOnSecretPostRequirePermission(array $config): void
    {
        $app = $this->makeApp($config);
        $this->call($app, 'POST', '/boards', [
            'board_key' => 'free', 'name' => '자유', 'use_secret' => true,
        ], $this->adminToken($app));
        $author = $this->tokenFor($app, 'user-1', '홍길동', false);
        $postId = (int) $this->call($app, 'POST', '/boards/free/posts',
            ['title' => '비밀', 'content' => '본문', 'is_secret' => true], $author)->payload()['data']['id'];

        $stranger = $this->tokenFor($app, 'user-9', '남', false);

        $this->assertSame(403, $this->call($app, 'GET', '/posts/' . $postId . '/comments', [], $stranger)->status());
        $this->assertSame(200, $this->call($app, 'GET', '/posts/' . $postId . '/comments', [], $author)->status());
    }

    #[DataProvider('connectionProvider')]
    public function testBoardManagerCanDeleteAnyComment(array $config): void
    {
        [$app, $postId] = $this->setUpPost($config, ['managers' => ['mgr-1']]);
        $id = (int) $this->call($app, 'POST', '/posts/' . $postId . '/comments', ['content' => '댓글'],
            $this->tokenFor($app, 'user-2', '댓글러', false))->payload()['data']['id'];

        $response = $this->call($app, 'DELETE', '/comments/' . $id, [],
            $this->tokenFor($app, 'mgr-1', '운영자', false));

        $this->assertSame(204, $response->status());
    }

    /** @return array{0: App, 1: int} */
    private function setUpPost(array $config, array $boardOverrides = []): array
    {
        $app = $this->makeApp($config);
        $this->call($app, 'POST', '/boards', array_merge([
            'board_key' => 'free', 'name' => '자유게시판',
        ], $boardOverrides), $this->adminToken($app));

        $postId = (int) $this->call($app, 'POST', '/boards/free/posts',
            ['title' => '글', 'content' => '본문'],
            $this->tokenFor($app, 'user-1', '홍길동', false))->payload()['data']['id'];

        return [$app, $postId];
    }
}
