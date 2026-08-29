<?php

declare(strict_types=1);

namespace GnuCms\Account;

/**
 * 동의를 받았다는 사실을 입증하기 위한 증적. 이것 자체는 동의 대상이 아니라
 * 정당한 이익으로 수집한다. 대신 개인정보 처리방침에 고지하고 보관기간을 지킨다.
 * 마스킹하지 않는다. 마스킹하면 증적으로서의 값이 없어져 수집만 남는다.
 */
final class ConsentTrace
{
    public ?string $ip;

    public ?string $userAgent;

    public function __construct(?string $ip, ?string $userAgent)
    {
        $ip = $ip === null ? null : trim($ip);
        $this->ip = ($ip === null || $ip === '') ? null : mb_substr($ip, 0, 45);
        $userAgent = $userAgent === null ? null : trim($userAgent);
        $this->userAgent = ($userAgent === null || $userAgent === '')
            ? null : mb_substr($userAgent, 0, 255);
    }
}
