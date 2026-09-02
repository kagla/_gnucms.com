<?php

declare(strict_types=1);

namespace GnuCms\Oauth;

final class SocialProfile
{
    public string $provider;
    public string $uid;
    public ?string $email;
    public bool $emailVerified;
    public string $name;
    public ?string $imageUrl;

    public function __construct(string $provider, string $uid, ?string $email, bool $emailVerified, string $name,
        ?string $imageUrl = null)
    {
        $this->provider = $provider;
        $this->uid = $uid;
        $this->email = $email === null ? null : strtolower(trim($email));
        $this->emailVerified = $emailVerified;
        $this->name = trim($name) === '' ? '회원' : mb_substr(trim($name), 0, 100);
        $this->imageUrl = $imageUrl === null || trim($imageUrl) === '' ? null : trim($imageUrl);
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'uid' => $this->uid,
            'email' => $this->email,
            'email_verified' => $this->emailVerified,
            'name' => $this->name,
            'image_url' => $this->imageUrl,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['provider'] ?? ''),
            (string) ($data['uid'] ?? ''),
            isset($data['email']) && is_string($data['email']) ? $data['email'] : null,
            (bool) ($data['email_verified'] ?? false),
            (string) ($data['name'] ?? '회원'),
            isset($data['image_url']) && is_string($data['image_url']) ? $data['image_url'] : null
        );
    }
}
