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
    private ?int $testMemberId = null;

    protected function setupShop(array $config): void
    {
        $this->testMemberId = null;
        $this->root = sys_get_temp_dir() . '/gnucms-yc-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
        $config['prefix'] = 'yc' . bin2hex(random_bytes(3)) . '_';
        $this->app = new App(['db' => $config, 'storage' => ['dir' => $this->root], 'uploads' => ['dir' => $this->root . '/uploads'],
            'auth' => ['secret' => bin2hex(random_bytes(32))]]);
        (new CoreSchema($this->app->db()))->create();
        // 주문 테스트에서 실제 메일을 보내지 않는다. 메일 본문·채널 검증은 별도 테스트가 맡는다.
        $this->app->setMailer(new class implements \GnuCms\Mail\MailerInterface {
            public function send(string $to, string $subject, string $body): void {}
        });
        $this->shop = new Service($this->app);
    }

    protected function memberId(): int
    {
        return $this->testMemberId ??= $this->app->users()->create(
            'shop-test-' . bin2hex(random_bytes(8)) . '@example.test', '', '테스트 회원'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) (new CoreSchema($this->app->db()))->drop();
        if (isset($this->root) && is_dir($this->root)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($this->root);
        }
    }

    /** 최소 필드로 분류 하나. 상위는 id 로 준다(없으면 최상위). */
    protected function category(string $name = '의류', ?int $parentId = null, array $extra = []): array
    {
        $id = $this->shop->categories->save($extra + ['name' => $name, 'parent_id' => $parentId === null ? '' : (string) $parentId, 'active' => '1',
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
