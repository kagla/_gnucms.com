<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Api;

use ApiBoard\App;
use PHPUnit\Framework\Attributes\DataProvider;
use ApiBoard\Tests\Support\ApiTestCase;

final class PostApiTest extends ApiTestCase
{
    #[DataProvider('connectionProvider')]
    public function testMemberCreatesPost(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $token = $this->tokenFor($app, 'user-1', '홍길동', false);

        $response = $this->call($app, 'POST', '/boards/free/posts', [
            'title'   => '첫 글',
            'content' => '본문입니다',
        ], $token);

        $this->assertSame(201, $response->status());
        $this->assertSame('첫 글', $response->payload()['data']['title']);
        $this->assertSame('홍길동', $response->payload()['data']['author_name']);
    }

    #[DataProvider('connectionProvider')]
    public function testAuthorNameIsForcedFromTokenNotRequest(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $token = $this->tokenFor($app, 'user-1', '홍길동', false);

        $response = $this->call($app, 'POST', '/boards/free/posts', [
            'title'       => '사칭 시도',
            'content'     => '본문',
            'author_name' => '관리자',
        ], $token);

        $this->assertSame('홍길동', $response->payload()['data']['author_name']);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCannotWriteToMemberBoard(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);

        $response = $this->call($app, 'POST', '/boards/free/posts', ['title' => '글', 'content' => '본문']);

        $this->assertSame(401, $response->status());
    }

    #[DataProvider('connectionProvider')]
    public function testGuestWritesWithNameAndPassword(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app, ['perm_write' => 'guest']);

        $response = $this->call($app, 'POST', '/boards/free/posts', [
            'title'       => '비회원 글',
            'content'     => '본문',
            'author_name' => '손님',
            'password'    => '1234',
        ]);

        $this->assertSame(201, $response->status());
        $this->assertSame('손님', $response->payload()['data']['author_name']);
        $this->assertNull($response->payload()['data']['author_id']);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestPostRequiresPasswordOfAtLeastFourCharacters(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app, ['perm_write' => 'guest']);

        $response = $this->call($app, 'POST', '/boards/free/posts', [
            'title' => '글', 'content' => '본문', 'author_name' => '손님', 'password' => '12',
        ]);

        $this->assertSame(422, $response->status());
        $this->assertArrayHasKey('password', (array) $response->payload()['error']['details']);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestEditsOwnPostWithPassword(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app, ['perm_write' => 'guest']);
        $id = $this->guestPost($app);

        $ok = $this->call($app, 'PATCH', '/posts/' . $id, ['title' => '수정됨', 'password' => '1234']);
        $this->assertSame(200, $ok->status());
        $this->assertSame('수정됨', $ok->payload()['data']['title']);

        $bad = $this->call($app, 'PATCH', '/posts/' . $id, ['title' => '탈취', 'password' => '9999']);
        $this->assertSame(401, $bad->status());
    }

    #[DataProvider('connectionProvider')]
    public function testStrangerCannotEditAnothersPost(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $id = $this->memberPost($app, 'user-1', '홍길동');

        $response = $this->call($app, 'PATCH', '/posts/' . $id, ['title' => '탈취'],
            $this->tokenFor($app, 'user-2', '남', false));

        $this->assertSame(403, $response->status());
    }

    #[DataProvider('connectionProvider')]
    public function testBoardManagerCanEditAndDeleteOthersPost(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app, ['managers' => ['mgr-1']]);
        $id = $this->memberPost($app, 'user-1', '홍길동');
        $manager = $this->tokenFor($app, 'mgr-1', '운영자', false);

        $this->assertSame(200, $this->call($app, 'PATCH', '/posts/' . $id, ['title' => '정리됨'], $manager)->status());
        $this->assertSame(204, $this->call($app, 'DELETE', '/posts/' . $id, [], $manager)->status());
    }

    #[DataProvider('connectionProvider')]
    public function testOnlyAdminCanSetNotice(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $id = $this->memberPost($app, 'user-1', '홍길동');

        $denied = $this->call($app, 'PATCH', '/posts/' . $id, ['is_notice' => true],
            $this->tokenFor($app, 'user-1', '홍길동', false));
        $this->assertSame(403, $denied->status());

        $allowed = $this->call($app, 'PATCH', '/posts/' . $id, ['is_notice' => true], $this->adminToken($app));
        $this->assertSame(200, $allowed->status());
        $this->assertTrue($allowed->payload()['data']['is_notice']);
    }

    #[DataProvider('connectionProvider')]
    public function testNoticesComeSeparatelyAndAreExcludedFromTotal(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $this->memberPost($app, 'user-1', '홍길동', '일반 글');
        $noticeId = $this->memberPost($app, 'user-1', '홍길동', '공지 글');
        $this->call($app, 'PATCH', '/posts/' . $noticeId, ['is_notice' => true], $this->adminToken($app));

        $page = $this->call($app, 'GET', '/boards/free/posts')->payload();

        $this->assertSame(1, $page['total']);
        $this->assertSame(['일반 글'], array_column($page['data'], 'title'));
        $this->assertSame(['공지 글'], array_column($page['notices'], 'title'));
    }

    #[DataProvider('connectionProvider')]
    public function testListItemsHaveNoContent(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $this->memberPost($app, 'user-1', '홍길동');

        $item = $this->call($app, 'GET', '/boards/free/posts')->payload()['data'][0];

        $this->assertArrayNotHasKey('content', $item);
        $this->assertArrayHasKey('comment_count', $item);
    }

    #[DataProvider('connectionProvider')]
    public function testPaginationMetadata(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        for ($i = 1; $i <= 5; $i++) {
            $this->memberPost($app, 'user-1', '홍길동', '글 ' . $i);
        }

        $page = $this->call($app, 'GET', '/boards/free/posts', ['page' => 2, 'per_page' => 2])->payload();

        $this->assertSame(5, $page['total']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(2, $page['per_page']);
        $this->assertSame(3, $page['total_pages']);
        $this->assertSame(['글 3', '글 2'], array_column($page['data'], 'title'));
    }

    #[DataProvider('connectionProvider')]
    public function testViewCountIncrementsForOthersButNotForAuthor(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $id = $this->memberPost($app, 'user-1', '홍길동');
        $author = $this->tokenFor($app, 'user-1', '홍길동', false);
        $other = $this->tokenFor($app, 'user-2', '남', false);

        $this->call($app, 'GET', '/posts/' . $id, [], $author);
        $this->assertSame(0, $this->call($app, 'GET', '/posts/' . $id, [], $author)->payload()['data']['view_count']);

        $this->call($app, 'GET', '/posts/' . $id, [], $other);
        $this->assertSame(1, $this->call($app, 'GET', '/posts/' . $id, [], $author)->payload()['data']['view_count']);
    }

    #[DataProvider('connectionProvider')]
    public function testSecretPostIsHiddenFromStrangersButVisibleToAuthorAndAdmin(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app, ['use_secret' => true]);
        $author = $this->tokenFor($app, 'user-1', '홍길동', false);
        $id = (int) $this->call($app, 'POST', '/boards/free/posts', [
            'title' => '비밀', 'content' => '민감한 내용', 'is_secret' => true,
        ], $author)->payload()['data']['id'];

        $this->assertSame(403, $this->call($app, 'GET', '/posts/' . $id, [],
            $this->tokenFor($app, 'user-2', '남', false))->status());
        $this->assertSame(200, $this->call($app, 'GET', '/posts/' . $id, [], $author)->status());
        $this->assertSame(200, $this->call($app, 'GET', '/posts/' . $id, [], $this->adminToken($app))->status());
    }

    #[DataProvider('connectionProvider')]
    public function testSecretFlagRejectedWhenBoardDoesNotAllowIt(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app, ['use_secret' => false]);

        $response = $this->call($app, 'POST', '/boards/free/posts', [
            'title' => '비밀', 'content' => '본문', 'is_secret' => true,
        ], $this->tokenFor($app, 'user-1', '홍길동', false));

        $this->assertSame(422, $response->status());
    }

    #[DataProvider('connectionProvider')]
    public function testCategoryMustBeOneOfBoardCategories(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app, ['use_category' => true, 'categories' => ['질문', '잡담']]);
        $token = $this->tokenFor($app, 'user-1', '홍길동', false);

        $ok = $this->call($app, 'POST', '/boards/free/posts',
            ['title' => '글', 'content' => '본문', 'category' => '질문'], $token);
        $this->assertSame(201, $ok->status());

        $bad = $this->call($app, 'POST', '/boards/free/posts',
            ['title' => '글', 'content' => '본문', 'category' => '없는분류'], $token);
        $this->assertSame(422, $bad->status());
    }

    #[DataProvider('connectionProvider')]
    public function testDeletedPostIsInvisibleToOthersAndRestorableByAdmin(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $id = $this->memberPost($app, 'user-1', '홍길동');
        $admin = $this->adminToken($app);
        $this->call($app, 'DELETE', '/posts/' . $id, [], $admin);

        $this->assertSame(404, $this->call($app, 'GET', '/posts/' . $id)->status());
        $this->assertSame(200, $this->call($app, 'GET', '/posts/' . $id, [], $admin)->status());

        $restored = $this->call($app, 'POST', '/posts/' . $id . '/restore', [], $admin);
        $this->assertSame(200, $restored->status());
        $this->assertSame(200, $this->call($app, 'GET', '/posts/' . $id)->status());
    }

    #[DataProvider('connectionProvider')]
    public function testIncludeDeletedIsHonoredForAdminsAndIgnoredForOthers(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $id = $this->memberPost($app, 'user-1', '홍길동', '지워질 글');
        $this->memberPost($app, 'user-1', '홍길동', '남을 글');
        $admin = $this->adminToken($app);
        $this->call($app, 'DELETE', '/posts/' . $id, [], $admin);

        $asAdmin = $this->call($app, 'GET', '/boards/free/posts', ['include_deleted' => true], $admin)->payload();
        $this->assertSame(2, $asAdmin['total']);
        // 목록은 id 내림차순이므로 먼저 쓴 '지워질 글' 이 뒤에 온다. 위치가 아니라 제목으로 찾는다.
        $deleted = array_values(array_filter(
            $asAdmin['data'],
            static function (array $row): bool {
                return $row['title'] === '지워질 글';
            }
        ));
        $this->assertTrue($deleted[0]['deleted']);

        $asMember = $this->call($app, 'GET', '/boards/free/posts', ['include_deleted' => true],
            $this->tokenFor($app, 'user-1', '홍길동', false))->payload();
        $this->assertSame(1, $asMember['total']);
    }

    #[DataProvider('connectionProvider')]
    public function testNonAdminCannotRestore(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $id = $this->memberPost($app, 'user-1', '홍길동');
        $author = $this->tokenFor($app, 'user-1', '홍길동', false);
        $this->call($app, 'DELETE', '/posts/' . $id, [], $author);

        $this->assertSame(403, $this->call($app, 'POST', '/posts/' . $id . '/restore', [], $author)->status());
    }

    #[DataProvider('connectionProvider')]
    public function testSearchFindsByTitle(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $this->memberPost($app, 'user-1', '홍길동', '사과 이야기');
        $this->memberPost($app, 'user-1', '홍길동', '배 이야기');

        $page = $this->call($app, 'GET', '/boards/free/posts', ['q' => '사과'])->payload();

        $this->assertSame(1, $page['total']);
    }

    private function board(App $app, array $overrides = []): void
    {
        $this->call($app, 'POST', '/boards', array_merge([
            'board_key' => 'free',
            'name'      => '자유게시판',
        ], $overrides), $this->adminToken($app));
    }

    private function memberPost(App $app, string $sub, string $name, string $title = '글'): int
    {
        $response = $this->call($app, 'POST', '/boards/free/posts', [
            'title' => $title, 'content' => '본문',
        ], $this->tokenFor($app, $sub, $name, false));

        return (int) $response->payload()['data']['id'];
    }

    private function guestPost(App $app): int
    {
        $response = $this->call($app, 'POST', '/boards/free/posts', [
            'title' => '비회원 글', 'content' => '본문', 'author_name' => '손님', 'password' => '1234',
        ]);

        return (int) $response->payload()['data']['id'];
    }
}
