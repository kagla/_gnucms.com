<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\AvatarService;
use GnuCms\Error\DomainError;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;

final class AvatarServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/' . GNUCMS_ID . '-avatar-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->root);
    }

    public function testStoresReadsAndDeletesValidatedImage(): void
    {
        $temporary = $this->temporary(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        ));
        $service = new AvatarService($this->root);
        $file = $service->storeUpload(new UploadedFile(
            $temporary, 'pixel.png', 'image/png', filesize($temporary) ?: null, UPLOAD_ERR_OK, false
        ));

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $file);
        self::assertSame('image/png', $service->image($file)['mime']);
        $service->delete($file);
        self::assertFileDoesNotExist($this->root . '/' . $file);
    }

    public function testRejectsNonImageEvenWhenFilenameClaimsPng(): void
    {
        $temporary = $this->temporary('not an image');
        $service = new AvatarService($this->root);

        $this->expectException(DomainError::class);
        $service->storeUpload(new UploadedFile(
            $temporary, 'fake.png', 'image/png', filesize($temporary) ?: null, UPLOAD_ERR_OK, false
        ));
    }

    private function temporary(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), GNUCMS_ID . '-avatar-file-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);
        return $path;
    }
}
