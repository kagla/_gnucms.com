<?php

declare(strict_types=1);

namespace StandardBoard\Tests\Api;

use StandardBoard\App;
use StandardBoard\Http\FileResponse;
use StandardBoard\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use StandardBoard\Tests\Support\ApiTestCase;

final class AttachmentApiTest extends ApiTestCase
{
    #[DataProvider('connectionProvider')]
    public function testUploadReturnsSignedDescriptor(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);

        $descriptor = $app->attachments()->upload(
            $app->aclFor($this->authed($app, 'user-1', '홍길동')),
            'free',
            $this->fakeUpload('메모.txt', '안녕하세요')
        );

        $this->assertSame('메모.txt', $descriptor['name']);
        $this->assertNotSame('', $descriptor['sig']);
        $this->assertFileExists($descriptor['path']);
    }

    #[DataProvider('connectionProvider')]
    public function testDisallowedExtensionIsRejected(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);

        $this->expectException(\StandardBoard\Http\ApiError::class);
        $app->attachments()->upload(
            $app->aclFor($this->authed($app, 'user-1', '홍길동')),
            'free',
            $this->fakeUpload('shell.php', '<?php echo 1;')
        );
    }

    #[DataProvider('connectionProvider')]
    public function testOversizedFileIsRejectedWith413(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);

        try {
            $app->attachments()->upload(
                $app->aclFor($this->authed($app, 'user-1', '홍길동')),
                'free',
                $this->fakeUpload('큰파일.txt', str_repeat('x', 1024 * 1024 + 1))
            );
            $this->fail('413 이 나와야 한다');
        } catch (\StandardBoard\Http\ApiError $e) {
            $this->assertSame(413, $e->status());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testTamperedDescriptorIsRejected(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $token = $this->tokenFor($app, 'user-1', '홍길동', false);
        $descriptor = $app->attachments()->upload(
            $app->aclFor($this->authed($app, 'user-1', '홍길동')),
            'free',
            $this->fakeUpload('메모.txt', '내용')
        );
        $descriptor['name'] = '조작된이름.txt';

        $response = $this->call($app, 'POST', '/boards/free/posts', [
            'title' => '글', 'content' => '본문', 'attachments' => [$descriptor],
        ], $token);

        $this->assertSame(422, $response->status());
    }

    #[DataProvider('connectionProvider')]
    public function testAttachmentSurvivesPostCreationAndIsDownloadable(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $token = $this->tokenFor($app, 'user-1', '홍길동', false);
        $descriptor = $app->attachments()->upload(
            $app->aclFor($this->authed($app, 'user-1', '홍길동')),
            'free',
            $this->fakeUpload('메모.txt', '안녕하세요')
        );

        $created = $this->call($app, 'POST', '/boards/free/posts', [
            'title' => '글', 'content' => '본문', 'attachments' => [$descriptor],
        ], $token);

        $this->assertSame(201, $created->status());
        $files = $created->payload()['data']['attachments'];
        $this->assertCount(1, $files);
        $this->assertSame('메모.txt', $files[0]['name']);
        $this->assertArrayNotHasKey('sig', $files[0]);
        $this->assertArrayNotHasKey('path', $files[0]);

        $postId = (int) $created->payload()['data']['id'];
        $download = $this->call($app, 'GET', '/posts/' . $postId . '/files/0');

        $this->assertInstanceOf(FileResponse::class, $download);
        $this->assertSame(200, $download->status());
    }

    #[DataProvider('connectionProvider')]
    public function testDownloadOfSecretPostIsDeniedToStrangers(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app, ['use_secret' => true]);
        $author = $this->tokenFor($app, 'user-1', '홍길동', false);
        $descriptor = $app->attachments()->upload(
            $app->aclFor($this->authed($app, 'user-1', '홍길동')),
            'free',
            $this->fakeUpload('비밀.txt', '민감')
        );
        $postId = (int) $this->call($app, 'POST', '/boards/free/posts', [
            'title' => '비밀', 'content' => '본문', 'is_secret' => true, 'attachments' => [$descriptor],
        ], $author)->payload()['data']['id'];

        $denied = $this->call($app, 'GET', '/posts/' . $postId . '/files/0', [],
            $this->tokenFor($app, 'user-9', '남', false));

        $this->assertSame(403, $denied->status());
    }

    #[DataProvider('connectionProvider')]
    public function testUnknownFileIndexGives404(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $token = $this->tokenFor($app, 'user-1', '홍길동', false);
        $postId = (int) $this->call($app, 'POST', '/boards/free/posts',
            ['title' => '글', 'content' => '본문'], $token)->payload()['data']['id'];

        $this->assertSame(404, $this->call($app, 'GET', '/posts/' . $postId . '/files/7')->status());
    }

    #[DataProvider('connectionProvider')]
    public function testUploadDeniedWhenBoardDoesNotAllowFiles(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app, ['use_file' => false]);

        try {
            $app->attachments()->upload(
                $app->aclFor($this->authed($app, 'user-1', '홍길동')),
                'free',
                $this->fakeUpload('메모.txt', '내용')
            );
            $this->fail('거부되어야 한다');
        } catch (\StandardBoard\Http\ApiError $e) {
            $this->assertSame(422, $e->status());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testGarbageCollectionRemovesUnreferencedFilesOnly(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $token = $this->tokenFor($app, 'user-1', '홍길동', false);
        $acl = $app->aclFor($this->authed($app, 'user-1', '홍길동'));

        $used = $app->attachments()->upload($acl, 'free', $this->fakeUpload('쓰는파일.txt', '내용'));
        $orphan = $app->attachments()->upload($acl, 'free', $this->fakeUpload('버려진파일.txt', '내용'));

        $this->call($app, 'POST', '/boards/free/posts', [
            'title' => '글', 'content' => '본문', 'attachments' => [$used],
        ], $token);

        $result = $app->attachments()->collectGarbage($app->aclFor($this->authed($app, 'root', '관리자', true)));

        $this->assertSame(1, $result['deleted']);
        $this->assertFileExists($used['path']);
        $this->assertFileDoesNotExist($orphan['path']);
    }

    #[DataProvider('connectionProvider')]
    public function testGarbageCollectionKeepsDotFiles(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);
        $dir = sys_get_temp_dir() . '/standard-board-test-uploads';
        @mkdir($dir, 0775, true);
        $keep = $dir . '/.gitkeep';
        file_put_contents($keep, '');

        $app->attachments()->collectGarbage($app->aclFor($this->authed($app, 'root', '관리자', true)));

        // 배포물에 포함되는 자리표시자다. 업로드 파일은 항상 32자리 16진수라
        // 점으로 시작하는 파일을 건너뛰어도 진짜 고아를 놓치지 않는다.
        $this->assertFileExists($keep);
    }

    #[DataProvider('connectionProvider')]
    public function testGarbageCollectionRequiresGlobalAdmin(array $config): void
    {
        $app = $this->makeApp($config);
        $this->board($app);

        $response = $this->call($app, 'POST', '/maintenance/gc', [],
            $this->tokenFor($app, 'user-1', '회원', false));

        $this->assertSame(403, $response->status());
    }

    protected function tearDown(): void
    {
        $dir = sys_get_temp_dir() . '/standard-board-test-uploads';
        if (is_dir($dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($dir);
        }
    }

    private function board(App $app, array $overrides = []): void
    {
        $this->call($app, 'POST', '/boards', array_merge([
            'board_key' => 'free', 'name' => '자유게시판', 'use_file' => true,
        ], $overrides), $this->adminToken($app));
    }

    private function authed(App $app, string $sub, string $name, bool $admin = false): Request
    {
        return new Request('POST', '/uploads', [], [], [
            'Authorization' => 'Bearer ' . $this->tokenFor($app, $sub, $name, $admin),
        ], []);
    }

    /** $_FILES 한 항목과 같은 모양을 만든다. */
    private function fakeUpload(string $name, string $contents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sbtest');
        file_put_contents($tmp, $contents);

        return [
            'name'     => $name,
            'type'     => 'text/plain',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($contents),
        ];
    }
}
