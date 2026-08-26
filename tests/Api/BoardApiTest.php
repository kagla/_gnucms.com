<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Api;

use ApiBoard\App;
use PHPUnit\Framework\Attributes\DataProvider;
use ApiBoard\Tests\Support\ApiTestCase;

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

    #[DataProvider('connectionProvider')]
    public function testRenamingACategoryMovesExistingPosts(array $config): void
    {
        $app = $this->makeApp($config);
        $this->categorizedBoard($app);
        $id = $this->postIn($app, 'free', '질문');

        $response = $this->call($app, 'PATCH', '/boards/free', [
            'categories'        => ['문의', '잡담'],
            'category_renames'  => ['질문' => '문의'],
        ], $this->adminToken($app));

        $this->assertSame(200, $response->status());
        $this->assertSame('문의', $this->call($app, 'GET', '/posts/' . $id)->payload()['data']['category']);
    }

    #[DataProvider('connectionProvider')]
    public function testRenamedCategoryPostRemainsEditable(array $config): void
    {
        // 이름을 바꾼 뒤에도 그 글을 원래 분류 그대로 저장할 수 있어야 한다.
        // 전파가 없으면 여기서 422 가 나고 사용자는 이유를 알 수 없다.
        $app = $this->makeApp($config);
        $this->categorizedBoard($app);
        $id = $this->postIn($app, 'free', '질문');
        $admin = $this->adminToken($app);

        $this->call($app, 'PATCH', '/boards/free', [
            'categories'       => ['문의', '잡담'],
            'category_renames' => ['질문' => '문의'],
        ], $admin);

        $edit = $this->call($app, 'PATCH', '/posts/' . $id, ['category' => '문의'], $admin);

        $this->assertSame(200, $edit->status());
    }

    #[DataProvider('connectionProvider')]
    public function testRenameOnlyTouchesItsOwnBoard(array $config): void
    {
        $app = $this->makeApp($config);
        $this->categorizedBoard($app);
        $this->categorizedBoard($app, 'other');
        $mine = $this->postIn($app, 'free', '질문');
        $theirs = $this->postIn($app, 'other', '질문');

        $this->call($app, 'PATCH', '/boards/free', [
            'categories'       => ['문의', '잡담'],
            'category_renames' => ['질문' => '문의'],
        ], $this->adminToken($app));

        $this->assertSame('문의', $this->call($app, 'GET', '/posts/' . $mine)->payload()['data']['category']);
        $this->assertSame('질문', $this->call($app, 'GET', '/posts/' . $theirs)->payload()['data']['category']);
    }

    #[DataProvider('connectionProvider')]
    public function testMergingTwoCategoriesIntoOne(array $config): void
    {
        $app = $this->makeApp($config);
        $this->categorizedBoard($app);
        $a = $this->postIn($app, 'free', '질문');
        $b = $this->postIn($app, 'free', '잡담');

        $this->call($app, 'PATCH', '/boards/free', [
            'categories'       => ['문의'],
            'category_renames' => ['질문' => '문의', '잡담' => '문의'],
        ], $this->adminToken($app));

        $this->assertSame('문의', $this->call($app, 'GET', '/posts/' . $a)->payload()['data']['category']);
        $this->assertSame('문의', $this->call($app, 'GET', '/posts/' . $b)->payload()['data']['category']);
    }

    #[DataProvider('connectionProvider')]
    public function testRenameFromAnUnknownCategoryIsRejected(array $config): void
    {
        $app = $this->makeApp($config);
        $this->categorizedBoard($app);

        $response = $this->call($app, 'PATCH', '/boards/free', [
            'categories'       => ['문의', '잡담'],
            'category_renames' => ['없던분류' => '문의'],
        ], $this->adminToken($app));

        $this->assertSame(422, $response->status());
        $this->assertArrayHasKey('category_renames', (array) $response->payload()['error']['details']);
    }

    #[DataProvider('connectionProvider')]
    public function testRenameToACategoryThatIsNotOfferedIsRejected(array $config): void
    {
        $app = $this->makeApp($config);
        $this->categorizedBoard($app);

        $response = $this->call($app, 'PATCH', '/boards/free', [
            'categories'       => ['문의', '잡담'],
            'category_renames' => ['질문' => '엉뚱한곳'],
        ], $this->adminToken($app));

        $this->assertSame(422, $response->status());
    }

    #[DataProvider('connectionProvider')]
    public function testKeepingTheOldNameAliveIsNotARename(array $config): void
    {
        // 옛 이름이 새 목록에 그대로 남아 있으면 이름 변경이 아니다.
        // 지웠는지 남겼는지 알 수 없는 채로 글을 옮기면 안 된다.
        $app = $this->makeApp($config);
        $this->categorizedBoard($app);

        $response = $this->call($app, 'PATCH', '/boards/free', [
            'categories'       => ['질문', '문의'],
            'category_renames' => ['질문' => '문의'],
        ], $this->adminToken($app));

        $this->assertSame(422, $response->status());
    }

    #[DataProvider('connectionProvider')]
    public function testWithoutARenameMapCategoriesAreSimplyReplaced(array $config): void
    {
        // 지금까지의 동작을 그대로 둔다. 서버가 알아서 짐작하지 않는다.
        $app = $this->makeApp($config);
        $this->categorizedBoard($app);
        $id = $this->postIn($app, 'free', '질문');

        $this->call($app, 'PATCH', '/boards/free', ['categories' => ['문의', '잡담']], $this->adminToken($app));

        $this->assertSame('질문', $this->call($app, 'GET', '/posts/' . $id)->payload()['data']['category']);
    }

    private function categorizedBoard(App $app, string $key = 'free'): void
    {
        $this->call($app, 'POST', '/boards', [
            'board_key'    => $key,
            'name'         => '분류 게시판',
            'use_category' => true,
            'categories'   => ['질문', '잡담'],
        ], $this->adminToken($app));
    }

    private function postIn(App $app, string $boardKey, string $category): int
    {
        $response = $this->call($app, 'POST', '/boards/' . $boardKey . '/posts', [
            'title'    => $category . ' 글',
            'content'  => '본문',
            'category' => $category,
        ], $this->tokenFor($app, 'user-1', '홍길동', false));

        return (int) $response->payload()['data']['id'];
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
