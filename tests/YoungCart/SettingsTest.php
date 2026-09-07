<?php

declare(strict_types=1);

namespace GnuCms\Tests\YoungCart;

use GnuCms\Error\DomainError;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingsTest extends YoungCartTestCase
{
    #[DataProvider('connectionProvider')]
    public function testDefaultsValidationAndRoundTrip(array $config): void
    {
        $this->setupShop($config);
        $settings = $this->shop->settings->all();
        self::assertTrue($settings['main']['hit']['use']);
        self::assertFalse($settings['main']['popular']['use']);
        self::assertSame(['use' => true, 'columns' => 4, 'rows' => 1, 'image_width' => 200, 'image_height' => 0], $settings['main']['new']);
        self::assertSame(['columns' => 3, 'rows' => 5, 'image_width' => 200, 'image_height' => 0], $settings['category']);
        self::assertSame(400, $settings['detail']['image_width']);
        self::assertFalse($settings['show_tax']);
        $saved = $this->shop->settings->save(['main_hit_use' => '0', 'main_popular_use' => '1', 'main_popular_columns' => '2', 'main_popular_rows' => '2',
            'main_popular_image_width' => '300', 'main_popular_image_height' => '300', 'category_columns' => '4', 'category_rows' => '6',
            'category_image_width' => '250', 'category_image_height' => '0', 'type_columns' => '4', 'type_rows' => '5', 'type_image_width' => '200', 'type_image_height' => '0',
            'search_columns' => '4', 'search_rows' => '5', 'search_image_width' => '200', 'search_image_height' => '0',
            'related_use' => '1', 'related_columns' => '5', 'related_image_width' => '120', 'related_image_height' => '0',
            'detail_image_width' => '500', 'detail_image_height' => '0', 'show_tax' => '1',
            'shipping_content' => '<p>배송 안내</p><script>x</script>', 'exchange_content' => '']);
        self::assertFalse($saved['main']['hit']['use']);
        self::assertSame(['use' => true, 'columns' => 2, 'rows' => 2, 'image_width' => 300, 'image_height' => 300], $saved['main']['popular']);
        self::assertSame(4, $this->shop->settings->all()['category']['columns']);
        self::assertTrue($this->shop->settings->all()['show_tax']);
        self::assertSame('<p>배송 안내</p>', $this->shop->settings->all()['shipping']['content']);
        self::assertSame(['use' => true, 'columns' => 2, 'rows' => 2, 'image_width' => 300, 'image_height' => 300], $this->shop->settings->block('main.popular'));
        try {
            $this->shop->settings->save(['category_columns' => '13'] + $this->flat($saved));
            self::fail('열 수 13은 거절해야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertArrayHasKey('category_columns', $e->details());
        }
        self::assertSame(4, $this->shop->settings->all()['category']['columns']);
    }

    private function flat(array $settings): array
    {
        $flat = [];
        foreach (['hit', 'new', 'recommend', 'discount', 'popular'] as $type) {
            foreach ($settings['main'][$type] as $key => $value) $flat['main_' . $type . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
        }
        foreach (['category', 'type', 'search', 'related', 'detail'] as $section) {
            foreach ($settings[$section] as $key => $value) $flat[$section . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
        }
        $flat['show_tax'] = $settings['show_tax'] ? '1' : '0';
        $flat['shipping_content'] = $settings['shipping']['content'];
        $flat['exchange_content'] = $settings['exchange']['content'];
        return $flat;
    }
}
