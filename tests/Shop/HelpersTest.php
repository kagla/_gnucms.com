<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Error\DomainError;
use GnuCms\Shop\Catalog\Pricing;
use GnuCms\Shop\Input;
use GnuCms\Shop\ProductInfo;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testInputHelpers(): void
    {
        self::assertSame('셔츠', Input::text(" 셔츠 ", 'name', 10));
        self::assertSame('', Input::text(null, 'memo', 10));
        try { Input::text('', 'name', 10, false); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('name', $e->details()); }
        try { Input::text("a\x00b", 'name', 10); self::fail(); } catch (DomainError $e) { self::assertSame(422, $e->status()); }
        try { Input::text(str_repeat('가', 11), 'name', 10); self::fail(); } catch (DomainError $e) { self::assertSame(422, $e->status()); }
        self::assertSame(7, Input::int('7', 'n', 0, 10));
        self::assertSame(3, Input::int('', 'n', 0, 10, 3));
        try { Input::int('11', 'n', 0, 10); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('n', $e->details()); }
        try { Input::int('-1', 'n', 0, 10); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('n', $e->details()); }
        self::assertSame(1, Input::bool('1')); self::assertSame(0, Input::bool('on')); self::assertSame(0, Input::bool(null));
        self::assertSame(12, Input::id('12'));
        try { Input::id('0'); self::fail(); } catch (DomainError $e) { self::assertSame(404, $e->status()); }
        self::assertNull(Input::optionalId('')); self::assertSame(3, Input::optionalId('3'));
        self::assertSame('AB_1-x', Input::code('AB_1-x', 'code', '/^[A-Za-z0-9_-]{1,20}$/D', '코드 형식'));
        try { Input::code('a b', 'code', '/^[A-Za-z0-9_-]{1,20}$/D', '코드 형식'); self::fail(); } catch (DomainError $e) { self::assertSame('코드 형식', $e->details()['code']); }
        self::assertSame('<p>본문</p>', Input::html('<p>본문</p><script>1</script>', 'html', new HtmlSanitizer()));
        self::assertSame('제목 본문 끝', Input::plain("<h1>제목</h1>\n<p>본문   끝</p>"));
        self::assertSame('여름-셔츠-2', Input::slug('  여름 셔츠 / 2  ', 'P1'));
        self::assertSame('P1', Input::slug('///', 'P1'));
        $extra = json_decode(Input::extra(['extra_label' => [1 => '색상', 3 => '크기'], 'extra_value' => [1 => '빨강', 3 => 'L']]), true);
        self::assertCount(10, $extra);
        self::assertSame(['label' => '색상', 'value' => '빨강'], $extra[0]);
        self::assertSame(['label' => '', 'value' => ''], $extra[1]);
        self::assertSame(['빨강', '파랑'], Input::csv(' 빨강, 파랑 ,,빨강', 20, 100, 'values'));
        try { Input::csv(implode(',', range(1, 21)), 20, 100, 'values'); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('values', $e->details()); }
    }

    public function testProductInfoGroupsAndPricing(): void
    {
        self::assertCount(35, ProductInfo::GROUPS);
        self::assertSame('의류', ProductInfo::labels()['wear']);
        self::assertContains('제품 소재', ProductInfo::articles('wear'));
        $json = ProductInfo::normalize('wear', [0 => '면 100%', 99 => '무시']);
        $decoded = ProductInfo::decode($json);
        self::assertSame('면 100%', $decoded[0]);
        self::assertSame('상품페이지 참고', $decoded[1]);
        self::assertArrayNotHasKey(99, $decoded);
        self::assertSame('', ProductInfo::normalize('', []));
        try { ProductInfo::normalize('nope', []); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('info_group', $e->details()); }
        $product = ['price' => 12000, 'phone_inquiry' => 0, 'point_type' => 1, 'point' => 5];
        self::assertSame(12000, Pricing::display($product));
        self::assertNull(Pricing::display(['price' => 12000, 'phone_inquiry' => 1]));
        self::assertSame(600, Pricing::point($product));
        self::assertSame(600, Pricing::point(['price' => 12000, 'point_type' => 2, 'point' => 5], 0));
        self::assertSame(700, Pricing::point(['price' => 12000, 'point_type' => 2, 'point' => 5], 2000));
        self::assertSame(300, Pricing::point(['price' => 12000, 'point_type' => 0, 'point' => 300]));
        self::assertSame(0, Pricing::point(['price' => 12000, 'point_type' => 0, 'point' => -5]));
        self::assertSame('12,000원', Pricing::format(12000));
        self::assertSame('구매금액(추가옵션 제외)의 5%', Pricing::pointLabel(['price' => 12000, 'point_type' => 2, 'point' => 5]));
        self::assertSame('600점', Pricing::pointLabel($product));
    }
}
