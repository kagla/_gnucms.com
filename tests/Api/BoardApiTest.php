<?php

declare(strict_types=1);

namespace StandardBoard\Tests\Api;

use StandardBoard\App;
use PHPUnit\Framework\Attributes\DataProvider;
use StandardBoard\Tests\Support\ApiTestCase;

final class BoardApiTest extends ApiTestCase
{
    #[DataProvider('connectionProvider')]
    public function testAdminCreatesBoard(array $config): void
    {
        $app = $this->makeApp($config);

        $response = $this->call($app, 'POST', '/boards', [
            'board_key'  => 'free',
            'name'       => '자유게시판',
            'categories' => ['잡담', '질문'],
        ], $this->adminToken($app));

        $this->assertSame(201, $response->status());
        $this->assertSame('free', $response->payload()['data']['board_key']);
        $this->assertSame(['잡담', '질문'], $response->payload()['data']['categories']);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCannotCreateBoard(array $config): void
    {
        $app = $this->makeApp($config);

        $response = $this->call($app, 'POST', '/boards', ['board_key' => 'free', 'name' => '자유']);

        $this->assertSame(401, $response->status());
    }

    #[DataProvider('connectionProvider')]
    public function testMemberCannotCreateBoard(array $config): void
    {
        $app = $this->makeApp($config);
        $token = $this->tokenFor($app, 'user-1', '회원', false);

        $response = $this->call($app, 'POST', '/boards', ['board_key' => 'free', 'name' => '자유'], $token);

        $this->assertSame(403, $response->status());
    }

    #[DataProvider('connectionProvider')]
    public function testBoardKeyIsValidated(array $config): void
    {
        $app = $this->makeApp($config);

        $response = $this->call($app, 'POST', '/boards', [
            'board_key' => '자유 게시판',
            'name'      => '자유',
        ], $this->adminToken($app));

        $this->assertSame(422, $response->status());
        $this->assertArrayHasKey('board_key', (array) $response->payload()['error']['details']);
    }

    #[DataProvider('connectionProvider')]
    public function testDuplicateBoardKeyIsRejected(array $config): void
    {
        $app = $this->makeApp($config);
        $this->createBoard($app);

        $response = $this->call($app, 'POST', '/boards', [
            'board_key' => 'free',
            'name'      => '중복',
        ], $this->adminToken($app));

        $this->assertSame(422, $response->status());
    }

    #[DataProvider('connectionProvider')]
    public function testListHidesBoardsTheCallerCannotRead(array $config): void
    {
        $app = $this->makeApp($config);
        $admin = $this->adminToken($app);
        $this->call($app, 'POST', '/boards', ['board_key' => 'open', 'name' => '공개'], $admin);
        $this->call($app, 'POST', '/boards', ['board_key' => 'secret', 'name' => '비공개', 'perm_read' => 'admin'], $admin);

        $guestKeys = array_column($this->call($app, 'GET', '/boards')->payload()['data'], 'board_key');
        $adminKeys = array_column($this->call($app, 'GET', '/boards', [], $admin)->payload()['data'], 'board_key');

        $this->assertSame(['open'], $guestKeys);
        $this->assertSame(['open', 'secret'], $adminKeys);
    }

    #[DataProvider('connectionProvider')]
    public function testManagersAreHiddenFromNonAdmins(array $config): void
    {
        $app = $this->makeApp($config);
        $this->call($app, 'POST', '/boards', [
            'board_key' => 'free',
            'name'      => '자유',
            'managers'  => ['mgr-1'],
        ], $this->adminToken($app));

        $guestView = $this->call($app, 'GET', '/boards/free')->payload()['data'];
        $adminView = $this->call($app, 'GET', '/boards/free', [], $this->adminToken($app))->payload()['data'];

        $this->assertArrayNotHasKey('managers', $guestView);
        $this->assertSame(['mgr-1'], $adminView['managers']);
    }

    #[DataProvider('connectionProvider')]
    public function testBoardManagerSeesManagersButCannotChangeSettings(array $config): void
    {
        $app = $this->makeApp($config);
        $this->call($app, 'POST', '/boards', [
            'board_key' => 'free',
            'name'      => '자유',
            'managers'  => ['mgr-1'],
        ], $this->adminToken($app));
        $managerToken = $this->tokenFor($app, 'mgr-1', '운영자', false);

        $view = $this->call($app, 'GET', '/boards/free', [], $managerToken)->payload()['data'];
        $this->assertSame(['mgr-1'], $view['managers']);

        $update = $this->call($app, 'PATCH', '/boards/free', ['name' => '바뀐 이름'], $managerToken);
        $this->assertSame(403, $update->status());
    }

    #[DataProvider('connectionProvider')]
    public function testUpdateChangesOnlyGivenFields(array $config): void
    {
        $app = $this->makeApp($config);
        $this->createBoard($app);

        $response = $this->call($app, 'PATCH', '/boards/free', ['name' => '새 이름'], $this->adminToken($app));

        $this->assertSame(200, $response->status());
        $this->assertSame('새 이름', $response->payload()['data']['name']);
        $this->assertSame('free', $response->payload()['data']['board_key']);
        $this->assertSame(20, $response->payload()['data']['per_page']);
    }

    #[DataProvider('connectionProvider')]
    public function testUnknownBoardGives404(array $config): void
    {
        $app = $this->makeApp($config);

        $this->assertSame(404, $this->call($app, 'GET', '/boards/nope')->status());
    }

    #[DataProvider('connectionProvider')]
    public function testDeleteRemovesBoardAndItsContent(array $config): void
    {
        $app = $this->makeApp($config);
        $boardId = $this->createBoard($app);
        $postId = $app->posts()->create([
            'board_id' => $boardId, 'title' => '글', 'content' => '본문',
            'author_id' => 'u', 'author_name' => '가',
        ]);
        $app->comments()->create([
            'board_id' => $boardId, 'post_id' => $postId, 'parent_id' => null,
            'content' => '댓글', 'author_id' => 'u', 'author_name' => '가',
        ]);

        $response = $this->call($app, 'DELETE', '/boards/free', [], $this->adminToken($app));

        $this->assertSame(204, $response->status());
        $this->assertNull($app->boards()->findByKey('free'));
        $this->assertNull($app->posts()->find($postId));
        $this->assertSame([], $app->comments()->findByPost($postId));
    }

    private function createBoard(App $app): int
    {
        $response = $this->call($app, 'POST', '/boards', [
            'board_key' => 'free',
            'name'      => '자유게시판',
        ], $this->adminToken($app));

        return (int) $response->payload()['data']['id'];
    }
}
