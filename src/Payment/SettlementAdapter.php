<?php

declare(strict_types=1);

namespace GnuCms\Payment;

interface SettlementAdapter
{
    public function id(): string;

    /** @return list<array<string,mixed>> */
    public function parse(string $contents): array;

    public function template(): string;
}
