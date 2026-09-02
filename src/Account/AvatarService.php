<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Error\DomainError;
use Psr\Http\Message\UploadedFileInterface;

final class AvatarService
{
    private const MAX_BYTES = 2097152;
    private const MAX_PIXELS = 20000000;
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const SOCIAL_HOSTS = [
        'google' => ['googleusercontent.com'],
        'naver' => ['pstatic.net', 'naver.net'],
        'kakao' => ['kakaocdn.net', 'daumcdn.net'],
    ];

    public function __construct(private string $root) { $this->root = rtrim($root, '/'); }

    public function storeUpload(UploadedFileInterface $upload): string
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw DomainError::validation(['profile_image' => '프로필 이미지를 업로드하지 못했습니다.']);
        }
        $size = (int) ($upload->getSize() ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw DomainError::validation(['profile_image' => '프로필 이미지는 2MB 이하만 올릴 수 있습니다.']);
        }
        $this->ensureRoot();
        $temporary = $this->root . '/' . bin2hex(random_bytes(16)) . '.upload';
        $upload->moveTo($temporary);
        return $this->finish($temporary);
    }

    public function storeSocial(string $provider, string $url): ?string
    {
        if (!$this->allowedSocialUrl($provider, $url) || !extension_loaded('curl')) return null;
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 7, CURLOPT_MAXFILESIZE => self::MAX_BYTES,
            CURLOPT_USERAGENT => GNUCMS]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (!is_string($body) || $status !== 200 || $body === '' || strlen($body) > self::MAX_BYTES) return null;
        $this->ensureRoot();
        $temporary = $this->root . '/' . bin2hex(random_bytes(16)) . '.download';
        if (file_put_contents($temporary, $body, LOCK_EX) === false) return null;
        try { return $this->finish($temporary); } catch (DomainError $e) { @unlink($temporary); return null; }
    }

    /** @return array{path:string,mime:string} */
    public function image(string $file): array
    {
        if (preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $file) !== 1) {
            throw DomainError::notFound('프로필 이미지를 찾을 수 없습니다.');
        }
        $path = $this->root . '/' . $file;
        $mime = is_file($path) ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if (!is_string($mime) || !isset(self::EXTENSIONS[$mime])) throw DomainError::notFound('프로필 이미지를 찾을 수 없습니다.');
        return ['path' => $path, 'mime' => $mime];
    }

    public function delete(?string $file): void
    {
        if (is_string($file) && preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D', $file) === 1) @unlink($this->root . '/' . $file);
    }

    private function finish(string $temporary): string
    {
        $info = @getimagesize($temporary);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
        if ($info === false || !is_string($mime) || !isset(self::EXTENSIONS[$mime])
            || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            @unlink($temporary);
            throw DomainError::validation(['profile_image' => 'JPG, PNG, WebP 이미지만 사용할 수 있습니다.']);
        }
        $file = bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$mime];
        if (!rename($temporary, $this->root . '/' . $file)) { @unlink($temporary); throw DomainError::internal('프로필 이미지를 저장하지 못했습니다.'); }
        return $file;
    }

    private function allowedSocialUrl(string $provider, string $url): bool
    {
        $parts = parse_url($url); $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '') return false;
        foreach (self::SOCIAL_HOSTS[$provider] ?? [] as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) return true;
        }
        return false;
    }

    private function ensureRoot(): void
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0775, true) && !is_dir($this->root)) throw DomainError::internal('프로필 이미지 저장 폴더를 만들 수 없습니다.');
    }
}
