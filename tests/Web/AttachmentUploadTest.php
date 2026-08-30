<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\UploadedFile;

final class AttachmentUploadTest extends WebTestCase
{
    /**
     * @param array $settings /login 요청보다 먼저 적용할 설정. Kernel 이 모든 요청에서
     *   CmsService::settings() 를 읽어 캐시해 버리므로, 설정을 로그인 뒤에 바꾸면 첫
     *   App::attachments() 호출이 캐시된 옛 값을 본다 — 그래서 로그인 전에 적용한다.
     * @return array{0: \GnuCms\App} 관리자 로그인이 끝난 앱
     */
    private function loggedInApp(array $dbConfig, bool $useFile = true, array $settings = []): \GnuCms\App
    {
        $app = $this->makeApp($dbConfig);
        if ($settings !== []) {
            $app->cms()->saveSettings($settings);
        }
        $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com', 'password' => 'admin-password-123',
        ]);
        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유', 'use_file' => $useFile]);

        return $app;
    }

    private function tmpFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'att-upload-');
        file_put_contents($path, $content);

        return $path;
    }

    #[DataProvider('connectionProvider')]
    public function testUploadReturnsSignedDescriptor(array $dbConfig): void
    {
        $app = $this->loggedInApp($dbConfig);
        $file = new UploadedFile($this->tmpFile('안녕'), '메모.txt', 'text/plain', 6);

        $response = $this->upload($app, '/boards/free/files?csrf_token=' . urlencode($_SESSION['csrf_token']), ['file' => $file]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode($this->body($response), true);
        self::assertSame('메모.txt', $data['name']);
        self::assertNotSame('', (string) $data['sig']);
        self::assertFileExists($data['path']);
        self::assertArrayHasKey('size_label', $data);
    }

    #[DataProvider('connectionProvider')]
    public function testBoardWithoutFilesRejectsAsJson(array $dbConfig): void
    {
        $app = $this->loggedInApp($dbConfig, false);
        $file = new UploadedFile($this->tmpFile('x'), 'a.txt', 'text/plain', 1);

        $response = $this->upload($app, '/boards/free/files?csrf_token=' . urlencode($_SESSION['csrf_token']), ['file' => $file]);

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode($this->body($response), true);
        self::assertStringContainsString('첨부를 쓰지 않습니다', $data['error']);
    }

    #[DataProvider('connectionProvider')]
    public function testOversizeIsRejectedAsJson413(array $dbConfig): void
    {
        $app = $this->loggedInApp($dbConfig, true, ['attach_max_mb' => '1']);
        $file = new UploadedFile($this->tmpFile(str_repeat('a', 1048577)), 'big.txt', 'text/plain', 1048577);

        $response = $this->upload($app, '/boards/free/files?csrf_token=' . urlencode($_SESSION['csrf_token']), ['file' => $file]);

        self::assertSame(413, $response->getStatusCode());
        self::assertArrayHasKey('error', json_decode($this->body($response), true));
    }

    #[DataProvider('connectionProvider')]
    public function testMissingCsrfIsForbidden(array $dbConfig): void
    {
        $app = $this->loggedInApp($dbConfig);
        $file = new UploadedFile($this->tmpFile('x'), 'a.txt', 'text/plain', 1);

        self::assertSame(403, $this->upload($app, '/boards/free/files', ['file' => $file])->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testMissingFileIsRejected(array $dbConfig): void
    {
        $app = $this->loggedInApp($dbConfig);

        $response = $this->upload($app, '/boards/free/files?csrf_token=' . urlencode($_SESSION['csrf_token']), []);

        self::assertSame(422, $response->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testRejectedUploadLeavesNoTempFile(array $dbConfig): void
    {
        // 이전 실행이 실패해서 남긴 찌꺼기가 있으면 아래 개수 비교가 흔들리므로
        // 테스트 시작 전에 먼저 치운다.
        foreach (glob(sys_get_temp_dir() . '/gnucms-att-*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        $before = count(glob(sys_get_temp_dir() . '/gnucms-att-*') ?: []);

        $app = $this->loggedInApp($dbConfig, false);
        $file = new UploadedFile($this->tmpFile('x'), 'a.txt', 'text/plain', 1);

        $response = $this->upload($app, '/boards/free/files?csrf_token=' . urlencode($_SESSION['csrf_token']), ['file' => $file]);

        self::assertSame(422, $response->getStatusCode());
        $after = count(glob(sys_get_temp_dir() . '/gnucms-att-*') ?: []);
        self::assertSame($before, $after, '거부된 업로드가 gnucms-att-* 임시 파일을 남겼습니다.');
    }
}
