<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Tests\Support\WebTestCase;

final class PostListTest extends WebTestCase
{
    private function seed(App $app, int $count): void
    {
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판', 'per_page' => 2]);

        for ($i = 1; $i <= $count; $i++) {
            $app->postService()->create($acl, 'free', [
                'title'   => '글 제목 ' . $i,
                'content' => '내용 ' . $i,
            ]);
        }
    }

    /** @dataProvider connectionProvider */
    public function testPostsAreListed(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 1);

        $response = $this->get($app, '/boards/free');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('글 제목 1', $this->body($response));
        self::assertStringContainsString('자유게시판', $this->body($response));
    }

    /** @dataProvider connectionProvider */
    public function testLegacyBoardUrlRedirectsToClearCanonicalUrl(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        $response = $this->get($app, '/b/free', ['page' => '2']);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/boards/free?page=2', $response->getHeaderLine('Location'));
    }

    /** @dataProvider connectionProvider */
    public function testSecondPageShowsOlderPosts(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 3);

        $body = $this->body($this->get($app, '/boards/free', ['page' => '2']));

        self::assertStringContainsString('글 제목 1', $body);
        self::assertStringNotContainsString('글 제목 3', $body);
    }

    /** @dataProvider connectionProvider */
    public function testSearchFiltersPosts(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 3);

        $body = $this->body($this->get($app, '/boards/free', ['q' => '제목 2']));

        self::assertStringContainsString('글 제목 2', $body);
        self::assertStringNotContainsString('글 제목 1', $body);
    }

    /**
     * PostRepository::paginate() 는 평범한 LIKE 로 검색한다. MySQL 과 (아스키 범위에서는)
     * SQLite 에서는 대소문자를 가리지 않지만, PostgreSQL 의 LIKE 는 대소문자를 가린다.
     * 지금까지의 검색 테스트는 대소문자 구분이 없는 한글이라 이 차이를 드러내지 못했다.
     *
     * 이 테스트는 SQLite 와 MySQL 에서는 통과한다. PostgreSQL 로 돌리면 실패할 것으로
     * 예상한다 — 그게 이 테스트의 목적이다. pgsql 에서 이 테스트가 실패하면 버그가
     * 아니라 여기서 미리 표시해 둔 실제 동작 차이이니, 테스트를 고치지 말고 ILIKE 로
     * 바꾸는 등 PostRepository 를 고쳐야 한다.
     *
     * @dataProvider connectionProvider
     */
    public function testSearchIsCaseInsensitiveForAsciiTitles(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        $app->postService()->create($acl, 'free', ['title' => 'Hello World', 'content' => '내용']);

        $body = $this->body($this->get($app, '/boards/free', ['q' => 'hello']));

        self::assertStringContainsString('Hello World', $body);
    }

    /**
     * ?q[]=x 처럼 배열로 온 검색어는 Validator 가 "Array to string conversion"
     * 경고 없이 검증 실패(422)로 처리해야 한다. failOnWarning="true" 라서 경고가
     * 나면 이 테스트 자체가 실패로 끝난다.
     *
     * @dataProvider connectionProvider
     */
    public function testArrayQueryParameterFailsValidationInsteadOfCrashing(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 1);

        $response = $this->get($app, '/boards/free', ['q' => ['x']]);

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * ?include_deleted[]=x 처럼 배열로 온 값은 Validator::bool() 이 (string) 캐스팅
     * 하면서 "Array to string conversion" 경고를 냈었다. bool() 은 원래도 인식하지
     * 못하는 문자열이면 조용히 기본값으로 떨어지므로(예: "nonsense" -> false), 배열도
     * 검증 실패가 아니라 그와 같은 방식으로 기본값 처리되어 200 이어야 한다.
     *
     * @dataProvider connectionProvider
     */
    public function testArrayIncludeDeletedQueryParameterDoesNotCrash(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 1);

        $response = $this->get($app, '/boards/free', ['include_deleted' => ['x']]);

        self::assertSame(200, $response->getStatusCode());
    }

    /** @dataProvider connectionProvider */
    public function testUnknownBoardRendersNotFoundPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $response = $this->get($app, '/boards/없는게시판');

        self::assertSame(404, $response->getStatusCode());
    }

    /** @dataProvider connectionProvider */
    public function testUnreadableBoardRendersUnauthorizedPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'secret',
            'name'      => '관리자전용',
            'perm_read' => 'admin',
        ]);

        $response = $this->get($app, '/boards/secret');

        self::assertSame(401, $response->getStatusCode());
    }

    /** @dataProvider connectionProvider */
    public function testGuestCannotOpenMemberWriteForm(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유게시판']);

        $response = $this->get($app, '/boards/free/write');

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('로그인이 필요합니다', $this->body($response));
    }

    /**
     * per_page 는 호출자가 정할 수 있고 (예전 하한은 1), total_pages 는 글 수만큼
     * 커질 수 있다. 예전 페이지네이션 템플릿은 1..total_pages 전체를 그렸으므로,
     * per_page=1 인 대형 게시판에서는 글 하나마다 링크 하나가 생겼다 — 공유 호스팅에서
     * 메모리 한도로 죽을 수 있는 크기다. 지금은 현재 페이지 앞뒤 창(5개)만 그려야 한다.
     *
     * @dataProvider connectionProvider
     */
    public function testLargeTotalPagesRendersBoundedPagerLinks(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판', 'per_page' => 1]);
        for ($i = 1; $i <= 40; $i++) {
            $app->postService()->create($acl, 'free', ['title' => '글 ' . $i, 'content' => '내용 ' . $i]);
        }

        $body = $this->body($this->get($app, '/boards/free'));

        // total_pages 는 40 이지만, 창(앞뒤 5개) + 첫/끝 페이지만 링크가 되어야 한다.
        // 예전처럼 1..total_pages 를 통째로 그렸다면 "?page=" 가 40번 가까이 나온다.
        $pageLinks = substr_count($body, '?page=');
        self::assertGreaterThan(0, $pageLinks);
        self::assertLessThanOrEqual(13, $pageLinks);
    }
}
