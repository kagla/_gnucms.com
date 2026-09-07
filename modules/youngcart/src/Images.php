<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart;

use GnuCms\App;
use GnuCms\Cms\ImageResizer;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

final class Images
{
    public const MAX = 10;
    public const SIZES = ['main', 'list', 'type', 'search', 'related', 'detail', 'thumb', 'original'];
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    private const MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    private string $root;
    private string $cache;

    public function __construct(private App $app, private Settings $settings)
    {
        $this->root = rtrim((string) $app->config('uploads.dir', $app->storageDir() . '/uploads'), '/') . '/youngcart';
        $this->cache = rtrim($app->storageDir(), '/') . '/cache/youngcart';
    }

    public function directory(int $productId): string { return $this->root . '/' . $productId; }

    public function save(int $productId, UploadedFileInterface $upload): string
    {
        $maxBytes = max(1, (int) $this->app->cmsService()->settings()['attach_max_mb']) * 1048576;
        if ($upload->getError() !== UPLOAD_ERR_OK || ($upload->getSize() ?? 0) < 1 || ($upload->getSize() ?? 0) > $maxBytes) {
            throw DomainError::validation(['images' => ($maxBytes >> 20) . 'MB 이하의 JPG·PNG·WebP·GIF 이미지를 선택해 주세요.']);
        }
        $stream = $upload->getStream();
        if ($stream->isSeekable()) $stream->rewind();
        $bytes = $stream->read($maxBytes + 1);
        $info = @getimagesizefromstring($bytes);
        if (strlen($bytes) > $maxBytes || $info === false || !isset(self::TYPES[$info[2]]) || $info[0] > 8000 || $info[1] > 8000) {
            throw DomainError::validation(['images' => '가로·세로 8,000px 이하의 JPG·PNG·WebP·GIF 이미지를 선택해 주세요.']);
        }
        $extension = strtolower(pathinfo($upload->getClientFilename() ?? '', PATHINFO_EXTENSION));
        if ($extension === 'jpeg') $extension = 'jpg';
        if ($extension !== '' && $extension !== self::TYPES[$info[2]]) throw DomainError::validation(['images' => '파일 확장자와 이미지 형식이 다릅니다.']);
        $directory = $this->directory($productId);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw DomainError::serviceUnavailable('이미지 폴더를 만들지 못했습니다.');
        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$info[2]];
        if (file_put_contents($directory . '/' . $name, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($directory . '/' . $name);
            throw DomainError::serviceUnavailable('이미지를 저장하지 못했습니다.');
        }
        return $name;
    }

    public function delete(int $productId, string $filename): void
    {
        if (!self::validName($filename)) return;
        @unlink($this->directory($productId) . '/' . $filename);
        foreach (glob($this->cache . '/' . $productId . '/*-' . $filename) ?: [] as $cached) @unlink($cached);
    }

    public function deleteAll(int $productId): void
    {
        foreach ([$this->directory($productId), $this->cache . '/' . $productId] as $directory) {
            if (!is_dir($directory)) continue;
            foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
            @rmdir($directory);
        }
    }

    public function copy(int $from, int $to, string $filename): string
    {
        if (!self::validName($filename) || !is_file($this->directory($from) . '/' . $filename)) throw DomainError::notFound('이미지를 찾을 수 없습니다.');
        $directory = $this->directory($to);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw DomainError::serviceUnavailable('이미지 폴더를 만들지 못했습니다.');
        $name = bin2hex(random_bytes(16)) . '.' . pathinfo($filename, PATHINFO_EXTENSION);
        if (!copy($this->directory($from) . '/' . $filename, $directory . '/' . $name)) throw DomainError::serviceUnavailable('이미지를 복사하지 못했습니다.');
        return $name;
    }

    /** 크기별 최대 너비. 0은 원본. list는 분류 값이 있으면 그것을 쓴다. */
    public function width(string $size, ?array $category = null): int
    {
        $all = $this->settings->all();
        return match ($size) {
            'main' => max(array_column($all['main'], 'image_width') ?: [0]),
            'list' => $category === null ? (int) $all['category']['image_width'] : (int) $category['image_width'],
            'type' => (int) $all['type']['image_width'],
            'search' => (int) $all['search']['image_width'],
            'related' => (int) $all['related']['image_width'],
            'detail' => (int) $all['detail']['image_width'],
            'thumb' => 70,
            'original' => 0,
            default => throw DomainError::notFound('이미지 크기를 찾을 수 없습니다.'),
        };
    }

    public function response(int $productId, string $filename, string $size, ResponseInterface $response, ?int $width = null): ResponseInterface
    {
        $source = $this->directory($productId) . '/' . $filename;
        if (!self::validName($filename) || !in_array($size, self::SIZES, true) || !is_file($source)) throw DomainError::notFound('이미지를 찾을 수 없습니다.');
        $width ??= $this->width($size);
        $file = $source;
        if ($width > 0) {
            $target = $this->cache . '/' . $productId . '/' . $size . '-' . $width . '-' . $filename;
            if (!is_dir(dirname($target))) @mkdir(dirname($target), 0755, true);
            if ((new ImageResizer())->ensure($source, $target, $width)) $file = $target;
        }
        $response->getBody()->write((string) file_get_contents($file));
        return $response->withHeader('Content-Type', self::MIME[pathinfo($filename, PATHINFO_EXTENSION)])
            ->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Cache-Control', 'public, max-age=86400');
    }

    public static function url(string $publicUrl, int $productId, string $filename, string $size): string
    {
        return $publicUrl . '/image?p=' . $productId . '&f=' . rawurlencode($filename) . '&s=' . $size;
    }

    public static function validName(string $filename): bool
    {
        return preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/D', $filename) === 1;
    }
}
