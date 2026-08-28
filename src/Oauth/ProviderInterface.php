<?php

declare(strict_types=1);

namespace GnuCms\Oauth;

interface ProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function authorizationUrl(string $state): string;

    public function fetchProfile(string $code, string $state = ''): SocialProfile;
}
