<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Extension\PackageSchema;
use GnuCms\Modules\YoungCart\Catalog\Categories;
use GnuCms\Modules\YoungCart\Catalog\Options;

final class Service
{
    public readonly Store $store;
    public readonly Settings $settings;
    public readonly Categories $categories;
    public readonly Options $options;
    public readonly Images $images;
    public readonly Catalog\Products $products;
    public readonly Catalog\Listing $listing;

    public function __construct(public readonly App $app)
    {
        $this->store = new Store($app->db());
        $this->settings = new Settings($this->store, $app->htmlSanitizer());
        $this->categories = new Categories($this->store, $app->htmlSanitizer(), $this->settings);
        $this->options = new Options($this->store);
        $this->images = new Images($app, $this->settings);
        $this->products = new Catalog\Products($this->store, $app->htmlSanitizer(), $app->contentImages(), $this->images, $this->options, $this->categories);
        $this->listing = new Catalog\Listing($this->store, $this->settings, $this->options);
    }

    public function ready(): bool { return $this->schema()->current(Schema::KEY, Schema::VERSION); }
    public function install(): void { Schema::install($this->schema()); }
    public function schema(): PackageSchema { return new PackageSchema($this->app->db(), $this->app->storageDir()); }
    public function requireReady(): void { if (!$this->ready()) throw DomainError::serviceUnavailable('쇼핑몰 데이터를 먼저 설치해 주세요.'); }
}
