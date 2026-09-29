<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Settings;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingsTest extends ShopTestCase
{
    #[DataProvider('connectionProvider')]
    public function testDefaultsValidationAndRoundTrip(array $config): void
    {
        $this->setupShop($config);
        $settings = $this->shop->settings->all();
        self::assertTrue($settings['main']['best']['use']);
        self::assertFalse($settings['main']['popular']['use']);
        self::assertSame(['use' => true, 'source' => 'auto', 'source_category_id' => null, 'columns' => 4, 'rows' => 1, 'image_width' => 200, 'image_height' => 0], $settings['main']['new']);
        self::assertSame([], $settings['main']['categories']);
        self::assertSame(['new_days' => 30, 'best_days' => 30], $settings['auto']);
        self::assertSame(['columns' => 4, 'rows' => 5, 'image_width' => 200, 'image_height' => 0], $settings['category']);
        self::assertSame(400, $settings['detail']['image_width']);
        self::assertFalse($settings['show_tax']);
        $saved = $this->shop->settings->save($this->settingsInput());
        self::assertFalse($saved['main']['best']['use']);
        self::assertSame(['use' => true, 'source' => 'auto', 'source_category_id' => null, 'columns' => 2, 'rows' => 2, 'image_width' => 300, 'image_height' => 300], $saved['main']['popular']);
        self::assertSame(4, $this->shop->settings->all()['category']['columns']);
        self::assertTrue($this->shop->settings->all()['show_tax']);
        self::assertSame('<p>배송 안내</p>', $this->shop->settings->all()['shipping']['content']);
        self::assertSame(['use' => true, 'source' => 'auto', 'source_category_id' => null, 'columns' => 2, 'rows' => 2, 'image_width' => 300, 'image_height' => 300], $this->shop->settings->block('main.popular'));
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
        return ['main_best_use' => '0', 'main_popular_use' => '1', 'main_popular_columns' => '2', 'main_popular_rows' => '2',
            'main_popular_image_width' => '300', 'main_popular_image_height' => '300', 'category_columns' => '4', 'category_rows' => '6',
            'category_image_width' => '250', 'category_image_height' => '0', 'type_columns' => '4', 'type_rows' => '5', 'type_image_width' => '200', 'type_image_height' => '0',
            'search_columns' => '4', 'search_rows' => '5', 'search_image_width' => '200', 'search_image_height' => '0',
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
        self::assertSame(['card' => 1, 'manual_transfer' => 72], $defaults['deadline_hours']);

        $this->shop->settings->save($this->settingsInput() + ['payment_environment' => 'test', 'payment_manual_enabled' => '1',
            'payment_manual_bank' => '국민은행', 'payment_manual_account' => '123456-01-234567', 'payment_manual_holder' => '홍길동',
            'payment_deadline_card' => '2', 'payment_deadline_manual_transfer' => '48']);
        $saved = $this->shop->settings->all()['payment'];
        self::assertSame('test', $saved['environment']);
        self::assertSame(['enabled' => true, 'bank' => '국민은행', 'account' => '123456-01-234567', 'holder' => '홍길동'], $saved['manual']);
        self::assertSame(['card' => 2, 'manual_transfer' => 48], $saved['deadline_hours']);

        $this->shop->settings->save($this->settingsInput());
        self::assertSame($saved, $this->shop->settings->all()['payment']);

        try {
            $this->shop->settings->save($this->settingsInput() + ['payment_deadline_card' => '0']);
            self::fail('0시간은 거절해야 합니다.');
        } catch (DomainError $e) {
            self::assertArrayHasKey('payment_deadline_card', $e->details());
        }
    }

    /** 쇼핑몰 공개: 기본 켜짐, 폼이 보낸 값으로 끄고 켠다. 폼에 없으면 이전 값. */
    #[DataProvider('connectionProvider')]
    public function testVisibilityDefaultsOnAndFollowsTheForm(array $config): void
    {
        $this->setupShop($config);
        self::assertTrue($this->shop->settings->all()['visible']);
        $this->shop->settings->save($this->settingsInput() + ['visible_form' => '1']);
        self::assertFalse($this->shop->settings->all()['visible']);
        $this->shop->settings->save($this->settingsInput());
        self::assertFalse($this->shop->settings->all()['visible'], '폼에 없으면 이전 값');
        $this->shop->settings->save($this->settingsInput() + ['visible_form' => '1', 'visible' => '1']);
        self::assertTrue($this->shop->settings->all()['visible']);
    }

    /** 자동 묶음 기간, 묶음 기준(자동/분류), 메인 분류 블록을 저장하고 검증한다. 옛 hit·recommend 설정은 버리고 popular 의 크기는 잇는다. */
    #[DataProvider('connectionProvider')]
    public function testCollectionSettings(array $config): void
    {
        $this->setupShop($config);
        $cat = $this->category('기획전'); $sub = $this->category('여름', (int) $cat['id']);
        $form = $this->settingsInput() + ['auto_new_days' => '14', 'auto_best_days' => '90', 'main_best_source' => 'category', 'main_best_source_category_id' => (string) $cat['id'],
            'main_categories' => [['id' => (string) $sub['id'], 'columns' => '3', 'rows' => '1'], ['id' => (string) $cat['id'], 'columns' => '4', 'rows' => '2']]];
        $this->shop->settings->save($form);
        $all = $this->shop->settings->all();
        self::assertSame(['new_days' => 14, 'best_days' => 90], $all['auto']);
        self::assertSame(['category', (int) $cat['id']], [$all['main']['best']['source'], $all['main']['best']['source_category_id']]);
        self::assertSame('auto', $all['main']['new']['source']);
        self::assertSame([['id' => (int) $sub['id'], 'columns' => 3, 'rows' => 1], ['id' => (int) $cat['id'], 'columns' => 4, 'rows' => 2]], $all['main']['categories']);
        // 11개 초과는 서로 다른 분류라야 한다 — 같은 분류가 겹치면 첫 줄만 남으므로 수가 늘지 않는다.
        $many = [];
        for ($n = 0; $n < 11; $n++) $many[] = ['id' => (string) $this->category('블록' . $n)['id'], 'columns' => '3', 'rows' => '1'];
        foreach ([['main_categories' => [['id' => '999999', 'columns' => '3', 'rows' => '1']]], ['main_categories' => $many],
            ['auto_new_days' => '0'], ['main_best_source' => 'category', 'main_best_source_category_id' => '999999']] as $bad) {
            try { $this->shop->settings->save($this->settingsInput() + $bad); self::fail('거절해야 한다'); } catch (DomainError $e) { self::assertSame(422, $e->status()); }
        }
        self::assertSame(['new_days' => 14, 'best_days' => 90], $this->shop->settings->all()['auto'], '거절된 저장은 아무것도 바꾸지 않는다');
        // id 가 아닌 값도 404 가 아니라 422 다.
        try {
            $this->shop->settings->save($this->settingsInput() + ['main_best_source' => 'category', 'main_best_source_category_id' => 'abc']);
            self::fail('id 가 아닌 값은 거절해야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertArrayHasKey('main_best_source_category_id', $e->details());
        }
        // 분류 블록의 id 가 아닌 값은 빈 줄처럼 건너뛴다.
        $this->shop->settings->save($this->settingsInput() + ['main_categories' => [['id' => 'abc', 'columns' => '3', 'rows' => '1'], ['id' => (string) $cat['id'], 'columns' => '3', 'rows' => '1']]]);
        self::assertSame([['id' => (int) $cat['id'], 'columns' => 3, 'rows' => 1]], $this->shop->settings->all()['main']['categories']);
        // 같은 분류를 두 번 올리면 첫 줄만 남는다 — 같은 블록이 메인에 두 번 나오지 않게.
        $this->shop->settings->save($this->settingsInput() + ['main_categories' => [['id' => (string) $cat['id'], 'columns' => '2', 'rows' => '1'],
            ['id' => (string) $sub['id'], 'columns' => '3', 'rows' => '1'], ['id' => (string) $cat['id'], 'columns' => '5', 'rows' => '2']]]);
        self::assertSame([['id' => (int) $cat['id'], 'columns' => 2, 'rows' => 1], ['id' => (int) $sub['id'], 'columns' => 3, 'rows' => 1]],
            $this->shop->settings->all()['main']['categories'], '겹친 분류는 첫 줄만 남는다');
        // 옛 저장값: hit·recommend 는 사라지고 popular 의 크기는 남는다.
        $legacy = $all; $legacy['main'] = ['hit' => ['use' => true, 'columns' => 2, 'rows' => 2, 'image_width' => 100, 'image_height' => 0], 'popular' => ['use' => true, 'columns' => 6, 'rows' => 1, 'image_width' => 150, 'image_height' => 0]];
        $this->app->db()->update('yc_settings', ['payload' => json_encode($legacy, JSON_UNESCAPED_UNICODE)], 'id = :id', ['id' => 'settings']);
        $all = $this->shop->settings->all();
        self::assertArrayNotHasKey('hit', $all['main']);
        self::assertSame([true, 6, 'auto'], [$all['main']['popular']['use'], $all['main']['popular']['columns'], $all['main']['popular']['source']]);
        self::assertSame(['new', 'best', 'popular', 'discount', 'categories'], array_keys($all['main']));
    }

    private function flat(array $settings): array
    {
        $flat = [];
        // 자동 묶음의 기준·기간은 관리자 폼이 따로 보낸다 — 여기서는 크기만 낸다.
        foreach (Settings::TYPES as $type) {
            foreach (['use', 'columns', 'rows', 'image_width', 'image_height'] as $key) {
                $value = $settings['main'][$type][$key];
                $flat['main_' . $type . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
            }
        }
        foreach (['category', 'type', 'search', 'detail'] as $section) {
            foreach ($settings[$section] as $key => $value) $flat[$section . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
        }
        $flat['show_tax'] = $settings['show_tax'] ? '1' : '0';
        $flat['shipping_content'] = $settings['shipping']['content'];
        $flat['exchange_content'] = $settings['exchange']['content'];
        return $flat;
    }
}
