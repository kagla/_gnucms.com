<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use PHPUnit\Framework\Attributes\DataProvider;

final class ListingTest extends ShopTestCase
{
    #[DataProvider('connectionProvider')]
    public function testCategoryPrefixSlotsVisibilitySortAndPaging(array $config): void
    {
        $this->setupShop($config);
        $top = $this->category('의류'); $child = $this->category('셔츠', (int) $top['id']); $hidden = $this->category('숨김', (int) $top['id'], ['active' => '0']); $other = $this->category('잡화');
        $a = $this->product(['category_id' => (string) $child['id'], 'code' => 'A', 'name' => '가', 'price' => '300', 'sort_order' => '2']);
        $b = $this->product(['category_id' => (string) $other['id'], 'extra_category_ids' => [(string) $top['id']], 'code' => 'B', 'name' => '나', 'price' => '100', 'sort_order' => '1']);
        $c = $this->product(['category_id' => (string) $hidden['id'], 'code' => 'C', 'name' => '다', 'price' => '200']);
        $d = $this->product(['category_id' => (string) $child['id'], 'code' => 'D', 'name' => '라', 'price' => '50', 'active' => '0']);
        $e = $this->product(['category_id' => (string) $other['id'], 'code' => 'E', 'name' => '마', 'price' => '400', 'stock' => '0']);
        $list = $this->shop->listing->category($top, '', '', 1);
        self::assertSame(['B', 'A'], array_column($list['items'], 'code'));
        self::assertSame(15, $list['per_page']); self::assertSame(3, $list['columns']); self::assertSame(1, $list['total_pages']);
        self::assertSame(['A', 'B'], array_column($this->shop->listing->category($top, 'price', 'desc', 1)['items'], 'code'));
        self::assertSame(['B', 'A'], array_column($this->shop->listing->category($top, 'bogus', 'up', 1)['items'], 'code'));
        self::assertSame([], $this->shop->listing->category($top, '', '', 2)['items']);
        $small = $this->shop->categories->get((int) $other['id']);
        $this->shop->categories->save(['name' => '잡화', 'active' => '1', 'list_columns' => '1', 'list_rows' => '1', 'image_width' => '200', 'image_height' => '0'], (int) $other['id']);
        $small = $this->shop->categories->get((int) $other['id']);
        $page1 = $this->shop->listing->category($small, 'price', 'asc', 1); $page2 = $this->shop->listing->category($small, 'price', 'asc', 2);
        self::assertSame(['B'], array_column($page1['items'], 'code')); self::assertSame(['E'], array_column($page2['items'], 'code'));
        self::assertSame(2, $page1['total_pages']); self::assertSame(2, $page1['total']);
        self::assertTrue($page2['items'][0]['sold_out']); self::assertFalse($page1['items'][0]['sold_out']);
        self::assertNull($page1['items'][0]['image']);
    }

    #[DataProvider('connectionProvider')]
    public function testSearchFacetsRelatedAndAdjacent(array $config): void
    {
        $this->setupShop($config);
        $top = $this->category('의류'); $other = $this->category('잡화');
        $a = $this->product(['category_id' => (string) $top['id'], 'code' => 'A', 'name' => '파란 셔츠', 'summary' => '여름 상품', 'price' => '300', 'sort_order' => '1']);
        $b = $this->product(['category_id' => (string) $top['id'], 'code' => 'B', 'name' => '빨간 셔츠', 'description' => '<p>겨울 상품</p>', 'price' => '100', 'sort_order' => '2', 'relations' => (string) $a['id']]);
        $c = $this->product(['category_id' => (string) $other['id'], 'code' => 'C', 'name' => '가방', 'price' => '200', 'sort_order' => '3']);
        $search = $this->shop->listing->search('셔츠 상품', null, 0, 0, '', '', 1);
        self::assertSame(['A', 'B'], array_column($search['items'], 'code'));
        self::assertSame(['셔츠', '상품'], $search['words']);
        self::assertSame([['slug' => '의류', 'name' => '의류', 'count' => 2]], $search['facets']);
        self::assertSame(['B'], array_column($this->shop->listing->search('셔츠', null, 0, 150, '', '', 1)['items'], 'code'));
        self::assertSame(['C'], array_column($this->shop->listing->search('가방', $other, 0, 0, '', '', 1)['items'], 'code'));
        self::assertSame([], $this->shop->listing->search('100%', null, 0, 0, '', '', 1)['items']);
        self::assertSame(['B', 'A'], array_column($this->shop->listing->search('셔츠', null, 0, 0, 'price', 'asc', 1)['items'], 'code'));
        self::assertSame(['A'], array_column($this->shop->listing->related((int) $b['id']), 'code'));
        self::assertSame(['B'], array_column($this->shop->listing->related((int) $a['id']), 'code'));
        $adjacent = $this->shop->listing->adjacent($this->shop->products->get((int) $b['id']));
        self::assertSame('A', $adjacent['prev']['code']); self::assertNull($adjacent['next']);
        $adjacent = $this->shop->listing->adjacent($this->shop->products->get((int) $a['id']));
        self::assertNull($adjacent['prev']); self::assertSame('B', $adjacent['next']['code']);
    }

    /** 자동 묶음: 신상품은 기간, 베스트는 판매량(결제 뒤 상태의 주문만), 인기는 조회수, 할인은 시중가보다 싼 상품. 수동 전환은 분류 하위를 보인다. */
    #[DataProvider('connectionProvider')]
    public function testCollectionsFollowTheirRules(array $config): void
    {
        $this->setupShop($config);
        $cat = $this->category('의류'); $event = $this->category('기획전', null, ['menu_hidden' => '1']);
        $now = \GnuCms\Support\Clock::timestamp();
        $fresh = $this->product(['category_id' => (string) $cat['id'], 'code' => 'F', 'name' => '새것', 'price' => '100']);
        $old = $this->product(['category_id' => (string) $cat['id'], 'code' => 'O', 'name' => '옛것', 'price' => '100']);
        $this->shop->store->update('yc_products', (int) $old['id'], ['created_at' => $now - 40 * 86400]);
        $sale = $this->product(['category_id' => (string) $cat['id'], 'code' => 'S', 'name' => '할인', 'price' => '80', 'list_price' => '100']);
        $this->shop->store->update('yc_products', (int) $sale['id'], ['hit' => 5]);
        $this->shop->store->update('yc_products', (int) $fresh['id'], ['hit' => 9]);
        // 판매: fresh 2개(결제 완료), old 5개(주문 접수 — 세지 않음), sale 1개(배송 완료)
        $order = function (int $productId, int $qty, string $status) use ($now): void {
            $id = $this->shop->store->insert('yc_orders', ['number' => 'N' . $productId . $status, 'checkout_key' => bin2hex(random_bytes(32)), 'owner_key' => bin2hex(random_bytes(32)),
                'user_id' => null, 'status' => $status, 'created_at' => $now, 'updated_at' => $now] + $this->orderDefaults());
            $this->shop->store->insert('yc_order_items', ['order_id' => $id, 'product_id' => $productId, 'option_id' => null, 'quantity' => $qty] + $this->orderItemDefaults());
        };
        $order((int) $fresh['id'], 2, 'paid'); $order((int) $old['id'], 5, 'pending'); $order((int) $sale['id'], 1, 'completed');
        $codes = fn (array $r): array => array_column($r['items'], 'code');
        self::assertSame(['S', 'F'], $codes($this->shop->listing->collection('new', '', '', 1)));      // 등록 내림차순, 옛것 제외
        self::assertSame(['F', 'S'], $codes($this->shop->listing->collection('best', '', '', 1)));     // 2개 > 1개, 접수 주문 제외
        self::assertSame(['F', 'S'], $codes($this->shop->listing->collection('popular', '', '', 1)));  // 9 > 5, 0 제외
        self::assertSame(['S'], $codes($this->shop->listing->collection('discount', '', '', 1)));
        // 할인율이 큰 쪽이 앞이다: 반값(D) > 20%(S)
        $this->product(['category_id' => (string) $cat['id'], 'code' => 'D', 'name' => '반값', 'price' => '50', 'list_price' => '100']);
        self::assertSame(['D', 'S'], $codes($this->shop->listing->collection('discount', '', '', 1)));
        self::assertSame(['S', 'F'], $codes($this->shop->listing->collection('best', 'price', 'asc', 1)), '정렬 메뉴는 규칙보다 우선하되 판매 없는 상품은 여전히 빠진다');
        // 수동 전환: 베스트를 기획전 분류로
        $this->shop->products->addToCategory([(string) $old['id']], (int) $event['id']);
        $settings = $this->shop->settings->all(); $settings['main']['best'] = ['source' => 'category', 'source_category_id' => (int) $event['id']] + $settings['main']['best'];
        $this->saveSettingsRow($settings);
        self::assertSame(['O'], $codes($this->shop->listing->collection('best', '', '', 1)));
        $this->shop->products->removeFromCategory([(string) $old['id']], (int) $event['id']);
        $this->shop->categories->delete((int) $event['id']);
        self::assertSame(['F', 'S'], $codes($this->shop->listing->collection('best', '', '', 1)), '분류가 없어지면 자동 규칙으로');
        try { $this->shop->listing->collection('hit', '', '', 1); self::fail('없는 묶음은 404'); } catch (DomainError $e) { self::assertSame(404, $e->status()); }
    }

    /** 메인 블록: use 가 켜진 자동 묶음과 관리자가 고른 분류 블록. */
    #[DataProvider('connectionProvider')]
    public function testMainBlocksIncludeChosenCategories(array $config): void
    {
        $this->setupShop($config);
        $cat = $this->category('의류'); $sub = $this->category('셔츠', (int) $cat['id']); $off = $this->category('내린 분류', null, ['active' => '0']);
        $a = $this->product(['category_id' => (string) $sub['id'], 'code' => 'A']); $b = $this->product(['category_id' => (string) $cat['id'], 'code' => 'B', 'price' => '50', 'list_price' => '100']);
        $c = $this->product(['category_id' => (string) $sub['id'], 'code' => 'C']);
        $settings = $this->shop->settings->all();
        $settings['main']['popular']['use'] = false; $settings['main']['best']['use'] = false;
        $settings['main']['categories'] = [['id' => (int) $cat['id'], 'columns' => 4, 'rows' => 1], ['id' => 999999, 'columns' => 4, 'rows' => 1],
            ['id' => (int) $off['id'], 'columns' => 4, 'rows' => 1], ['id' => (int) $sub['id'], 'columns' => 1, 'rows' => 1]];
        $this->saveSettingsRow($settings);
        $blocks = $this->shop->listing->main();
        self::assertSame(['new', 'discount', 'categories'], array_keys($blocks));
        self::assertSame(['B'], array_column($blocks['discount'], 'code'));
        self::assertCount(2, $blocks['categories'], '없어진 분류도 공개를 끈 분류도 건너뛴다');
        self::assertSame('의류', $blocks['categories'][0]['category']['name']);
        self::assertSame(['C', 'B', 'A'], array_column($blocks['categories'][0]['items'], 'code'), '하위 분류 상품까지, sort_order·id 역순');
        // 블록의 열은 분류의 기본 열(3)이 아니라 관리자가 블록에 정한 값이고, 열 × 행이 상품 수를 제한한다.
        self::assertSame(4, $blocks['categories'][0]['columns']);
        self::assertSame(1, $blocks['categories'][0]['rows']);
        self::assertSame('셔츠', $blocks['categories'][1]['category']['name']);
        self::assertSame(1, $blocks['categories'][1]['columns']);
        self::assertSame(['C'], array_column($blocks['categories'][1]['items'], 'code'), '열 × 행이 상품 수를 제한한다');
    }

    /** 주문 표의 NOT NULL 칸을 빈값으로 채운다 — 판매량 집계만 보는 테스트용. */
    private function orderDefaults(): array
    {
        return ['guest_password' => '', 'buyer_name' => '이름', 'email' => 'a@b.c', 'phone' => '010', 'recipient' => '받는분', 'recipient_phone' => '010',
            'postcode' => '04524', 'address' => '주소', 'address_detail' => '', 'delivery_note' => '', 'subtotal' => 0, 'shipping_fee' => 0,
            'cod_fee' => 0, 'total' => 0, 'shipping_detail' => '[]', 'order_notice' => '', 'carrier' => '', 'tracking_number' => ''];
    }

    private function orderItemDefaults(): array
    {
        return ['kind' => 'select', 'product_code' => 'X', 'product_name' => '항목', 'option_label' => '', 'image' => '', 'unit_price' => 100, 'total' => 100];
    }

    /** 설정 화면을 거치지 않고 저장된 설정을 통째로 덮는다. */
    private function saveSettingsRow(array $settings): void
    {
        $db = $this->app->db();
        $payload = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($db->selectOne('SELECT id FROM ' . $db->table('yc_settings') . " WHERE id = 'settings'") === null) $db->insert('yc_settings', ['id' => 'settings', 'payload' => $payload]);
        else $db->update('yc_settings', ['payload' => $payload], 'id = :id', ['id' => 'settings']);
    }
}
