<?php

declare(strict_types=1);

namespace ApiBoard\Cms;

use ApiBoard\Auth\Acl;
use ApiBoard\Error\DomainError;
use Psr\Http\Message\UploadedFileInterface;

final class ContentImageService
{
    private string $root;
    private int $maxBytes;

    public function __construct(string $root, int $maxBytes = 5242880)
    {
        $this->root = rtrim($root, '/');
        $this->maxBytes = $maxBytes;
    }

    /** @return array{key:string, file:string} */
    public function upload(Acl $acl, UploadedFileInterface $upload, string $key): array
    {
        $acl->assertGlobalAdmin();
        $this->assertKey($key);
        $error = $upload->getError();
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw DomainError::tooLarge('이미지 파일이 너무 큽니다.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw DomainError::validation(['upload' => '이미지 업로드에 실패했습니다.']);
        }
        $size = (int) ($upload->getSize() ?? 0);
        if ($size < 1 || $size > $this->maxBytes) {
            throw DomainError::tooLarge('이미지는 5MB 이하만 올릴 수 있습니다.');
        }

        $directory = $this->root . '/' . $key;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw DomainError::internal('이미지 저장 폴더를 만들 수 없습니다.');
        }

        $id = bin2hex(random_bytes(16));
        $temporaryPath = $directory . '/' . $id . '.upload';
        $upload->moveTo($temporaryPath);
        $mime = $this->detectMime($temporaryPath);
        $extensions = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        ];
        if (!isset($extensions[$mime])) {
            @unlink($temporaryPath);
            throw DomainError::validation(['upload' => 'JPG, PNG, GIF, WebP 이미지만 올릴 수 있습니다.']);
        }

        $file = $id . '.' . $extensions[$mime];
        if (!rename($temporaryPath, $directory . '/' . $file)) {
            @unlink($temporaryPath);
            throw DomainError::internal('이미지를 저장하지 못했습니다.');
        }

        return ['key' => $key, 'file' => $file];
    }

    /** @return array{path:string, mime:string} */
    public function ownedImage(string $key, string $file): array
    {
        $this->assertKey($key);
        $this->assertFile($file);
        return $this->readImage($this->root . '/' . $key . '/' . $file, $file);
    }

    /** @return array{path:string, mime:string} */
    public function image(string $year, string $month, string $file): array
    {
        if (preg_match('/^\d{4}$/D', $year) !== 1 || preg_match('/^(?:0[1-9]|1[0-2])$/D', $month) !== 1
            || preg_match('/^[a-f0-9]{32}\.(?:jpg|png|gif|webp)$/D', $file) !== 1) {
            throw DomainError::notFound('이미지를 찾을 수 없습니다.');
        }
        return $this->readImage($this->root . '/' . $year . '/' . $month . '/' . $file, $file);
    }

    public function sync(string $key, string $content): void
    {
        $this->assertKey($key);
        $used = [];
        preg_match_all('~/media/editor/' . preg_quote($key, '~')
            . '/([a-f0-9]{32}\.(?:jpg|png|gif|webp))(?:[?"\'\s<]|$)~i', $content, $matches);
        foreach ($matches[1] ?? [] as $file) {
            $used[strtolower((string) $file)] = true;
        }
        $directory = $this->root . '/' . $key;
        foreach (glob($directory . '/*') ?: [] as $path) {
            $file = basename($path);
            if (is_file($path) && $this->isFile($file) && !isset($used[strtolower($file)])) {
                @unlink($path);
            }
        }
        if (is_dir($directory) && (glob($directory . '/*') ?: []) === []) {
            @rmdir($directory);
        }
    }

    /** @param mixed[] $files */
    public function discard(Acl $acl, string $key, array $files): void
    {
        $acl->assertGlobalAdmin();
        $this->assertKey($key);
        $directory = $this->root . '/' . $key;
        foreach (array_slice($files, 0, 100) as $file) {
            if (!is_string($file) || !$this->isFile($file)) {
                continue;
            }
            $path = $directory . '/' . $file;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($directory) && (glob($directory . '/*') ?: []) === []) {
            @rmdir($directory);
        }
    }

    public function deleteFolder(string $key): void
    {
        $this->assertKey($key);
        $directory = $this->root . '/' . $key;
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_file($path) && $this->isFile(basename($path))) {
                @unlink($path);
            }
        }
        if (is_dir($directory) && !@rmdir($directory)) {
            throw DomainError::internal('이미지 폴더를 삭제하지 못했습니다.');
        }
    }

    /** @return array{path:string, mime:string} */
    private function readImage(string $path, string $file): array
    {
        if (!is_file($path)) {
            throw DomainError::notFound('이미지를 찾을 수 없습니다.');
        }
        $mime = $this->detectMime($path);
        $expected = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $extension = (string) pathinfo($file, PATHINFO_EXTENSION);
        if (($expected[$extension] ?? '') !== $mime) {
            throw DomainError::notFound('이미지를 찾을 수 없습니다.');
        }
        return ['path' => $path, 'mime' => $mime];
    }

    private function assertKey(string $key): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
            throw DomainError::validation(['upload' => '이미지 저장 정보를 확인할 수 없습니다.']);
        }
    }

    private function assertFile(string $file): void
    {
        if (!$this->isFile($file)) {
            throw DomainError::notFound('이미지를 찾을 수 없습니다.');
        }
    }

    private function isFile(string $file): bool
    {
        return preg_match('/^[a-f0-9]{32}\.(?:jpg|png|gif|webp)$/D', $file) === 1;
    }

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return (string) ($finfo->file($path) ?: 'application/octet-stream');
    }
}
