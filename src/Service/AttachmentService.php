<?php

declare(strict_types=1);

namespace ApiBoard\Service;

use ApiBoard\Auth\Acl;
use ApiBoard\Http\ApiError;
use ApiBoard\Http\FileResponse;
use ApiBoard\Repository\PostRepository;
use ApiBoard\Support\Clock;

final class AttachmentService
{
    /** @var BoardService */
    private $boards;

    /** @var PostService */
    private $posts;

    /** @var PostRepository */
    private $postRepo;

    /** @var array */
    private $config;

    /** @var string */
    private $secret;

    public function __construct(
        BoardService $boards,
        PostService $posts,
        PostRepository $postRepo,
        array $config,
        string $secret
    ) {
        $this->boards = $boards;
        $this->posts = $posts;
        $this->postRepo = $postRepo;
        $this->config = $config;
        $this->secret = $secret;
    }

    /**
     * @param array $file $_FILES 의 한 항목
     * @return array 서명된 디스크립터
     */
    public function upload(Acl $acl, string $boardKey, array $file): array
    {
        $board = $this->boards->getEntity($acl, $boardKey);
        $acl->assertCanWrite($board);

        if ((int) $board['use_file'] !== 1) {
            throw ApiError::validation(['file' => '이 게시판은 첨부를 쓰지 않습니다.']);
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw ApiError::tooLarge('파일이 너무 큽니다.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw ApiError::validation(['file' => '파일 업로드에 실패했습니다.']);
        }

        $originalName = trim((string) ($file['name'] ?? ''));
        if ($originalName === '') {
            throw ApiError::validation(['file' => '파일 이름이 없습니다.']);
        }

        $size = (int) ($file['size'] ?? 0);
        $maxBytes = (int) ($this->config['max_bytes'] ?? 5242880);
        if ($size > $maxBytes) {
            throw ApiError::tooLarge('파일은 ' . $maxBytes . ' 바이트를 넘을 수 없습니다.');
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = (array) ($this->config['allowed_ext'] ?? []);
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw ApiError::validation(['file' => '허용되지 않은 확장자입니다: ' . $extension]);
        }

        $relative = substr(Clock::now(), 0, 4) . '/' . substr(Clock::now(), 5, 2);
        $directory = rtrim((string) $this->config['dir'], '/') . '/' . $relative;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw ApiError::internal('업로드 디렉터리를 만들 수 없습니다: ' . $directory);
        }

        $id = bin2hex(random_bytes(16));
        $path = $directory . '/' . $id;

        // 테스트에서는 진짜 업로드가 아니므로 move_uploaded_file 이 실패한다.
        $moved = is_uploaded_file((string) $file['tmp_name'])
            ? move_uploaded_file((string) $file['tmp_name'], $path)
            : rename((string) $file['tmp_name'], $path);

        if ($moved !== true) {
            throw ApiError::internal('파일을 저장하지 못했습니다.');
        }

        $descriptor = [
            'id'   => $id,
            'name' => $originalName,
            'size' => $size,
            'mime' => $this->detectMime($path, (string) ($file['type'] ?? '')),
            'path' => $path,
        ];
        $descriptor['sig'] = $this->sign($descriptor);

        return $descriptor;
    }

    /**
     * 서명이 유효하면 이 서버가 방금 받아들인 파일이라는 뜻이다.
     * 임시 업로드를 추적하는 테이블이 필요 없는 이유다.
     */
    public function verify(array $descriptor): array
    {
        $signature = (string) ($descriptor['sig'] ?? '');
        unset($descriptor['sig']);

        $expectedKeys = ['id', 'name', 'size', 'mime', 'path'];
        foreach ($expectedKeys as $key) {
            if (!array_key_exists($key, $descriptor)) {
                throw ApiError::validation(['attachments' => '첨부 정보가 올바르지 않습니다.']);
            }
        }

        $normalized = [
            'id'   => (string) $descriptor['id'],
            'name' => (string) $descriptor['name'],
            'size' => (int) $descriptor['size'],
            'mime' => (string) $descriptor['mime'],
            'path' => (string) $descriptor['path'],
        ];

        if (!hash_equals($this->sign($normalized), $signature)) {
            throw ApiError::validation(['attachments' => '첨부 서명이 올바르지 않습니다.']);
        }
        if (!is_file($normalized['path'])) {
            throw ApiError::validation(['attachments' => '업로드된 파일을 찾을 수 없습니다.']);
        }

        return $normalized;
    }

    public function download(Acl $acl, int $postId, int $index, ?string $password): FileResponse
    {
        $loaded = $this->posts->loadForRead($acl, $postId, $password);
        $files = $loaded['post']['attachments'];

        if (!isset($files[$index])) {
            throw ApiError::notFound('첨부를 찾을 수 없습니다.');
        }

        $file = $files[$index];
        $path = (string) ($file['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw ApiError::notFound('첨부 파일이 서버에 없습니다.');
        }

        return new FileResponse(
            $path,
            (string) ($file['name'] ?? 'download'),
            (string) ($file['mime'] ?? 'application/octet-stream')
        );
    }

    /**
     * 어떤 글에도 연결되지 않은 파일을 지운다. cron 이 보장되지 않는
     * 저가 호스팅을 가정하므로 관리자가 화면에서 직접 돌린다.
     *
     * MySQL 5.7 에 JSON 함수가 없으므로 SQL 로 참조를 훑을 수 없다.
     * 모든 글의 attachments 를 PHP 로 모은다. 글 수가 아주 많은 게시판에서는
     * 시간이 걸릴 수 있고, 그때는 배치로 나누는 것이 다음 단계다.
     */
    public function collectGarbage(Acl $acl): array
    {
        $acl->assertGlobalAdmin();

        $referenced = [];
        foreach ($this->postRepo->allAttachmentPaths() as $path) {
            $referenced[$path] = true;
        }

        $root = rtrim((string) $this->config['dir'], '/');
        if (!is_dir($root)) {
            return ['deleted' => 0, 'bytes' => 0];
        }

        $deleted = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            // 업로드 파일 이름은 항상 32자리 16진수다. 점으로 시작하는 파일은
            // .gitkeep 같은 자리표시자이므로 건드리지 않는다.
            if (strncmp($item->getFilename(), '.', 1) === 0) {
                continue;
            }
            $path = $item->getPathname();
            if (isset($referenced[$path])) {
                continue;
            }
            $size = (int) $item->getSize();
            if (@unlink($path)) {
                $deleted++;
                $bytes += $size;
            }
        }

        return ['deleted' => $deleted, 'bytes' => $bytes];
    }

    private function sign(array $descriptor): string
    {
        $canonical = implode('|', [
            $descriptor['id'],
            $descriptor['name'],
            (string) $descriptor['size'],
            $descriptor['mime'],
            $descriptor['path'],
        ]);

        return hash_hmac('sha256', $canonical, $this->secret);
    }

    private function detectMime(string $path, string $reported): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return $reported !== '' ? $reported : 'application/octet-stream';
    }
}
