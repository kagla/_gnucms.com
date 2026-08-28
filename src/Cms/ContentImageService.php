<?php

declare(strict_types=1);

namespace GnuCms\Cms;

use GnuCms\Auth\Acl;
use GnuCms\Error\DomainError;
use Psr\Http\Message\UploadedFileInterface;

final class ContentImageService
{
    /**
     * 줄여서 내보내는 크기. 파일 이름 뒤에 `-thumb` / `-view` 를 붙여 구분한다.
     *
     *   목록 카드  {32자리}-thumb.jpg   480px
     *   본문       {32자리}-view.jpg    960px
     *   원본       {32자리}.jpg         눌렀을 때만
     *
     * 이 목록에 없는 이름은 받지 않는다. 아무 크기나 만들어 달라고 하면
     * 그것만으로 서버를 갈아 넣을 수 있기 때문이다.
     */
    public const VARIANTS = ['thumb' => 480, 'view' => ImageResizer::CONTENT_WIDTH];

    private string $root;
    private int $maxBytes;
    private ImageResizer $resizer;

    public function __construct(string $root, int $maxBytes = 5242880, ?ImageResizer $resizer = null)
    {
        $this->root = rtrim($root, '/');
        $this->maxBytes = $maxBytes;
        $this->resizer = $resizer ?? new ImageResizer();
    }

    /** `abc.jpg` + `thumb` → `abc-thumb.jpg`. 이름 규칙을 한 곳에서만 만든다. */
    public static function variantFile(string $file, string $variant): string
    {
        if (!isset(self::VARIANTS[$variant])) {
            return $file;
        }
        $extension = (string) pathinfo($file, PATHINFO_EXTENSION);

        return pathinfo($file, PATHINFO_FILENAME) . '-' . $variant . '.' . $extension;
    }

    /**
     * CMS 내용 편집용 업로드. 관리자만 쓴다.
     *
     * @return array{key:string, file:string}
     */
    public function upload(Acl $acl, UploadedFileInterface $upload, string $key): array
    {
        $acl->assertGlobalAdmin();

        return $this->store($upload, $key);
    }

    /**
     * 게시글 본문 편집용 업로드. 그 게시판에 글을 쓸 수 있는 사람만 쓴다.
     *
     * @return array{key:string, file:string}
     */
    public function uploadForBoard(Acl $acl, array $board, UploadedFileInterface $upload, string $key): array
    {
        $acl->assertCanWrite($board);

        return $this->store($upload, $key);
    }

    /**
     * 댓글 편집용 업로드. 그 게시판에 댓글을 쓸 수 있는 사람만 쓴다.
     *
     * @return array{key:string, file:string}
     */
    public function uploadForComment(Acl $acl, array $board, UploadedFileInterface $upload, string $key): array
    {
        $acl->assertCanComment($board);

        return $this->store($upload, $key);
    }

    /** 권한 판단은 위 세 곳에서 끝내고, 여기서는 파일만 다룬다. */
    private function store(UploadedFileInterface $upload, string $key): array
    {
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

        return $this->readVariant($this->root . '/' . $key, $file);
    }

    /** @return array{path:string, mime:string} */
    public function image(string $year, string $month, string $file): array
    {
        if (preg_match('/^\d{4}$/D', $year) !== 1 || preg_match('/^(?:0[1-9]|1[0-2])$/D', $month) !== 1
            || preg_match('/^[a-f0-9]{32}\.(?:jpg|png|gif|webp)$/D', $file) !== 1) {
            throw DomainError::notFound('이미지를 찾을 수 없습니다.');
        }
        return $this->readVariant($this->root . '/' . $year . '/' . $month, $file);
    }

    /**
     * 요청한 이름이 축소본이면 원본에서 만들어 준다.
     *
     * 만들 수 없는 그림(이미 작거나, 움직이는 GIF 이거나, GD 가 없는 곳)은
     * 원본을 그대로 준다. 화면이 깨지는 것보다 낫다.
     *
     * @return array{path:string, mime:string}
     */
    private function readVariant(string $directory, string $file): array
    {
        [$base, $variant] = $this->splitVariant($file);
        $original = $this->readImage($directory . '/' . $base, $base);
        if ($variant === null) {
            return $original;
        }

        $target = $directory . '/' . $file;
        if ($this->resizer->ensure($original['path'], $target, self::VARIANTS[$variant])) {
            return ['path' => $target, 'mime' => $original['mime']];
        }

        return $original;
    }

    /**
     * `abc-thumb.jpg` → `['abc.jpg', 'thumb']`, `abc.jpg` → `['abc.jpg', null]`.
     *
     * @return array{0:string, 1:?string}
     */
    private function splitVariant(string $file): array
    {
        $names = implode('|', array_keys(self::VARIANTS));
        if (preg_match('/^([a-f0-9]{32})-(' . $names . ')\.([a-z]+)$/D', $file, $m) === 1) {
            return [$m[1] . '.' . $m[3], $m[2]];
        }

        return [$file, null];
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
            if (!is_file($path) || !$this->isFile($file)) {
                continue;
            }
            // 축소본은 자기 원본이 쓰이는지로 판단한다.
            [$base] = $this->splitVariant($file);
            if (!isset($used[strtolower($base)])) {
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
        $this->remove($key, $files);
    }

    /** 게시글 편집 중 버린 이미지 정리. 글쓰기 권한이 있는 사람만 부른다. */
    public function discardForBoard(Acl $acl, array $board, string $key, array $files): void
    {
        $acl->assertCanWrite($board);
        $this->remove($key, $files);
    }

    /** 댓글 편집 중 버린 이미지 정리. */
    public function discardForComment(Acl $acl, array $board, string $key, array $files): void
    {
        $acl->assertCanComment($board);
        $this->remove($key, $files);
    }

    private function remove(string $key, array $files): void
    {
        $this->assertKey($key);
        $directory = $this->root . '/' . $key;
        foreach (array_slice($files, 0, 100) as $file) {
            if (!is_string($file) || !$this->isFile($file)) {
                continue;
            }
            [$base] = $this->splitVariant($file);
            $names = array_merge([$base], array_map(
                static fn (string $variant): string => self::variantFile($base, $variant),
                array_keys(self::VARIANTS)
            ));
            foreach ($names as $name) {
                $path = $directory . '/' . $name;
                if (is_file($path)) {
                    @unlink($path);
                }
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

    /** 원본과 축소본 모두 우리 파일이다. 정리할 때 함께 다뤄야 한다. */
    private function isFile(string $file): bool
    {
        $names = implode('|', array_keys(self::VARIANTS));

        return preg_match('/^[a-f0-9]{32}(?:-(?:' . $names . '))?\.(?:jpg|png|gif|webp)$/D', $file) === 1;
    }

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return (string) ($finfo->file($path) ?: 'application/octet-stream');
    }
}
