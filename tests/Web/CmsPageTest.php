<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\UploadedFile;

final class CmsPageTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testPublishedPageAppearsInMenuAndDraftIsHidden(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->createPage([
            'slug' => 'about', 'title' => '소개', 'content' => '우리 사이트 소개입니다.',
            'seo_description' => null, 'status' => 'published', 'show_in_menu' => 1, 'sort_order' => 0,
        ]);
        $draftId = $app->cms()->createPage([
            'slug' => 'draft', 'title' => '초안', 'content' => '아직 공개하지 않습니다.',
            'seo_description' => null, 'status' => 'draft', 'show_in_menu' => 1, 'sort_order' => 1,
        ]);

        $home = $this->get($app, '/');
        self::assertSame(200, $home->getStatusCode());
        self::assertStringContainsString('href="/content/about"', $this->body($home));
        self::assertStringNotContainsString('href="/content/draft"', $this->body($home));
        self::assertSame(200, $this->get($app, '/content/about')->getStatusCode());
        self::assertSame(404, $this->get($app, '/content/draft')->getStatusCode());
        self::assertSame(401, $this->get($app, '/admin/content/' . $draftId . '/preview')->getStatusCode());
        $legacy = $this->get($app, '/page/about');
        self::assertSame(301, $legacy->getStatusCode());
        self::assertSame('/content/about', $legacy->getHeaderLine('Location'));
    }

    #[DataProvider('connectionProvider')]
    public function testRegistrationCanBeDisabled(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['registration_enabled' => '0']);

        self::assertSame(403, $this->get($app, '/register')->getStatusCode());
        self::assertStringNotContainsString('회원가입</a>', $this->body($this->get($app, '/')));
    }

    #[DataProvider('connectionProvider')]
    public function testOwnerCanCreatePageFromAdmin(array $dbConfig): void
    {
        $editorRoot = sys_get_temp_dir() . '/' . GNUCMS_ID . '-web-editor-' . bin2hex(random_bytes(5));
        $app = $this->makeApp($dbConfig, ['editor' => ['dir' => $editorRoot, 'max_bytes' => 1024 * 1024]]);
        $id = $app->users()->create('owner@example.com', password_hash('owner-password-123', PASSWORD_DEFAULT), '소유자', true);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'owner@example.com', 'password' => 'owner-password-123',
        ]);

        $settingsPage = $this->get($app, '/admin/settings');
        self::assertSame(200, $settingsPage->getStatusCode());
        self::assertStringContainsString('name="theme"', $this->body($settingsPage));
        self::assertStringContainsString('default (기본)', $this->body($settingsPage));
        $settingsSaved = $this->post($app, '/admin/settings', [
            'csrf_token' => $_SESSION['csrf_token'],
            'site_name' => GNUCMS,
            'site_tagline' => '가볍게 시작하는 기초 커뮤니티',
            'home_title' => '가볍게 시작하고, 오래 이어지는 공간',
            'home_intro' => '필요한 페이지와 커뮤니티를 한곳에서 운영하세요.',
            'theme' => 'default',
            'registration_enabled' => '1',
        ]);
        self::assertSame(303, $settingsSaved->getStatusCode());
        self::assertSame('default', $app->cms()->settings()['theme']);
        $documents = $this->get($app, '/admin/content');
        self::assertSame(200, $documents->getStatusCode());
        self::assertStringContainsString('<h1>내용 관리</h1>', $this->body($documents));
        self::assertStringNotContainsString('<h1>페이지 관리</h1>', $this->body($documents));
        self::assertSame(200, $this->get($app, '/admin/content/new')->getStatusCode());
        self::assertStringContainsString('/vendor/ckeditor4/ckeditor.js', $this->body($this->get($app, '/admin/content/new')));
        self::assertStringContainsString('data-cms-editor', $this->body($this->get($app, '/admin/content/new')));
        self::assertStringContainsString("input.multiple=true", $this->body($this->get($app, '/admin/content/new')));
        self::assertStringContainsString("items:['" . ucfirst(GNUCMS_ID) . "Images'", $this->body($this->get($app, '/admin/content/new')));
        self::assertStringContainsString('navigator.sendBeacon', $this->body($this->get($app, '/admin/content/new')));
        self::assertStringContainsString('data-uploaded-images', $this->body($this->get($app, '/admin/content/new')));
        self::assertStringContainsString('refreshStoredImages', $this->body($this->get($app, '/admin/content/new')));
        self::assertStringContainsString('getDocumentElement', $this->body($this->get($app, '/admin/content/new')));
        $createForm = $this->body($this->get($app, '/admin/content/new'));
        self::assertSame(1, preg_match('/name="image_key" value="([a-f0-9]{32})"/', $createForm, $keyMatch));
        $imageKey = $keyMatch[1];

        $temporaryImage = tempnam(sys_get_temp_dir(), GNUCMS_ID . '-web-png-');
        self::assertNotFalse($temporaryImage);
        file_put_contents($temporaryImage, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        ));
        $imageSize = filesize($temporaryImage);
        self::assertNotFalse($imageSize);
        $imageUpload = $this->upload(
            $app,
            '/admin/editor/images?csrf_token=' . rawurlencode($_SESSION['csrf_token'])
                . '&image_key=' . $imageKey,
            ['upload' => new UploadedFile($temporaryImage, 'pixel.png', 'image/png', $imageSize)]
        );
        self::assertSame(200, $imageUpload->getStatusCode());
        $uploadedImage = json_decode($this->body($imageUpload), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $uploadedImage['uploaded']);
        self::assertSame(200, $this->get($app, $uploadedImage['url'])->getStatusCode());
        $storedImage = $editorRoot . substr($uploadedImage['url'], strlen('/media/editor'));

        $discardTemporary = tempnam(sys_get_temp_dir(), GNUCMS_ID . '-discard-png-');
        self::assertNotFalse($discardTemporary);
        file_put_contents($discardTemporary, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        ));
        $discardSize = filesize($discardTemporary);
        self::assertNotFalse($discardSize);
        $discardUpload = $this->upload(
            $app,
            '/admin/editor/images?csrf_token=' . rawurlencode($_SESSION['csrf_token'])
                . '&image_key=' . $imageKey,
            ['upload' => new UploadedFile($discardTemporary, 'discard.png', 'image/png', $discardSize)]
        );
        $discardImage = json_decode($this->body($discardUpload), true, 512, JSON_THROW_ON_ERROR);
        $discardPath = $editorRoot . substr($discardImage['url'], strlen('/media/editor'));
        self::assertFileExists($discardPath);
        $discard = $this->post(
            $app,
            '/admin/editor/images/discard?csrf_token=' . rawurlencode($_SESSION['csrf_token'])
                . '&image_key=' . $imageKey,
            ['files' => [$discardImage['fileName']]]
        );
        self::assertSame(204, $discard->getStatusCode());
        self::assertFileDoesNotExist($discardPath);
        $legacy = $this->get($app, '/admin/pages');
        self::assertSame(301, $legacy->getStatusCode());
        self::assertSame('/admin/content', $legacy->getHeaderLine('Location'));
        $legacyDocuments = $this->get($app, '/admin/documents');
        self::assertSame(301, $legacyDocuments->getStatusCode());
        self::assertSame('/admin/content', $legacyDocuments->getHeaderLine('Location'));

        $legalSetup = $this->post($app, '/admin/terms/setup', [
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
        self::assertSame(303, $legalSetup->getStatusCode());
        self::assertSame('draft', $app->cms()->findBySlug('terms')['status']);
        self::assertSame('draft', $app->cms()->findBySlug('privacy')['status']);
        $legalPage = $this->get($app, '/admin/terms');
        self::assertSame(200, $legalPage->getStatusCode());
        self::assertStringContainsString('<h1>약관 관리</h1>', $this->body($legalPage));
        self::assertStringContainsString('이용약관', $this->body($legalPage));
        self::assertStringContainsString('/admin/terms/service', $this->body($legalPage));
        self::assertStringContainsString('>/content/terms<', $this->body($legalPage));
        // 약관은 약관 관리에서 다루므로 내용 관리 목록에는 나오지 않는다.
        self::assertStringNotContainsString('이용약관', $this->body($this->get($app, '/admin/content')));
        $terms = $app->cms()->findBySlug('terms');
        self::assertSame(200, $this->get($app, '/admin/terms/service')->getStatusCode());
        self::assertSame(200, $this->get($app, '/admin/terms/service/preview')->getStatusCode());
        self::assertSame(200, $this->get($app, '/admin/content/' . $terms['id'] . '/edit')->getStatusCode());
        $termsSaved = $this->post($app, '/admin/terms/service', [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '이용약관',
            'content' => '검토를 마친 이용약관', 'seo_description' => '약관', 'status' => 'published',
            'sort_order' => '900',
        ]);
        self::assertSame(303, $termsSaved->getStatusCode(), $this->body($termsSaved));
        self::assertSame('/admin/terms?saved=1', $termsSaved->getHeaderLine('Location'));
        self::assertSame(200, $this->get($app, '/content/terms')->getStatusCode());
        self::assertSame(301, $this->get($app, '/terms/service')->getStatusCode());
        self::assertSame('/content/terms', $this->get($app, '/terms/service')->getHeaderLine('Location'));
        self::assertSame(301, $this->get($app, '/admin/legal')->getStatusCode());
        self::assertSame('/admin/terms', $this->get($app, '/admin/legal')->getHeaderLine('Location'));

        $draftId = $app->cms()->createPage([
            'slug' => 'private-note', 'title' => '비공개 안내', 'content' => '관리자 미리보기',
            'seo_description' => null, 'status' => 'draft', 'show_in_menu' => 0, 'sort_order' => 0,
        ]);
        $draftPreview = $this->get($app, '/admin/content/' . $draftId . '/preview');
        self::assertSame(200, $draftPreview->getStatusCode());
        self::assertStringContainsString('아직 공개되지 않은 초안 미리보기', $this->body($draftPreview));

        $response = $this->post($app, '/admin/content/new', [
            'csrf_token' => $_SESSION['csrf_token'], 'slug' => 'guide', 'title' => '이용안내',
            'content' => '<p>간단한 이용안내입니다.</p><img src="' . $uploadedImage['url'] . '">',
            'status' => 'published', 'show_in_menu' => '1', 'sort_order' => '10',
            'image_key' => $imageKey,
        ]);

        self::assertSame(303, $response->getStatusCode());
        $saved = $app->cms()->findPublishedBySlug('guide');
        self::assertSame('이용안내', $saved['title']);
        self::assertSame($imageKey, $saved['image_key']);
        $preview = $this->get($app, '/admin/content/' . $saved['id'] . '/preview');
        self::assertSame(200, $preview->getStatusCode());
        self::assertStringContainsString('공개된 내용 미리보기', $this->body($preview));

        $deleted = $this->post($app, '/admin/content/' . $saved['id'] . '/delete', [
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/admin/content?deleted=1', $deleted->getHeaderLine('Location'));
        self::assertNull($app->cms()->findPageById((int) $saved['id']));
        self::assertNotNull($app->cms()->findDeletedPageById((int) $saved['id']));
        self::assertFileExists($storedImage, '휴지통에서는 복원을 위해 이미지를 보존해야 한다.');
        self::assertSame(404, $this->get($app, '/content/guide')->getStatusCode());

        $trash = $this->get($app, '/admin/content/trash');
        self::assertSame(200, $trash->getStatusCode());
        self::assertStringContainsString('이용안내', $this->body($trash));
        $restored = $this->post($app, '/admin/content/trash/' . $saved['id'] . '/restore', [
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
        self::assertSame(303, $restored->getStatusCode());
        self::assertSame('/admin/content/trash?restored=1', $restored->getHeaderLine('Location'));
        self::assertSame('draft', $app->cms()->findPageById((int) $saved['id'])['status']);
        self::assertNull($app->cms()->findDeletedPageById((int) $saved['id']));
        self::assertSame(404, $this->get($app, '/content/guide')->getStatusCode());

        $this->post($app, '/admin/content/' . $saved['id'] . '/delete', [
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
        $permanent = $this->post($app, '/admin/content/trash/' . $saved['id'] . '/delete', [
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
        self::assertSame(303, $permanent->getStatusCode());
        self::assertSame('/admin/content/trash?deleted=1', $permanent->getHeaderLine('Location'));
        self::assertNull($app->cms()->findDeletedPageById((int) $saved['id']));
        self::assertFileDoesNotExist($storedImage, '완전 삭제하면 콘텐츠 전용 폴더의 이미지도 삭제해야 한다.');
        @rmdir($editorRoot);
    }
}
