<?php

declare(strict_types=1);

namespace GnuCms\Tests\Cms;

use GnuCms\Auth\Acl;
use GnuCms\Auth\Identity;
use GnuCms\Cms\ContentImageService;
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
}
