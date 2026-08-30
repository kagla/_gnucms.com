<?php

declare(strict_types=1);

namespace GnuCms\Tests\View;

use GnuCms\App;
use GnuCms\Support\Clock;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 같은 데이터로 Twig(default)와 PHP(native)를 그려 HTML 을 비교한다.
 * 이스케이프 누락, 조건 실수, 빠진 속성이 전부 여기서 드러난다. 목표는 차이 0.
 *
 * native 로 아직 옮기지 않은 화면은 '템플릿을 찾을 수 없습니다' 로 떨어진다.
 * 그 실패가 곧 남은 일 목록이다.
 *
 * normalize() 는 네 가지만 정규화한다: ?v= 캐시 해시, /themes/{name}/ 경로,
 * 공백, 그리고 image_key 입력에 담긴 32자리 16진 난수.
 */
final class ThemeParityTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 두 앱을 잇달아 세우므로 실제 시각을 쓰면 초가 넘어갈 때 시각 표기가 갈린다.
        Clock::freeze('2026-08-30 10:00:00');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    /** @return array<string, array{string, int, bool}> 경로, 기대 상태, 관리자 로그인 여부 */
    public static function routes(): array
    {
        $guest = [
            '/', '/boards/free', '/boards/free?view=gallery', '/boards/free?view=magazine',
            '/boards/free?view=news', '/boards/free?q=테스트&category=질문', '/boards/photo',
            '/boards/news', '/boards/mag', '/boards/free/write', '/posts/{post}', '/posts/{post}/edit',
            '/comments/{comment}/edit', '/content/about', '/terms/service', '/terms/privacy',
            '/login', '/register', '/forgot-password', '/reset-password?token=abc', '/health',
        ];
        $admin = [
            '/admin', '/admin/boards', '/admin/boards/new', '/admin/boards/free/edit', '/admin/posts',
            '/admin/posts?q=테스트&board=free', '/admin/members', '/admin/members/{admin}/edit',
            '/admin/content', '/admin/content/new', '/admin/content/{page}/edit',
            '/admin/content/{page}/preview', '/admin/content/trash', '/admin/settings', '/admin/mail',
            '/admin/terms', '/admin/terms/new', '/admin/password', '/notifications',
        ];
        $cases = [];
        foreach ($guest as $path) {
            $cases[$path] = [$path, 200, false];
        }
        $cases['/no-such-page'] = ['/no-such-page', 404, false];
        foreach ($admin as $path) {
            $cases[$path] = [$path, 200, true];
        }

        return $cases;
    }

    #[DataProvider('routes')]
    public function testRouteRendersTheSameInBothEngines(string $path, int $status, bool $asAdmin): void
    {
        // SQLite 만 쓴다. 이 테스트가 보는 것은 DB 가 아니라 두 템플릿 엔진의 HTML 이다.
        $dbConfig = self::connectionProvider()['sqlite'][0];

        $html = [];
        foreach (['default', 'native'] as $theme) {
            // $_SESSION 은 프로세스 전역이다. 앞 앱의 로그인이 새 수 있어 먼저 비운다.
            $_SESSION = [];
            $app = $this->makeApp($dbConfig);
            $app->cms()->saveSettings(['theme' => $theme, 'registration_enabled' => '1']);
            $ids = $this->seed($app);
            if ($asAdmin) {
                $this->loginAsAdmin($app, $ids['admin_email']);
            }
            $real = strtr($path, [
                '{post}' => (string) $ids['post'], '{comment}' => (string) $ids['comment'],
                '{page}' => (string) $ids['page'], '{admin}' => (string) $ids['admin'],
            ]);
            $response = $this->get($app, $real);
            self::assertSame($status, $response->getStatusCode(), $theme . ' ' . $real);
            $html[$theme] = $this->normalize($this->body($response), $theme);
        }
        self::assertSame($html['default'], $html['native'], $path);
    }

    /** 씨앗. smoke.php 와 같은 데이터. @return array{post:int,comment:int,page:int,admin:int,admin_email:string} */
    private function seed(App $app): array
    {
        $acl = $this->adminAcl();
        foreach ([
            ['free', '자유게시판', 'list'],
            ['photo', '사진첩', 'gallery'],
            ['news', '소식', 'news'],
            ['mag', '이야기', 'magazine'],
        ] as [$key, $name, $type]) {
            $app->boardService()->create($acl, [
                'board_key' => $key, 'name' => $name, 'list_type' => $type,
                'description' => $name . ' 설명입니다', 'use_category' => '1',
                'categories' => ['공지', '질문', '후기'], 'use_secret' => '1', 'use_file' => '1',
                'perm_write' => 'guest', 'perm_comment' => 'guest',
            ]);
        }
        $postId = null;
        for ($i = 1; $i <= 6; $i++) {
            $created = $app->postService()->create($acl, 'free', [
                'title' => "테스트 글 {$i}", 'content' => "<p>본문 {$i}</p>", 'category' => '질문',
                // 두 엔진이 같은 값을 받도록 무작위 대신 정해진 열쇠를 쓴다.
                'image_key' => $this->imageKey('free-' . $i),
            ]);
            $postId ??= (int) $created['id'];
        }
        $app->postService()->create($acl, 'photo', [
            'title' => '사진 글', 'content' => '<p>사진</p>', 'category' => '후기',
            'image_key' => $this->imageKey('photo'),
        ]);
        $comment = $app->commentService()->create($acl, $postId, [
            'content' => '댓글입니다', 'image_key' => $this->imageKey('comment'),
        ]);
        $pageId = $app->cmsService()->createPage($acl, [
            'title' => '소개', 'slug' => 'about', 'content' => '<p>소개 내용</p>',
            'status' => 'published', 'show_in_menu' => '1', 'image_key' => $this->imageKey('about'),
        ]);
        $app->cmsService()->ensureLegalDrafts($acl);
        // 약관은 초안으로 만들어진다. 공개 화면을 보려면 공개 상태로 올린다.
        foreach (['service', 'privacy'] as $slug) {
            $doc = $app->cms()->findBySlug($slug);
            if ($doc !== null) {
                $app->cmsService()->updatePage($acl, (int) $doc['id'], [
                    'title' => $doc['title'], 'slug' => $doc['slug'], 'content' => $doc['content'],
                    'status' => 'published', 'show_in_menu' => '0', 'sort_order' => '0',
                    'image_key' => $this->imageKey('terms-' . $slug),
                ]);
            }
        }
        $app->cms()->saveSettings(['registration_enabled' => '1']);

        $adminId = $app->users()->create(
            'admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT),
            '스튜디오 관리자',
            true
        );
        $app->users()->verifyEmail($adminId);

        return [
            'post' => (int) $postId,
            'comment' => (int) $comment['id'],
            'page' => (int) $pageId,
            'admin' => (int) $adminId,
            'admin_email' => 'admin@example.com',
        ];
    }

    /** 화면에 나오지 않는 값이지만, 두 앱이 같은 씨앗을 받도록 정해진 값으로 만든다. */
    private function imageKey(string $seed): string
    {
        return substr(hash('sha256', 'parity-' . $seed), 0, 32);
    }

    private function loginAsAdmin(App $app, string $email): void
    {
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '', 'email' => $email, 'password' => 'admin-password-123',
        ]);
    }

    /** 설계 3.7 의 네 가지만 정규화한다. */
    private function normalize(string $html, string $theme): string
    {
        $html = preg_replace('/\?v=[0-9a-f]{12}/', '?v=HASH', $html) ?? $html;
        $html = str_replace('/themes/' . $theme . '/', '/themes/THEME/', $html);
        $html = preg_replace('/[ \t]+$/m', '', $html) ?? $html;
        $html = preg_replace('/\n{2,}/', "\n", $html) ?? $html;
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;

        // image_key 는 렌더마다 새로 만드는 난수(bin2hex(random_bytes(16)))라 두 엔진이 같을 수 없다.
        // 값이 아니라 그 값이 있는 자리를 견준다: image_key 입력에 실제로 쓰인 32자리 16진수만 골라
        // (아무 32자리 16진 문자열이 아니라) 문서 전체에서 그 값의 모든 자리를 IMAGEKEY 로 바꾼다.
        // 인라인 JS(업로드 주소 등)에도 같은 값이 나올 수 있어 input 태그 밖도 함께 바꾼다.
        if (preg_match_all('/name="image_key" value="([a-f0-9]{32})"/', $html, $matches)) {
            foreach (array_unique($matches[1]) as $key) {
                $html = str_replace($key, 'IMAGEKEY', $html);
            }
        }

        return trim($html);
    }
}
