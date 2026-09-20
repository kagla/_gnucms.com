<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingsTest extends ShopTestCase
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
        $saved = $this->shop->settings->save($this->settingsInput());
        self::assertFalse($saved['main']['hit']['use']);
        self::assertSame(['use' => true, 'columns' => 2, 'rows' => 2, 'image_width' => 300, 'image_height' => 300], $saved['main']['popular']);
        self::assertSame(4, $this->shop->settings->all()['category']['columns']);
        self::assertTrue($this->shop->settings->all()['show_tax']);
        self::assertSame('<p>배송 안내</p>', $this->shop->settings->all()['shipping']['content']);
        self::assertSame(['use' => true, 'columns' => 2, 'rows' => 2, 'image_width' => 300, 'image_height' => 300], $this->shop->settings->block('main.popular'));
        $flatNoShowTax = array_diff_key($this->flat($saved), ['show_tax' => true]);
        $savedWithoutShowTax = $this->shop->settings->save($flatNoShowTax);
        self::assertFalse($savedWithoutShowTax['show_tax']);
        try {
            $this->shop->settings->save(['category_columns' => '13'] + $this->flat($saved));
            self::fail('열 수 13은 거절해야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertArrayHasKey('category_columns', $e->details());
        }
        self::assertSame(4, $this->shop->settings->all()['category']['columns']);
    }

    /** 기존 테스트가 save()에 넘기던 "필수 폼 값 전부" 배열. */
    private function settingsInput(): array
    {
        return ['main_hit_use' => '0', 'main_popular_use' => '1', 'main_popular_columns' => '2', 'main_popular_rows' => '2',
            'main_popular_image_width' => '300', 'main_popular_image_height' => '300', 'category_columns' => '4', 'category_rows' => '6',
            'category_image_width' => '250', 'category_image_height' => '0', 'type_columns' => '4', 'type_rows' => '5', 'type_image_width' => '200', 'type_image_height' => '0',
            'search_columns' => '4', 'search_rows' => '5', 'search_image_width' => '200', 'search_image_height' => '0',
            'related_use' => '1', 'related_columns' => '5', 'related_image_width' => '120', 'related_image_height' => '0',
            'detail_image_width' => '500', 'detail_image_height' => '0', 'show_tax' => '1',
            'shipping_content' => '<p>배송 안내</p><script>x</script>', 'exchange_content' => ''] + $this->flat($this->shop->settings->all());
    }

    /** 결제 설정: 환경, 무통장 계좌, 수단별 기한. 폼에 없는 값은 이전 값을 지킨다. */
    #[DataProvider('connectionProvider')]
    public function testPaymentSettingsSaveAndKeepPreviousValuesWhenAbsent(array $config): void
    {
        $this->setupShop($config);
        $defaults = $this->shop->settings->all()['payment'];
        self::assertSame('live', $defaults['environment']);
        self::assertFalse($defaults['manual']['enabled']);
        self::assertSame(['card' => 1, 'virtual_account' => 72, 'manual_transfer' => 72], $defaults['deadline_hours']);

        $this->shop->settings->save($this->settingsInput() + ['payment_environment' => 'test', 'payment_manual_enabled' => '1',
            'payment_manual_bank' => '국민은행', 'payment_manual_account' => '123456-01-234567', 'payment_manual_holder' => '홍길동',
            'payment_deadline_card' => '2', 'payment_deadline_manual_transfer' => '48']);
        $saved = $this->shop->settings->all()['payment'];
        self::assertSame('test', $saved['environment']);
        self::assertSame(['enabled' => true, 'bank' => '국민은행', 'account' => '123456-01-234567', 'holder' => '홍길동'], $saved['manual']);
        self::assertSame(['card' => 2, 'virtual_account' => 72, 'manual_transfer' => 48], $saved['deadline_hours']);

        $this->shop->settings->save($this->settingsInput());
        self::assertSame($saved, $this->shop->settings->all()['payment']);

        try {
            $this->shop->settings->save($this->settingsInput() + ['payment_deadline_card' => '0']);
            self::fail('0시간은 거절해야 합니다.');
        } catch (DomainError $e) {
            self::assertArrayHasKey('payment_deadline_card', $e->details());
        }
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
