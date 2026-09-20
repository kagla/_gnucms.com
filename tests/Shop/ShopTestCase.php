<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\App;
use GnuCms\Db\Schema as CoreSchema;
use GnuCms\Shop\Service;
use GnuCms\Tests\Support\DatabaseTestCase;

abstract class ShopTestCase extends DatabaseTestCase
{
    protected App $app;
    protected Service $shop;
    protected string $root;

    protected function setupShop(array $config, bool $install = true): void
    {
        $this->root = sys_get_temp_dir() . '/gnucms-yc-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
        if ($config['dsn'] === 'sqlite::memory:') $config['dsn'] = 'sqlite:' . $this->root . '/yc.sqlite';
        $config['prefix'] = 'yc' . bin2hex(random_bytes(3)) . '_';
        $this->app = new App(['db' => $config, 'storage' => ['dir' => $this->root], 'uploads' => ['dir' => $this->root . '/uploads'],
            'auth' => ['secret' => bin2hex(random_bytes(32))]]);
        (new CoreSchema($this->app->db()))->create();
        $this->shop = new Service($this->app);
        if ($install) $this->shop->install();
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && $this->app->db()->dialect()->name() === 'mysql') {
            foreach (\GnuCms\Shop\Schema::TABLES as $table) {
                $this->app->db()->execute('DROP TABLE IF EXISTS ' . $this->app->db()->table($table));
            }
            $this->app->db()->execute('DELETE FROM ' . $this->app->db()->table('extension_schemas'));
        }
        if (isset($this->root) && is_dir($this->root)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($this->root);
        }
    }

    /** 최소 필드로 분류 하나를 만든다. */
    protected function category(string $name = '의류', ?string $parent = null, array $extra = []): array
    {
        $code = $this->shop->categories->suggestCode($parent);
        $id = $this->shop->categories->save($extra + ['code' => $code, 'name' => $name, 'active' => '1',
            'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']);
        return $this->shop->categories->get($id);
    }

    /** 최소 필드로 상품 하나를 만든다. */
    protected function product(array $overrides = []): array
    {
        $category = $overrides['category_id'] ?? $this->category()['id'];
        $input = $overrides + ['code' => 'P' . bin2hex(random_bytes(4)), 'name' => '기본 상품', 'category_id' => (string) $category,
            'price' => '10000', 'stock' => '5', 'active' => '1', 'summary' => '', 'description' => ''];
        $id = $this->shop->products->save($input, []);
        return $this->shop->products->get($id);
    }
}
