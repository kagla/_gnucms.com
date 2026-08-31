<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminPageTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testGuestCannotOpenAdminPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        self::assertSame(401, $this->get($app, '/admin')->getStatusCode());
        self::assertSame(401, $this->get($app, '/admin/boards')->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testAdminEmailLogsInAndRendersDashboard(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['theme' => 'modern']);
        $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);

        $this->get($app, '/login');
        $login = $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'admin@example.com',
            'password' => 'admin-password-123',
        ]);
        $dashboard = $this->get($app, '/admin');

        self::assertSame(303, $login->getStatusCode());
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('class="admin-page"', $this->body($dashboard));
        self::assertStringNotContainsString('<body class="theme-modern">', $this->body($dashboard));
        // 기본 테마가 claude-sky(하늘빛)로 바뀌며 대시보드 제목이 인사말이 됐다.
        self::assertStringContainsString('님, 오늘도 반가워요</h1>', $this->body($dashboard));
        self::assertStringNotContainsString('<header class="site-header">', $this->body($dashboard));
        self::assertStringContainsString('class="admin-sidebar"', $this->body($dashboard));
        self::assertStringContainsString('>관리 콘솔</strong>', $this->body($dashboard));
        self::assertStringContainsString('admin-fold', $this->body($dashboard));
        self::assertStringContainsString('for="admin-drawer"', $this->body($dashboard));
        self::assertStringContainsString('class="admin-user"', $this->body($dashboard));
        self::assertStringContainsString('href="/admin" class="menu-active"', $this->body($dashboard));
        self::assertStringContainsString('회원 관리', $this->body($dashboard));
        self::assertStringContainsString('메일 설정', $this->body($dashboard));

        $mail = $this->get($app, '/admin/mail');
        self::assertSame(200, $mail->getStatusCode());
        self::assertStringContainsString('href="/admin/mail" class="menu-active"', $this->body($mail));
        self::assertStringContainsString('smtp.gmail.com', $this->body($mail));
        $saved = $this->post($app, '/admin/mail', [
            'csrf_token' => $_SESSION['csrf_token'], 'enabled' => '1', 'provider' => 'gmail',
            'host' => 'smtp.gmail.com', 'port' => '465', 'encryption' => 'ssl',
            'username' => 'owner@gmail.com', 'password' => 'google-app-password',
            'from_email' => 'owner@gmail.com', 'from_name' => GNUCMS,
        ]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/admin/mail?saved=1', $saved->getHeaderLine('Location'));
        self::assertStringNotContainsString('google-app-password', $this->body($this->get($app, '/admin/mail')));
    }

    #[DataProvider('connectionProvider')]
    public function testAdminCanCreateBoardFromWebForm(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com', 'password' => 'admin-password-123',
        ]);

        $boardsPage = $this->get($app, '/admin/boards');
        self::assertSame(200, $boardsPage->getStatusCode());
        self::assertStringContainsString('<h1>게시판 관리</h1>', $this->body($boardsPage));
        self::assertStringContainsString('href="/admin/boards" class="menu-active"', $this->body($boardsPage));
        self::assertStringContainsString('/admin/boards/new', $this->body($boardsPage));

        $response = $this->post($app, '/admin/boards/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'board_key' => 'notice', 'name' => '공지사항', 'description' => '중요한 소식',
            'categories_text' => "안내, 업데이트\n새 소식", 'managers_text' => '',
            'perm_read' => 'guest', 'perm_write' => 'admin', 'perm_comment' => 'member',
            'per_page' => '20', 'sort_order' => '-10', 'use_category' => '1',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/boards?saved=1', $response->getHeaderLine('Location'));
        $board = $app->boards()->findByKey('notice');
        self::assertSame('공지사항', $board['name']);
        self::assertSame(['안내, 업데이트', '새 소식'], $board['categories']);
        self::assertSame('admin', $board['perm_write']);
        $savedPage = $this->get($app, '/admin/boards?saved=1');
        self::assertStringContainsString('공지사항', $this->body($savedPage));
        self::assertStringContainsString('게시판 설정을 저장했습니다.', $this->body($savedPage));
    }

    #[DataProvider('connectionProvider')]
    public function testAdminCanEditMemberInformation(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $adminId = $app->users()->create(
            'admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT),
            '관리자',
            true
        );
        $app->users()->verifyEmail($adminId);
        $memberId = $app->users()->create(
            'member@example.com',
            password_hash('member-password-123', PASSWORD_DEFAULT),
            'member',
            false
        );
        $app->users()->verifyEmail($memberId);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'admin@example.com',
            'password' => 'admin-password-123',
        ]);

        $members = $this->get($app, '/admin/members');
        self::assertSame(200, $members->getStatusCode());
        self::assertStringContainsString('/admin/members/' . $memberId . '/edit', $this->body($members));
        $form = $this->get($app, '/admin/members/' . $memberId . '/edit');
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('>회원 수정</h1>', $this->body($form));

        $saved = $this->post($app, '/admin/members/' . $memberId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'new-member@example.com',
            'display_name' => '새표시이름',
            'status' => 'blocked',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/admin/members?saved=1', $saved->getHeaderLine('Location'));
        $updated = $app->users()->findById($memberId);
        self::assertSame('new-member@example.com', $updated['email']);
        self::assertSame('새표시이름', $updated['display_name']);
        self::assertSame('blocked', $updated['status']);

        $blockedOwner = $this->post($app, '/admin/members/' . $adminId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'admin@example.com',
            'display_name' => '관리자',
            'status' => 'blocked',
        ]);
        self::assertSame(422, $blockedOwner->getStatusCode());
        self::assertStringContainsString('현재 로그인한 관리자 계정은 차단할 수 없습니다.', $this->body($blockedOwner));
        self::assertSame('active', $app->users()->findById($adminId)['status']);
    }

    /** 회원 수정 화면에 가입 동의 내역이 붙는다. 동의하지 않은 항목도 함께 나온다. */
    #[DataProvider('connectionProvider')]
    public function testMemberFormShowsConsentHistory(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $adminId = $app->users()->create(
            'admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT),
            '관리자',
            true
        );
        $app->users()->verifyEmail($adminId);
        // 새 동의 저장소는 문서에 붙은 필드 대신 consent_uses 붙임으로 자리를 표현한다.
        foreach ([['terms', '이용약관', true], ['marketing', '마케팅 정보 수신', false]] as $doc) {
            $id = $app->cms()->createPage([
                'slug' => $doc[0], 'title' => $doc[1], 'content' => $doc[1] . ' 본문',
                'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0,
                'sort_order' => 0, 'is_consent' => 1,
            ]);
            $app->consentUses()->attach('signup', $id, $doc[2], 0);
        }
        $memberId = $app->users()->create(
            'member@example.com',
            password_hash('member-password-123', PASSWORD_DEFAULT),
            'member',
            false
        );
        $app->users()->verifyEmail($memberId);
        $app->consents()->record('user', $memberId, 'signup', $app->cms()->findBySlug('terms'), true, null);
        $app->consents()->record('user', $memberId, 'signup', $app->cms()->findBySlug('marketing'), false, null);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'admin@example.com',
            'password' => 'admin-password-123',
        ]);

        $body = $this->body($this->get($app, '/admin/members/' . $memberId . '/edit'));
        self::assertStringContainsString('가입 동의 내역', $body);
        self::assertStringContainsString('이용약관', $body);
        self::assertStringContainsString('마케팅 정보 수신', $body);
        self::assertStringContainsString('안 함', $body);
    }
    /** 관리자 자신의 비밀번호도 회원 수정에서 바꾼다. 바꾼 뒤에도 지금 세션은 살아 있어야 한다. */
    #[DataProvider('connectionProvider')]
    public function testAdminChangesOwnPasswordFromMemberForm(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $adminId = $app->users()->create(
            'admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT),
            '관리자',
            true
        );
        $app->users()->verifyEmail($adminId);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'admin@example.com',
            'password' => 'admin-password-123',
        ]);

        // 비워 두면 비밀번호는 그대로다.
        $kept = $this->post($app, '/admin/members/' . $adminId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com',
            'display_name' => '관리자', 'status' => 'active', 'password' => '', 'password_confirmation' => '',
        ]);
        self::assertSame(303, $kept->getStatusCode());
        self::assertTrue(password_verify('admin-password-123', (string) $app->users()->findById($adminId)['password_hash']));

        // 확인 칸이 다르면 막힌다.
        $mismatch = $this->post($app, '/admin/members/' . $adminId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com',
            'display_name' => '관리자', 'status' => 'active',
            'password' => 'new-password-456', 'password_confirmation' => 'other',
        ]);
        self::assertSame(422, $mismatch->getStatusCode());
        self::assertStringContainsString('비밀번호가 일치하지 않습니다', $this->body($mismatch));

        // 제대로 바꾸면 새 비밀번호가 들어가고, 지금 세션은 끊기지 않는다.
        $changed = $this->post($app, '/admin/members/' . $adminId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com',
            'display_name' => '관리자', 'status' => 'active',
            'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
        ]);
        self::assertSame(303, $changed->getStatusCode(), $this->body($changed));
        self::assertTrue(password_verify('new-password-456', (string) $app->users()->findById($adminId)['password_hash']));
        self::assertSame(200, $this->get($app, '/admin')->getStatusCode(), '내 비밀번호를 바꿔도 지금 세션은 살아 있어야 한다');

        // 옛 화면은 없다.
        self::assertSame(404, $this->get($app, '/admin/password')->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testSettingsPageShowsSchemaStatus(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com', 'password' => 'admin-password-123',
        ]);

        $body = $this->body($this->get($app, '/admin/settings'));

        self::assertStringContainsString('데이터 구조', $body);
        self::assertStringContainsString('<dt>판 번호</dt><dd>' . \GnuCms\Db\Schema::VERSION . ' ', $body);
        self::assertStringContainsString('설치 이후 없음', $body);
    }

    /** 옮긴 시각·백업 목록·비SQLite 안내, 세 갈래를 모두 확인한다. */
    #[DataProvider('connectionProvider')]
    public function testSettingsPageShowsSchemaBackupsAndUpgradedAt(array $dbConfig): void
    {
        $tmp = sys_get_temp_dir() . '/' . GNUCMS_ID . '-settings-' . bin2hex(random_bytes(4));
        $backupsDir = $tmp . '/backups';
        $older = $backupsDir . '/board-v8-20260101-000000.sqlite';
        $newer = $backupsDir . '/board-v9-20260201-000000.sqlite';

        try {
            mkdir($backupsDir, 0775, true);
            file_put_contents($older, 'x');
            touch($newer);

            $app = $this->makeApp($dbConfig, ['storage' => ['dir' => $tmp]]);
            $app->db()->execute(
                'INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
                ['schema_upgraded_at', '2026-08-30 01:02:03', '2026-08-30 01:02:03']
            );
            $app->db()->execute(
                'INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
                ['schema_backup', '/x/storage/backups/board-v9-20260201-000000.sqlite', '2026-08-30 01:02:03']
            );
            $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
            $app->users()->verifyEmail($id);
            $this->get($app, '/login');
            $this->post($app, '/login', [
                'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com', 'password' => 'admin-password-123',
            ]);

            $body = $this->body($this->get($app, '/admin/settings'));

            self::assertStringContainsString('2026-08-30 01:02:03 UTC', $body);
            self::assertStringContainsString('<dt>마지막 백업</dt><dd>board-v9-20260201-000000.sqlite</dd>', $body);

            if ($app->db()->dialect()->name() !== 'sqlite') {
                self::assertStringContainsString('앱이 백업하지 못합니다', $body);
                return;
            }

            self::assertStringContainsString('schema-backups', $body);
            self::assertStringNotContainsString('설치 이후 없음', $body);
            $newerPos = strpos($body, 'board-v9-20260201-000000.sqlite');
            $olderPos = strpos($body, 'board-v8-20260101-000000.sqlite');
            self::assertIsInt($newerPos);
            self::assertIsInt($olderPos);
            self::assertLessThan($olderPos, $newerPos, '최신 백업이 먼저 나와야 한다');
        } finally {
            @unlink($older);
            @unlink($newer);
            @rmdir($backupsDir);
            @rmdir($tmp);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testSettingsPageSavesAttachmentLimits(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com', 'password' => 'admin-password-123',
        ]);

        $page = $this->body($this->get($app, '/admin/settings'));
        self::assertStringContainsString('파일 첨부', $page);
        self::assertStringContainsString('name="attach_max_mb"', $page);
        self::assertStringContainsString('name="attach_limit"', $page);
        self::assertStringContainsString('0 = 무제한', $page);

        $base = [
            'csrf_token' => $_SESSION['csrf_token'],
            'site_name' => '사이트', 'site_tagline' => '소개', 'home_title' => '홈', 'home_intro' => '소개',
            'registration_enabled' => '1', 'theme' => 'default',
        ];
        $saved = $this->post($app, '/admin/settings', $base + ['attach_max_mb' => '20', 'attach_limit' => '0']);
        self::assertSame(303, $saved->getStatusCode());
        $settings = $app->cmsService()->settings();
        self::assertSame(20, $settings['attach_max_mb']);
        self::assertSame(0, $settings['attach_limit']);

        // Validator::int 는 범위를 벗어나면 실패가 아니라 잘라낸다(clamp)이므로
        // 여기서는 422 가 아니라 1024 로 잘린 값을 확인한다.
        $clamped = $this->post($app, '/admin/settings', $base + ['attach_max_mb' => '2000', 'attach_limit' => '5']);
        self::assertSame(303, $clamped->getStatusCode());
        self::assertSame(1024, $app->cmsService()->settings()['attach_max_mb']);
    }

    #[DataProvider('connectionProvider')]
    public function testAbandonedUploadsCanBeCleanedFromSettings(array $dbConfig): void
    {
        $this->purgeTestUploads();
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com', 'password' => 'admin-password-123',
        ]);
        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유', 'use_file' => true]);
        $abandoned = $app->attachments()->upload($this->adminAcl(), 'free', $this->fakeUpload('버려짐.txt', 'x'));
        touch($abandoned['path'], time() - 90000);

        $page = $this->body($this->get($app, '/admin/settings'));
        self::assertStringContainsString('버려진 파일 정리', $page);

        $cleaned = $this->post($app, '/admin/uploads/gc', ['csrf_token' => $_SESSION['csrf_token']]);
        self::assertSame(303, $cleaned->getStatusCode());
        self::assertStringContainsString('gc=1', $cleaned->getHeaderLine('Location'));
        self::assertFileDoesNotExist($abandoned['path']);

        $after = $this->body($this->get($app, '/admin/settings', ['gc' => '1']));
        self::assertStringContainsString('버려진 파일 1개를 정리했습니다', $after);

        // 정리할 게 없을 때(gc=0)는 성공 알림이 아니라 안내 문구를 보여야 한다.
        $cleanedAgain = $this->post($app, '/admin/uploads/gc', ['csrf_token' => $_SESSION['csrf_token']]);
        self::assertStringContainsString('gc=0', $cleanedAgain->getHeaderLine('Location'));
        $emptyGc = $this->body($this->get($app, '/admin/settings', ['gc' => '0']));
        self::assertStringContainsString('정리할 파일이 없습니다', $emptyGc);
        self::assertStringNotContainsString('버려진 파일 0개를 정리했습니다', $emptyGc);
    }

}
