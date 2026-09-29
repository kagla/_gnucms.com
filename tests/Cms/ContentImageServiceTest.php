<?php

declare(strict_types=1);

namespace GnuCms\Tests\Cms;

use GnuCms\Auth\Acl;
use GnuCms\Auth\Identity;
use GnuCms\Cms\ContentImageService;
use GnuCms\Error\DomainError;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;

final class ContentImageServiceTest extends TestCase
{
    private string $root;
    private ?string $storedPath = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/' . GNUCMS_ID . '-editor-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        if ($this->storedPath !== null) {
            @unlink($this->storedPath);
            @rmdir(dirname($this->storedPath));
            @rmdir(dirname($this->storedPath, 2));
        }
        @rmdir($this->root);
    }

    public function testAdminCanStoreAndReadAValidatedImage(): void
    {
        $key = bin2hex(random_bytes(16));
        $temporary = tempnam(sys_get_temp_dir(), GNUCMS_ID . '-png-');
        self::assertNotFalse($temporary);
        file_put_contents($temporary, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        ));
        $size = filesize($temporary);
        self::assertNotFalse($size);

        $service = new ContentImageService($this->root, 1024 * 1024);
        $saved = $service->upload(
            new Acl(Identity::user('1', '관리자', true)),
            new UploadedFile($temporary, 'pixel.png', 'image/png', $size, UPLOAD_ERR_OK, false),
            $key
        );
        $image = $service->ownedImage($saved['key'], $saved['file']);
        $this->storedPath = $image['path'];

        self::assertSame('image/png', $image['mime']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $saved['file']);
        self::assertFileExists($image['path']);

        $service->sync($key, '<img src="/media/editor/' . $key . '/' . $saved['file'] . '">');
        self::assertFileExists($image['path']);
        $service->discard(new Acl(Identity::user('1', '관리자', true)), $key, [$saved['file']]);
        self::assertFileDoesNotExist($image['path']);
        $this->storedPath = null;
    }

    /** 소유자 폴더(categories/10, products/123)와 첫 저장 전의 임시 폴더(tmp/<키>). 저장 때 임시 폴더를 소유자 폴더로 옮기고 본문 주소를 바꾼다. */
    public function testOwnerFoldersAndRelocation(): void
    {
        $service = new ContentImageService($this->root, 1024 * 1024);
        $acl = new Acl(Identity::user('1', '관리자', true));
        $tmp = 'tmp/' . bin2hex(random_bytes(16));
        $saved = $service->upload($acl, $this->png(), $tmp);
        self::assertSame($tmp, $saved['key']);
        self::assertFileExists($this->root . '/' . $tmp . '/' . $saved['file']);
        $html = '<p><img src="/media/editor/' . $tmp . '/' . $saved['file'] . '"></p>';
        $service->move($tmp, 'categories/10');
        $moved = ContentImageService::relocatedHtml($tmp, 'categories/10', $html);
        self::assertSame('<p><img src="/media/editor/categories/10/' . $saved['file'] . '"></p>', $moved);
        self::assertDirectoryDoesNotExist($this->root . '/' . $tmp);
        self::assertSame('image/png', $service->ownedImage('categories/10', $saved['file'])['mime']);
        $service->sync('categories/10', $moved);
        self::assertFileExists($this->root . '/categories/10/' . $saved['file']);
        $service->sync('categories/10', '');
        self::assertDirectoryDoesNotExist($this->root . '/categories/10');
        $service->upload($acl, $this->png(), 'products/123');
        $service->deleteFolder('products/123');
        self::assertDirectoryDoesNotExist($this->root . '/products/123');
        $service->move('tmp/' . bin2hex(random_bytes(16)), 'products/9'); // 없는 임시 폴더는 조용히 넘어간다
        foreach (['../x', 'Categories/10', 'categories/0', 'categories/', 'a/b/c', 'categories/abc', str_repeat('a', 31), 'tmp/' . str_repeat('g', 32), '/categories/10'] as $bad) {
            try {
                $service->sync($bad, '');
                self::fail($bad . ' 는 거절해야 한다');
            } catch (DomainError $e) {
                self::assertSame(422, $e->status(), $bad);
            }
        }
        foreach (['categories', 'products', 'tmp'] as $scope) @rmdir($this->root . '/' . $scope);
    }

    private function png(): UploadedFile
    {
        $temporary = tempnam(sys_get_temp_dir(), GNUCMS_ID . '-png-');
        file_put_contents($temporary, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        return new UploadedFile($temporary, 'pixel.png', 'image/png', (int) filesize($temporary), UPLOAD_ERR_OK, false);
    }
}
