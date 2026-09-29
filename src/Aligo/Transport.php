<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

interface Transport
{
    /** @param array<string,string|null> $fields @return array{status:int,body:string} */
    public function post(string $url, array $fields, int $timeout = 10): array;
}
