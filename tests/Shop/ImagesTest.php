<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Images;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

final class ImagesTest extends ShopTestCase
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
        $cached = $this->root . '/cache/youngcart/7/list-200-' . $name;
        self::assertFileExists($cached);
        $response = $images->response(7, $name, 'list', new Response(), 100);
        $response->getBody()->rewind();
        [$narrowWidth] = getimagesizefromstring((string) $response->getBody());
        self::assertSame(100, $narrowWidth);
        $cachedNarrow = $this->root . '/cache/youngcart/7/list-100-' . $name;
        self::assertFileExists($cachedNarrow);
        self::assertFileExists($cached);
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
        $png = self::png(20, 20);
        $stream = $png->getStream();
        $stream->rewind();
        $mismatch = new UploadedFile((new StreamFactory())->createStream((string) $stream), 'photo.jpg', 'image/png', $png->getSize(), UPLOAD_ERR_OK);
        try { $images->save(7, $mismatch); self::fail(); } catch (DomainError $e) { self::assertSame(422, $e->status()); self::assertStringContainsString('확장자', $e->details()['images']); }
        try { $images->save(7, self::png(8001, 1)); self::fail(); } catch (DomainError $e) { self::assertSame(422, $e->status()); }
        $images->delete(7, $name);
        self::assertFileDoesNotExist($images->directory(7) . '/' . $name);
        self::assertFileDoesNotExist($cached);
        self::assertFileDoesNotExist($cachedNarrow);
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
