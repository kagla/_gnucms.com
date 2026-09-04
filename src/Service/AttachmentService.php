<?php

declare(strict_types=1);

namespace GnuCms\Service;

use GnuCms\Auth\Acl;
use GnuCms\Cms\ImageResizer;
use GnuCms\Error\DomainError;
use GnuCms\Repository\PostRepository;
use GnuCms\Support\Clock;

final class AttachmentService
{
    /** 정리가 건너뛰는 나이. 이보다 새 파일은 작성 중인 폼의 것일 수 있다. */
    public const GC_MIN_AGE_SECONDS = 86400;

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

    /** @var ImageResizer */
    private $resizer;

    /**
     * 서버 PHP 가 실제로 받아 주는 파일당 최대 크기(MB). 설정 화면의 힌트에 쓴다.
     * upload_max_filesize·post_max_size 가 둘 다 무제한(iniToMb 가 PHP_INT_MAX)이면
     * 0 을 돌려준다. 0 은 "서버 쪽 한계가 없다"는 뜻이고, 화면은 이를 별도 문구로 보여 준다.
     *
     * @param string|null $uploadMaxFilesize 테스트 전용. 생략하면 ini_get('upload_max_filesize').
     *   두 값 모두 PHP_INI_PERDIR 라 ini_set() 으로 런타임에 바꿀 수 없어, 무제한(0) 분기를
     *   테스트하려면 이렇게 값을 주입받아야 한다.
     * @param string|null $postMaxSize 테스트 전용. 생략하면 ini_get('post_max_size').
     */
    public static function serverMaxMb(?string $uploadMaxFilesize = null, ?string $postMaxSize = null): int
    {
        $mb = min(
            self::iniToMb($uploadMaxFilesize ?? (string) ini_get('upload_max_filesize')),
            self::iniToMb($postMaxSize ?? (string) ini_get('post_max_size'))
        );

        return $mb === PHP_INT_MAX ? 0 : max(1, $mb);
    }

    /** php.ini 의 8M·1G 같은 축약 표기를 MB 정수로. 0·음수는 무제한이라는 뜻이다. */
    public static function iniToMb(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return PHP_INT_MAX;
        }
        $unit = strtoupper(substr($value, -1));
        $number = (float) $value;
        switch ($unit) {
            case 'G': $bytes = $number * 1073741824; break;
            case 'M': $bytes = $number * 1048576; break;
            case 'K': $bytes = $number * 1024; break;
            default: $bytes = $number;
        }
        if ($bytes <= 0) {
            return PHP_INT_MAX;
        }

        return max(1, (int) floor($bytes / 1048576));
    }

    public function __construct(
        BoardService $boards,
        PostService $posts,
        PostRepository $postRepo,
        array $config,
        string $secret,
        ?ImageResizer $resizer = null
    ) {
        $this->boards = $boards;
        $this->posts = $posts;
        $this->postRepo = $postRepo;
        $this->config = $config;
        $this->secret = $secret;
        $this->resizer = $resizer ?? new ImageResizer();
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
            throw DomainError::validation(['file' => '이 게시판은 첨부를 쓰지 않습니다.']);
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw DomainError::tooLarge('파일이 너무 큽니다.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw DomainError::validation(['file' => '파일 업로드에 실패했습니다.']);
        }

        $originalName = trim((string) ($file['name'] ?? ''));
        if ($originalName === '') {
            throw DomainError::validation(['file' => '파일 이름이 없습니다.']);
        }

        $size = (int) ($file['size'] ?? 0);
        $maxBytes = (int) ($this->config['max_bytes'] ?? 5242880);
        if ($size > $maxBytes) {
            throw DomainError::tooLarge('파일은 ' . $maxBytes . ' 바이트를 넘을 수 없습니다.');
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = (array) ($this->config['allowed_ext'] ?? []);
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            throw DomainError::validation(['file' => '허용되지 않은 확장자입니다: ' . $extension]);
        }

        $relative = substr(Clock::now(), 0, 4) . '/' . substr(Clock::now(), 5, 2);
        $directory = rtrim((string) $this->config['dir'], '/') . '/' . $relative;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw DomainError::internal('업로드 디렉터리를 만들 수 없습니다: ' . $directory);
        }

        $id = bin2hex(random_bytes(16));
        $path = $directory . '/' . $id;

        // 테스트에서는 진짜 업로드가 아니므로 move_uploaded_file 이 실패한다.
        $moved = is_uploaded_file((string) $file['tmp_name'])
            ? move_uploaded_file((string) $file['tmp_name'], $path)
            : rename((string) $file['tmp_name'], $path);

        if ($moved !== true) {
            throw DomainError::internal('파일을 저장하지 못했습니다.');
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
     * 저장된 디스크립터에 서명을 다시 붙인다. 수정 화면이 기존 첨부를
     * 폼의 hidden input 으로 되실을 때 쓴다. index 같은 여분 키는 버린다.
     */
    public function withSignature(array $stored): array
    {
        $descriptor = [
            'id'   => (string) ($stored['id'] ?? ''),
            'name' => (string) ($stored['name'] ?? ''),
            'size' => (int) ($stored['size'] ?? 0),
            'mime' => (string) ($stored['mime'] ?? ''),
            'path' => (string) ($stored['path'] ?? ''),
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
                throw DomainError::validation(['attachments' => '첨부 정보가 올바르지 않습니다.']);
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
            throw DomainError::validation(['attachments' => '첨부 서명이 올바르지 않습니다.']);
        }
        // 파일 이름은 항상 upload() 가 만든 32자리 16진수다. 형식부터 어긋나면
        // 실제 경로를 계산할 것도 없이 거부한다.
        if (preg_match('/^[0-9a-f]{32}$/D', basename($normalized['path'])) !== 1) {
            throw DomainError::validation(['attachments' => '첨부 정보가 올바르지 않습니다.']);
        }
        if (!is_file($normalized['path'])) {
            throw DomainError::validation(['attachments' => '업로드된 파일을 찾을 수 없습니다.']);
        }
        // 서명 비밀키가 새거나 약해도 디스크립터가 가리킬 수 있는 파일은 업로드
        // 폴더 안으로만 묶는다. 심볼릭 링크나 '..' 조각으로 밖을 가리키지 못하게
        // realpath 로 실제 경로를 확인한다.
        $root = realpath(rtrim((string) $this->config['dir'], '/'));
        $realPath = realpath($normalized['path']);
        if ($root === false || $realPath === false || strncmp($realPath, $root . '/', strlen($root) + 1) !== 0) {
            throw DomainError::validation(['attachments' => '첨부 정보가 올바르지 않습니다.']);
        }

        return $normalized;
    }

    /**
     * 파일을 실제로 내보내는 일은 Web 계층이 한다. 서비스는 무엇을 어떤 이름으로
     * 보낼지만 정한다.
     *
     * @return array{path: string, name: string, mime: string}
     */
    public function download(Acl $acl, int $postId, int $index, ?string $password): array
    {
        $loaded = $this->posts->loadForRead($acl, $postId, $password);
        $files = $loaded['post']['attachments'];

        if (!isset($files[$index])) {
            throw DomainError::notFound('첨부를 찾을 수 없습니다.');
        }

        $file = $files[$index];
        $path = (string) ($file['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw DomainError::notFound('첨부 파일이 서버에 없습니다.');
        }

        return [
            'path' => $path,
            'name' => (string) ($file['name'] ?? 'download'),
            'mime' => (string) ($file['mime'] ?? 'application/octet-stream'),
        ];
    }

    /**
     * 첨부 이미지의 축소본 경로. 없으면 만들고, 만들 수 없으면 원본을 그대로 준다.
     *
     * 이름 앞에 점을 붙여 두면 collectGarbage() 가 건드리지 않는다.
     * 그쪽은 글에 연결되지 않은 파일을 지우는데, 축소본은 글이 직접 가리키지 않기 때문이다.
     */
    public function thumbnailPath(string $original, int $width): string
    {
        $target = dirname($original) . '/.' . $width . '-' . basename($original);

        return $this->resizer->ensure($original, $target, $width) ? $target : $original;
    }

    /** @return array{items:list<array{path:string,relative_path:string,size:int,original_size:int,mtime:int,file_count:int,thumbnails:list<string>}>,files:int,bytes:int} */
    public function garbageCandidates(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        $referenced = [];
        foreach ($this->postRepo->allAttachmentPaths() as $path) {
            $referenced[$path] = true;
        }

        $root = rtrim((string) $this->config['dir'], '/');
        if (!is_dir($root)) {
            return ['items' => [], 'files' => 0, 'bytes' => 0];
        }

        $items = [];
        $files = 0;
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
            // 방금 올라온 파일은 아직 글을 저장하지 않은 폼의 것일 수 있다.
            if ($item->getMTime() > time() - self::GC_MIN_AGE_SECONDS) {
                continue;
            }

            $originalSize = (int) $item->getSize();
            $size = $originalSize;
            $thumbnails = [];
            foreach (glob(dirname($path) . '/.*-' . basename($path)) ?: [] as $thumbnail) {
                if (is_file($thumbnail)) {
                    $thumbnails[] = $thumbnail;
                    $size += (int) filesize($thumbnail);
                }
            }
            $fileCount = 1 + count($thumbnails);
            $items[] = [
                'path' => $path,
                'relative_path' => str_replace('\\', '/', substr($path, strlen($root) + 1)),
                'size' => $size,
                'original_size' => $originalSize,
                'mtime' => (int) $item->getMTime(),
                'file_count' => $fileCount,
                'thumbnails' => $thumbnails,
            ];
            $files += $fileCount;
            $bytes += $size;
        }

        usort($items, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']
            ?: strcmp($a['relative_path'], $b['relative_path']));

        return ['items' => $items, 'files' => $files, 'bytes' => $bytes];
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
        $candidates = $this->garbageCandidates($acl);
        $deleted = 0;
        $bytes = 0;
        foreach ($candidates['items'] as $item) {
            if (!@unlink($item['path'])) {
                continue;
            }
            $deleted++;
            $bytes += (int) $item['original_size'];
            foreach ($item['thumbnails'] as $thumbnail) {
                $thumbnailSize = is_file($thumbnail) ? (int) filesize($thumbnail) : 0;
                if (@unlink($thumbnail)) {
                    $deleted++;
                    $bytes += $thumbnailSize;
                }
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
