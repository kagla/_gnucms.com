<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\Tests\Support\WebTestCase;

final class AttachmentDownloadTest extends WebTestCase
{
    /** @dataProvider connectionProvider */
    public function testAttachmentIsDownloadable(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'free',
            'name'      => '자유게시판',
            'use_file'  => true,
        ]);

        $descriptor = $app->attachments()->upload($acl, 'free', $this->fakeUpload('메모.txt', '안녕하세요'));
        $post = $app->postService()->create($acl, 'free', [
            'title'       => '글',
            'content'     => '본문',
            'attachments' => [$descriptor],
        ]);

        $response = $this->get($app, '/p/' . $post['id'] . '/files/0');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('안녕하세요', $this->body($response));
        self::assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));
        // 한글 파일명은 RFC 5987 형식으로만 온전히 전달된다.
        self::assertStringContainsString("filename*=UTF-8''" . rawurlencode('메모.txt'), $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        // Content-Type 은 HtmlContentTypeMiddleware 가 이미 정해진 값을 건드리지 않는다는
        // 것을 확인하는 자리다. 다운로드가 text/html 로 오면 그 가드가 깨진 것이다.
        self::assertSame($descriptor['mime'], $response->getHeaderLine('Content-Type'));
        self::assertNotSame('text/html', $response->getHeaderLine('Content-Type'));
    }

    /** @dataProvider connectionProvider */
    public function testUnknownIndexRendersNotFoundPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        $post = $app->postService()->create($acl, 'free', ['title' => '글', 'content' => '본문']);

        self::assertSame(404, $this->get($app, '/p/' . $post['id'] . '/files/7')->getStatusCode());
    }

    protected function tearDown(): void
    {
        $dir = sys_get_temp_dir() . '/apiboard-test-uploads';
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

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
