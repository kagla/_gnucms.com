<?php

declare(strict_types=1);

namespace GnuCms\Shop\Fulfillment;

interface CarrierAdapter
{
    public function id(): string;

    public function label(): string;

    /** @param list<array<string,mixed>> $orders */
    public function export(array $orders): string;

    /** @return list<array{number:string,carrier:string,tracking_number:string}> */
    public function import(string $contents): array;
}
