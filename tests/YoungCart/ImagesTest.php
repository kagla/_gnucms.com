<?php

declare(strict_types=1);

namespace GnuCms\Tests\YoungCart;

use GnuCms\Error\DomainError;
use GnuCms\Modules\YoungCart\Images;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

final class ImagesTest extends YoungCartTestCase
{
    #[DataProvider('connectionProvider')]
    public function testSaveResizeCopyAndDelete(array $config): void
    {
        $this->setupShop($config);
        $images = $this->shop->images;
        $name = $images->save(7, self::png(800, 400));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $name);
        self::assertFileExists($images->directory(7) . '/' . $name);
        self::assertSame(200, $images->width('list'));
        self::assertSame(150, $images->width('list', ['image_width' => 150]));
        self::assertSame(70, $images->width('thumb'));
        self::assertSame(0, $images->width('original'));
        $response = $images->response(7, $name, 'list', new Response());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('max-age=86400', $response->getHeaderLine('Cache-Control'));
        $response->getBody()->rewind();
        [$width] = getimagesizefromstring((string) $response->getBody());
        self::assertSame(200, $width);
        $response = $images->response(7, $name, 'original', new Response());
        $response->getBody()->rewind();
        self::assertSame(800, getimagesizefromstring((string) $response->getBody())[0]);
        self::assertSame('/shop/image?p=7&f=' . $name . '&s=thumb', Images::url('/shop', 7, $name, 'thumb'));
        $copy = $images->copy(7, 8, $name);
        self::assertFileExists($images->directory(8) . '/' . $copy);
        self::assertNotSame($name, $copy);
        try { $images->response(7, '../x.png', 'list', new Response()); self::fail(); } catch (DomainError $e) { self::assertSame(404, $e->status()); }
        try { $images->response(7, $name, 'huge', new Response()); self::fail(); } catch (DomainError $e) { self::assertSame(404, $e->status()); }
        try { $images->save(7, new UploadedFile((new StreamFactory())->createStream('not an image'), 'x.png', 'image/png', 12, UPLOAD_ERR_OK)); self::fail(); } catch (DomainError $e) { self::assertSame(422, $e->status()); }
        $images->delete(7, $name);
        self::assertFileDoesNotExist($images->directory(7) . '/' . $name);
        $images->deleteAll(8);
        self::assertDirectoryDoesNotExist($images->directory(8));
    }

    public static function png(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 30, 30));
        ob_start(); imagepng($image); $bytes = (string) ob_get_clean();
        return new UploadedFile((new StreamFactory())->createStream($bytes), 'photo.png', 'image/png', strlen($bytes), UPLOAD_ERR_OK);
    }
}
