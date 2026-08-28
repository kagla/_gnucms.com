<?php

declare(strict_types=1);

namespace GnuCms\Cms;

/**
 * 본문에 보여 줄 축소본을 만든다.
 *
 * 원본을 그대로 내려보내면 글 한 편에 수 MB 가 오간다. 화면에 필요한 만큼만 줄여
 * 따로 저장해 두고, 원본은 눌렀을 때만 받아 가게 한다.
 */
final class ImageResizer
{
    /** 본문 폭에 맞춘 크기. 이 값만 허용해 임의의 크기 요청으로 서버를 갈아 넣지 못하게 한다. */
    public const CONTENT_WIDTH = 960;

    /** 이보다 픽셀이 많은 그림은 메모리를 너무 먹으므로 건드리지 않고 원본을 준다. */
    private const MAX_PIXELS = 40000000;

    private const QUALITY_JPEG = 82;
    private const QUALITY_WEBP = 80;

    /**
     * 축소본을 만들어 $target 에 저장한다.
     *
     * 이미 있으면 그대로 두고, 줄일 필요가 없거나 줄일 수 없으면 false 를 준다.
     * false 는 잘못이 아니라 "원본을 그대로 쓰라"는 뜻이다.
     */
    public function ensure(string $source, string $target, int $maxWidth): bool
    {
        if (is_file($target) && filemtime($target) >= (int) filemtime($source)) {
            return true;
        }
        if (!extension_loaded('gd') || !is_file($source)) {
            return false;
        }

        $info = @getimagesize($source);
        if ($info === false) {
            return false;
        }
        [$width, $height] = $info;
        if ($width <= 0 || $height <= 0 || $width <= $maxWidth) {
            return false;
        }
        if ($width * $height > self::MAX_PIXELS) {
            return false;
        }
        // 움직이는 GIF 는 줄이면 첫 장만 남는다. 원본을 그대로 두는 편이 낫다.
        if ($info[2] === IMAGETYPE_GIF && $this->isAnimatedGif($source)) {
            return false;
        }

        $image = $this->read($source, $info[2]);
        if ($image === null) {
            return false;
        }

        $targetHeight = max(1, (int) round($height * ($maxWidth / $width)));
        $small = imagecreatetruecolor($maxWidth, $targetHeight);
        if ($info[2] === IMAGETYPE_PNG || $info[2] === IMAGETYPE_GIF || $info[2] === IMAGETYPE_WEBP) {
            imagealphablending($small, false);
            imagesavealpha($small, true);
            $transparent = imagecolorallocatealpha($small, 0, 0, 0, 127);
            imagefilledrectangle($small, 0, 0, $maxWidth, $targetHeight, $transparent);
        }
        imagecopyresampled($small, $image, 0, 0, 0, 0, $maxWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($small);

            return false;
        }

        // 반쯤 쓰다 만 파일이 남지 않도록 임시 이름으로 쓰고 옮긴다.
        $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $written = $this->write($small, $temporary, $info[2]);
        imagedestroy($small);

        if (!$written) {
            @unlink($temporary);

            return false;
        }
        if (!rename($temporary, $target)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /** @return \GdImage|null */
    private function read(string $path, int $type)
    {
        $image = null;
        if ($type === IMAGETYPE_JPEG) {
            $image = @imagecreatefromjpeg($path);
        } elseif ($type === IMAGETYPE_PNG) {
            $image = @imagecreatefrompng($path);
        } elseif ($type === IMAGETYPE_GIF) {
            $image = @imagecreatefromgif($path);
        } elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($path);
        }

        return $image === false ? null : $image;
    }

    private function write($image, string $path, int $type): bool
    {
        if ($type === IMAGETYPE_JPEG) {
            return @imagejpeg($image, $path, self::QUALITY_JPEG);
        }
        if ($type === IMAGETYPE_PNG) {
            return @imagepng($image, $path, 8);
        }
        if ($type === IMAGETYPE_GIF) {
            return @imagegif($image, $path);
        }
        if ($type === IMAGETYPE_WEBP && function_exists('imagewebp')) {
            return @imagewebp($image, $path, self::QUALITY_WEBP);
        }

        return false;
    }

    /** 프레임을 나타내는 블록이 둘 이상이면 움직이는 그림이다. */
    private function isAnimatedGif(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $frames = 0;
        $chunk = '';
        while (!feof($handle) && $frames < 2) {
            $chunk = substr($chunk, -20) . (string) fread($handle, 8192);
            $frames += preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk);
        }
        fclose($handle);

        return $frames > 1;
    }
}
