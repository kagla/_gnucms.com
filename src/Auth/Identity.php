<?php

declare(strict_types=1);

namespace ApiBoard\Auth;

/**
 * 요청자의 신원. 게시판은 사용자 저장소를 갖지 않으므로 이 값은
 * 전적으로 호스트 앱이 서명한 주장에서 온다.
 */
final class Identity
{
    /** @var string|null */
    private $sub;

    /** @var string|null */
    private $displayName;

    /** @var bool */
    private $admin;

    private function __construct(?string $sub, ?string $displayName, bool $admin)
    {
        $this->sub = $sub;
        $this->displayName = $displayName;
        $this->admin = $admin;
    }

    public static function guest(): self
    {
        return new self(null, null, false);
    }

    public static function user(string $sub, string $displayName, bool $admin): self
    {
        return new self($sub, $displayName, $admin);
    }

    public function sub(): ?string
    {
        return $this->sub;
    }

    public function displayName(): ?string
    {
        return $this->displayName;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function isGuest(): bool
    {
        return $this->sub === null;
    }
}
