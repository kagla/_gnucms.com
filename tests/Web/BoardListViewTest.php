<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Db\Schema;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 게시판 목록 형태(list_type: 목록·갤러리·매거진·뉴스형)를 다룬다.
 *
 * 형태 이름은 posts/_list_{이름}.html.twig 로 그대로 파일 경로가 되므로,
 * 허용 목록 밖의 값이 그 자리에 닿지 않는지가 이 테스트의 핵심이다.
 */
final class BoardListViewTest extends WebTestCase
{
    private const DEFAULT_HOME_LIMIT = 5;

    /** 1x1 PNG. finfo 가 image/png 로 판정할 실제 바이트가 필요하다. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    #[DataProvider('connectionProvider')]
    public function testBoardSettingChoosesListTemplate(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 'gallery');

        $body = $this->body($this->get($app, '/boards/free'));

        self::assertStringContainsString('class="card-grid"', $body);
        self::assertStringNotContainsString('post-rows-text', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testViewQueryOverridesBoardSetting(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 'gallery');

        $body = $this->body($this->get($app, '/boards/free', ['view' => 'news']));

        self::assertStringContainsString('post-rows post-rows-text', $body);
        self::assertStringNotContainsString('class="card-grid"', $body);
    }

    /**
     * 허용 목록 밖의 값은 게시판 설정으로 되돌린다. 이 값이 그대로 include 경로에
     * 들어가면 템플릿 디렉터리 밖을 가리킬 수 있으므로, 500 이 아니라 정상 화면이어야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testUnknownViewFallsBackInsteadOfTouchingTemplatePath(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 'gallery');

        foreach (['../../layout', 'evil', '', 'LIST'] as $bad) {
            $response = $this->get($app, '/boards/free', ['view' => $bad]);

            self::assertSame(200, $response->getStatusCode(), '입력: ' . $bad);
            self::assertStringContainsString('class="card-grid"', $this->body($response), '입력: ' . $bad);
        }
    }

    /** 비밀글은 목록에서 본문 발췌와 썸네일을 내보내면 안 된다. 제목만 남는다. */
    #[DataProvider('connectionProvider')]
    public function testSecretPostKeepsExcerptAndThumbnailOutOfTheList(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'free', 'name' => '자유게시판',
            'use_file' => true, 'use_secret' => true, 'list_type' => 'magazine',
        ]);
        $image = $app->attachments()->upload($acl, 'free', $this->fakeUpload('사진.png', base64_decode(self::PNG)));
        $post = $app->postService()->create($acl, 'free', [
            'title'       => '비밀 제목',
            'content'     => '아무도 못 볼 본문입니다',
            'is_secret'   => true,
            'attachments' => [$image],
        ]);

        $body = $this->body($this->get($app, '/boards/free'));

        self::assertStringContainsString('비밀 제목', $body);
        self::assertStringNotContainsString('아무도 못 볼 본문입니다', $body);
        self::assertStringNotContainsString('/posts/' . $post['id'] . '/images/', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testGalleryLinksThumbnailAndServesItInline(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'free', 'name' => '자유게시판', 'use_file' => true, 'list_type' => 'gallery',
        ]);
        $image = $app->attachments()->upload($acl, 'free', $this->fakeUpload('사진.png', base64_decode(self::PNG)));
        $post = $app->postService()->create($acl, 'free', [
            'title' => '사진 글', 'content' => '본문', 'attachments' => [$image],
        ]);

        self::assertStringContainsString(
            '/posts/' . $post['id'] . '/images/0',
            $this->body($this->get($app, '/boards/free'))
        );

        $response = $this->get($app, '/posts/' . $post['id'] . '/images/0');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame('inline', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    /** 이미지가 아닌 첨부를 인라인으로 열어 주면 브라우저에서 실행될 여지가 생긴다. */
    #[DataProvider('connectionProvider')]
    public function testNonImageAttachmentIsNotServedInline(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판', 'use_file' => true]);
        $text = $app->attachments()->upload($acl, 'free', $this->fakeUpload('메모.txt', '안녕하세요'));
        $post = $app->postService()->create($acl, 'free', [
            'title' => '글', 'content' => '본문', 'attachments' => [$text],
        ]);

        self::assertSame(404, $this->get($app, '/posts/' . $post['id'] . '/images/0')->getStatusCode());
    }

    /** 목록에 사진이 없어도 갤러리·매거진은 깨지지 않고 대체 블록을 그린다. */
    #[DataProvider('connectionProvider')]
    public function testViewsRenderWithoutAnyImage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 'list');

        foreach (['list', 'gallery', 'magazine', 'news'] as $view) {
            $response = $this->get($app, '/boards/free', ['view' => $view]);

            self::assertSame(200, $response->getStatusCode(), $view);
            self::assertStringContainsString('글 제목 1', $this->body($response), $view);
        }
    }

    /**
     * 홈의 게시판 구역도 그 게시판의 목록 형태를 따른다.
     * 사진 게시판만 사진으로 나오고, 공지·소식은 글줄로 나와야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testHomeFeedFollowsEachBoardListType(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        foreach (['gallery', 'magazine', 'news', 'list'] as $type) {
            $app->boardService()->create($acl, [
                'board_key' => $type, 'name' => $type . ' 게시판', 'use_file' => true, 'list_type' => $type,
            ]);
            $image = $app->attachments()->upload($acl, $type, $this->fakeUpload('사진.png', base64_decode(self::PNG)));
            $app->postService()->create($acl, $type, [
                'title' => $type . ' 글', 'content' => '본문입니다', 'attachments' => [$image],
            ]);
        }

        $body = $this->body($this->get($app, '/'));
        $sections = [];
        foreach (['gallery', 'magazine', 'news', 'list'] as $type) {
            preg_match('~id="feed-' . $type . '".*?</section>~s', $body, $found);
            $sections[$type] = $found[0] ?? '';
        }

        self::assertStringContainsString('class="carousel"', $sections['gallery']);
        self::assertStringContainsString('post-rows feed-rows', $sections['magazine']);
        self::assertStringContainsString('post-rows-text', $sections['news']);
        self::assertStringContainsString('feed-lines', $sections['list']);

        // 사진은 갤러리·매거진에만 나온다.
        self::assertStringContainsString('post-cover-img', $sections['gallery']);
        self::assertStringContainsString('post-cover-img', $sections['magazine']);
        self::assertStringNotContainsString('post-cover-img', $sections['news']);
        self::assertStringNotContainsString('post-cover-img', $sections['list']);
    }

    /**
     * 배포 뒤 마이그레이션을 잊으면 목록 조회가 통째로 실패해 사이트가 멈춘다.
     * 부팅할 때 스스로 스키마를 맞추므로 그런 상태에서도 화면이 떠야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testBootHealsAnUnmigratedDatabase(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 'gallery');

        foreach ([['boards', 'list_type'], ['posts', 'image_key'], ['comments', 'image_key']] as [$table, $column]) {
            $app->db()->execute('ALTER TABLE ' . $app->db()->q($table) . ' DROP COLUMN ' . $column);
        }
        $app->db()->execute(
            'UPDATE ' . $app->db()->q('site_settings') . ' SET setting_value = ? WHERE setting_key = ?',
            ['0', 'schema_version']
        );

        foreach (['/', '/boards/free'] as $path) {
            self::assertSame(200, $this->get($app, $path)->getStatusCode(), $path);
        }
    }

    /** 기존 설치를 위한 컬럼 추가. 두 번 돌려도 안전해야 한다. */
    #[DataProvider('connectionProvider')]
    public function testMigrateBoardsAddsListTypeColumnAndIsIdempotent(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $schema = new Schema($app->db());

        $app->db()->execute('ALTER TABLE ' . $app->db()->q('boards') . ' DROP COLUMN list_type');

        $schema->migrateBoards();
        $schema->migrateBoards();

        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유게시판']);
        $board = $app->boardService()->get($app->guestAcl(), 'free');

        self::assertSame('list', $board['list_type']);
    }

    /**
     * 테마가 자체 posts/index.html.twig 를 갖고 있으면 디스패처를 직접 넣어야 파셜이 불린다.
     * classic(옛 default) 이 그렇게 만들어 둔 테마다 — 네 형태가 모두 살아 있는지 고정한다.
     */
    #[DataProvider('connectionProvider')]
    public function testThemeWithItsOwnIndexStillDispatchesEveryView(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['theme' => 'classic']);
        $this->seed($app, 'gallery');

        $markers = [
            'list'     => '<table>',
            'gallery'  => 'class="gallery-grid"',
            'magazine' => 'class="magazine-list surface"',
            'news'     => 'class="news-list surface"',
        ];
        foreach ($markers as $view => $marker) {
            $body = $this->body($this->get($app, '/boards/free', ['view' => $view]));

            self::assertStringContainsString('/themes/classic/theme.css', $body, $view);
            self::assertStringContainsString($marker, $body, $view);
        }
    }

    /** 관리 콘솔의 게시판 목록에서 각 게시판이 어떤 형태로 보이는지 한눈에 확인할 수 있어야 한다. */
    #[DataProvider('connectionProvider')]
    public function testAdminBoardListShowsListType(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'photo', 'name' => '사진', 'list_type' => 'gallery']);
        $app->boardService()->create($acl, ['board_key' => 'notice', 'name' => '공지', 'list_type' => 'news']);
        $this->loginAsAdmin($app);

        $body = $this->body($this->get($app, '/admin/boards'));

        self::assertStringContainsString('목록 형태', $body);
        self::assertStringContainsString('갤러리형', $body);
        self::assertStringContainsString('뉴스형', $body);
    }

    /**
     * 배포에서 형태 파셜 하나가 빠지는 일이 실제로 있었다. strict_variables 가 켜져 있어
     * 그때 화면이 통째로 500 이 됐다. 파셜이 없으면 목록형으로 떨어져야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testMissingViewPartialFallsBackToListInsteadOfFailing(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 'list');

        $partial = dirname(__DIR__, 2) . '/templates/default/posts/_list_magazine.html.twig';
        $hidden = $partial . '.hidden';
        self::assertFileExists($partial);
        rename($partial, $hidden);

        try {
            $response = $this->get($app, '/boards/free', ['view' => 'magazine']);
            $body = $this->body($response);

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('글 제목 1', $body);
        } finally {
            rename($hidden, $partial);
        }
    }

    private function loginAsAdmin(App $app): void
    {
        $id = $app->users()->create(
            'list-admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT),
            '목록 관리자',
            true
        );
        $app->users()->verifyEmail($id);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'email'      => 'list-admin@example.com',
            'password'   => 'admin-password-123',
        ]);
    }

    /**
     * 댓글 수는 어느 형태에서든 제목 바로 뒤에 붙어야 한다.
     * 제목이 블록이거나 flex 로 늘어나면 배지가 아랫줄이나 오른쪽 끝으로 밀려난다.
     */
    #[DataProvider('connectionProvider')]
    public function testCommentCountSitsRightAfterTheTitle(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판', 'perm_comment' => 'guest']);
        $post = $app->postService()->create($acl, 'free', ['title' => '댓글이 달린 글', 'content' => '본문']);
        $app->commentService()->create($acl, (int) $post['id'], ['content' => '댓글']);

        foreach (['list', 'gallery', 'magazine', 'news'] as $view) {
            $body = $this->body($this->get($app, '/boards/free', ['view' => $view]));
            // 제목과 배지 사이에는 닫는 태그와 공백만 올 수 있다.
            self::assertMatchesRegularExpression(
                '#댓글이 달린 글\s*(?:</a>\s*)?<span class="[^"]*comment-count#u',
                $body,
                $view . ' 형태에서 댓글 수가 제목에서 떨어졌다'
            );
        }
    }

    /**
     * 첨부 없이 편집기로만 사진을 넣은 글도 갤러리 목록에 썸네일이 나와야 한다.
     * 다만 본문에 적힌 아무 주소나 불러오면 방문자 정보가 다른 사이트로 새므로
     * 우리 편집기가 올린 사진만 쓴다.
     */
    #[DataProvider('connectionProvider')]
    public function testEditorImageBecomesTheGalleryThumbnail(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'gallery', 'name' => '갤러리', 'list_type' => 'gallery',
        ]);
        $image = '/media/editor/' . str_repeat('a', 32) . '/' . str_repeat('b', 32) . '.jpg';
        $app->postService()->create($acl, 'gallery', [
            'title' => '본문 사진 글', 'content' => '<p>사진</p><img src="' . $image . '">',
        ]);
        $app->postService()->create($acl, 'gallery', [
            'title' => '바깥 사진 글', 'content' => '<img src="https://example.com/spy.jpg">',
        ]);

        $body = $this->body($this->get($app, '/boards/gallery'));

        // 목록에는 원본이 아니라 카드 크기 축소본이 나가야 한다.
        self::assertStringContainsString(str_replace('.jpg', '-thumb.jpg', $image), $body);
        self::assertStringNotContainsString('"' . $image . '"', $body);
        self::assertStringNotContainsString('example.com/spy.jpg', $body);
    }

    /** 비밀글은 본문 사진도 목록에 내보내면 안 된다. */
    #[DataProvider('connectionProvider')]
    public function testSecretPostDoesNotLeakItsContentImage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'gallery', 'name' => '갤러리', 'list_type' => 'gallery',
            'use_secret' => '1', 'perm_write' => 'guest',
        ]);
        $image = '/media/editor/' . str_repeat('c', 32) . '/' . str_repeat('d', 32) . '.jpg';
        $app->postService()->create(new \GnuCms\Auth\Acl(\GnuCms\Auth\Identity::guest()), 'gallery', [
            'title' => '비밀 사진', 'content' => '<img src="' . $image . '">',
            'author_name' => '손님', 'password' => 'secret-pass-1', 'is_secret' => '1',
        ]);

        self::assertStringNotContainsString($image, $this->body($this->get($app, '/boards/gallery')));
    }

    /** 메인 노출 글 수를 0 으로 두면 그 게시판은 첫 화면에서 빠진다. 게시판 자체는 그대로 열린다. */
    #[DataProvider('connectionProvider')]
    public function testBoardCanBeKeptOffTheHomePage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'shown', 'name' => '보이는 게시판']);
        $app->boardService()->create($acl, ['board_key' => 'hidden', 'name' => '숨긴 게시판', 'home_limit' => '0']);
        $app->postService()->create($acl, 'shown', ['title' => '보이는 글', 'content' => '본문']);
        $app->postService()->create($acl, 'hidden', ['title' => '숨긴 글', 'content' => '본문']);

        $home = $this->body($this->get($app, '/'));

        self::assertStringContainsString('보이는 글', $home);
        self::assertStringNotContainsString('숨긴 글', $home);
        self::assertStringNotContainsString('숨긴 게시판', $home);
        // 첫 화면에서만 빠질 뿐 게시판은 그대로 열려야 한다.
        self::assertSame(200, $this->get($app, '/boards/hidden')->getStatusCode());
    }

    /** 메인에 낼 글 수를 줄이면 그만큼만 나온다. */
    #[DataProvider('connectionProvider')]
    public function testHomeShowsOnlyTheConfiguredNumberOfPosts(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'few', 'name' => '적게', 'home_limit' => '2']);
        for ($i = 1; $i <= 4; $i++) {
            $app->postService()->create($acl, 'few', ['title' => '글 번호 ' . $i, 'content' => '본문']);
        }

        $home = $this->body($this->get($app, '/'));

        // 최신 글부터 두 개만 (id 내림차순)
        self::assertStringContainsString('글 번호 4', $home);
        self::assertStringContainsString('글 번호 3', $home);
        self::assertStringNotContainsString('글 번호 2', $home);
        self::assertStringNotContainsString('글 번호 1', $home);
    }

    /** 컬럼이 없던 기존 설치도 부팅할 때 스스로 채우고, 그동안 메인이 멈추지 않는다. */
    #[DataProvider('connectionProvider')]
    public function testBoardsFromAnOlderInstallStillAppearOnHome(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'old', 'name' => '예전 게시판']);
        $app->postService()->create($acl, 'old', ['title' => '예전 글', 'content' => '본문']);

        // 마이그레이션 전 상태를 흉내낸다.
        $app->db()->execute('ALTER TABLE ' . $app->db()->q('boards') . ' DROP COLUMN home_limit');
        $app->db()->execute(
            'UPDATE ' . $app->db()->q('site_settings') . ' SET setting_value = ? WHERE setting_key = ?',
            ['3', 'schema_version']
        );

        self::assertStringContainsString('예전 글', $this->body($this->get($app, '/')));
        self::assertSame(
            self::DEFAULT_HOME_LIMIT,
            (int) $app->db()->selectOne(
                'SELECT home_limit AS v FROM ' . $app->db()->q('boards') . ' WHERE board_key = ?',
                ['old']
            )['v'],
            '컬럼을 더할 때 예전 동작(5개)을 그대로 이어야 한다'
        );
    }

    private function seed(App $app, string $listType): void
    {
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'free', 'name' => '자유게시판', 'list_type' => $listType,
        ]);
        for ($i = 1; $i <= 2; $i++) {
            $app->postService()->create($acl, 'free', ['title' => '글 제목 ' . $i, 'content' => '본문 ' . $i]);
        }
    }

    private function fakeUpload(string $name, string $contents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sbtest');
        file_put_contents($tmp, $contents);

        return [
            'name'     => $name,
            'type'     => 'application/octet-stream',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($contents),
        ];
    }
}
