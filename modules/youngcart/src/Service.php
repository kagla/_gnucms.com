<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart;

use GnuCms\App;
use GnuCms\Extension\PackageSchema;

final class Service
{
    public function __construct(public readonly App $app) {}

    public function ready(): bool
    {
        return $this->schema()->current(Schema::KEY, Schema::VERSION);
    }

    public function schema(): PackageSchema
    {
        return new PackageSchema($this->app->db(), $this->app->storageDir());
    }
}
