<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Catalog\Categories;
use PHPUnit\Framework\Attributes\DataProvider;

final class CategoriesTest extends ShopTestCase
{
    #[DataProvider('connectionProvider')]
    public function testTreeSlugsAndPaths(array $config): void
    {
        $this->setupShop($config);
        $top = $this->category('의류');
        self::assertSame(['의류', '/' . $top['id'] . '/', 1, null], [$top['slug'], $top['path'], (int) $top['depth'], $top['legacy_code']]);
        self::assertNull($top['parent_id']);
        $child = $this->category('셔츠', (int) $top['id']);
        self::assertSame(['셔츠', '/' . $top['id'] . '/' . $child['id'] . '/', 2, (int) $top['id']], [$child['slug'], $child['path'], (int) $child['depth'], (int) $child['parent_id']]);
        // 이름이 같으면 -2, 직접 준 슬러그는 규칙대로 다듬고, 남과 겹치는 직접 슬러그는 거절한다.
        self::assertSame('의류-2', $this->category('의류')['slug']);
        self::assertSame('summer-tees', $this->category('여름', null, ['slug' => ' summer/tees '])['slug']);
        try { $this->category('겹침', null, ['slug' => '셔츠']); self::fail('겹치는 슬러그는 거절해야 한다'); } catch (DomainError $e) { self::assertSame(422, $e->status()); self::assertArrayHasKey('slug', $e->details()); }
        // 슬러그로 쓸 수 없는 값은 거절한다: 다듬고 나면 비는 이름과, 주소에서 상위 폴더로 읽히는 . 과 .. 이다.
        foreach ([['/?#%', ''], ['..', ''], ['점', '.'], ['점', '..']] as [$name, $typed]) {
            try {
                $this->category($name, null, ['slug' => $typed]);
                self::fail('쓸 수 없는 슬러그는 거절해야 한다: ' . $name . ' / ' . $typed);
            } catch (DomainError $e) {
                self::assertSame(422, $e->status(), $name . ' / ' . $typed);
                self::assertArrayHasKey('slug', $e->details(), $name . ' / ' . $typed);
            }
        }
        // 자기 슬러그를 그대로 두고 저장하는 것은 된다.
        $this->shop->categories->save(['name' => '셔츠', 'slug' => '셔츠', 'parent_id' => (string) $top['id'], 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0'], (int) $child['id']);
        self::assertSame('셔츠', $this->shop->categories->get((int) $child['id'])['slug']);
        try { $this->category('없는 부모', 999999); self::fail(); } catch (DomainError $e) { self::assertSame(422, $e->status()); self::assertArrayHasKey('parent_id', $e->details()); }
        self::assertSame($child['id'], $this->shop->categories->bySlug('셔츠')['id']);
        self::assertNull($this->shop->categories->bySlug('없음'));
        self::assertSame([$top['id'], $child['id']], array_column($this->shop->categories->ancestors($this->shop->categories->get((int) $child['id'])), 'id'));
        self::assertSame(['셔츠'], array_column($this->shop->categories->children((int) $top['id'], true), 'name'));
        self::assertSame(['여름', '의류', '의류'], array_column($this->shop->categories->children(null, true), 'name'));
        $tree = $this->shop->categories->tree();
        self::assertSame(['여름', '의류', '셔츠', '의류'], array_column($tree, 'name'));
        self::assertSame('의류 > 셔츠', $this->shop->categories->options()[(int) $child['id']]);
        // 선택 상자 글자에는 슬러그가 이름과 다를 때만 덧붙이고, title 에는 슬러그·번호를 늘 담는다.
        $details = $this->shop->categories->optionDetails();
        self::assertSame('의류 > 셔츠', $details[(int) $child['id']]['text']);
        self::assertSame('슬러그 셔츠 · 번호 ' . $child['id'], $details[(int) $child['id']]['title']);
        $summer = $this->shop->categories->bySlug('summer-tees');
        self::assertSame('여름 [summer-tees]', $details[(int) $summer['id']]['text']);
        self::assertSame(array_keys($this->shop->categories->parentOptions(null)), array_keys($this->shop->categories->parentOptionDetails(null)));
        self::assertSame(['c.path LIKE ?', [$top['path'] . '%']], Categories::subtreeWhere($top, 'c'));
        // 메뉴 숨김: 메뉴용 children() 에서만 빠지고 나머지는 그대로다.
        $hidden = $this->category('기획전', null, ['menu_hidden' => '1']);
        self::assertSame(1, (int) $hidden['menu_hidden']);
        self::assertContains('기획전', array_column($this->shop->categories->children(null, true), 'name'));
        self::assertNotContains('기획전', array_column($this->shop->categories->children(null, true, true), 'name'));
        self::assertSame('기획전', $this->shop->categories->bySlug('기획전')['name']);
    }

    #[DataProvider('connectionProvider')]
    public function testMoveRewritesTheSubtreeAndGuardsCyclesAndDepth(array $config): void
    {
        $this->setupShop($config);
        $a = $this->category('A'); $b = $this->category('B', (int) $a['id']); $c = $this->category('C', (int) $b['id']); $x = $this->category('X');
        $form = ['name' => 'B', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0'];
        // B(와 그 아래 C)를 X 아래로 옮긴다.
        $this->shop->categories->save($form + ['parent_id' => (string) $x['id']], (int) $b['id']);
        $b2 = $this->shop->categories->get((int) $b['id']); $c2 = $this->shop->categories->get((int) $c['id']);
        self::assertSame(['/' . $x['id'] . '/' . $b['id'] . '/', 2], [$b2['path'], (int) $b2['depth']]);
        self::assertSame(['/' . $x['id'] . '/' . $b['id'] . '/' . $c['id'] . '/', 3], [$c2['path'], (int) $c2['depth']]);
        self::assertSame([], $this->shop->categories->children((int) $a['id'], false));
        self::assertArrayNotHasKey((int) $b['id'], $this->shop->categories->parentOptions((int) $b['id']));
        self::assertArrayNotHasKey((int) $c['id'], $this->shop->categories->parentOptions((int) $b['id']));
        self::assertArrayHasKey((int) $x['id'], $this->shop->categories->parentOptions((int) $b['id']));
        // 자기 자신·자기 하위 아래로는 못 옮긴다.
        foreach ([(int) $b['id'], (int) $c['id']] as $bad) {
            try { $this->shop->categories->save($form + ['parent_id' => (string) $bad], (int) $b['id']); self::fail('순환'); } catch (DomainError $e) { self::assertSame(422, $e->status()); self::assertArrayHasKey('parent_id', $e->details()); }
        }
        // 최상위로 되돌리면 path 가 /id/ 가 된다.
        $this->shop->categories->save($form + ['parent_id' => ''], (int) $b['id']);
        self::assertSame(['/' . $b['id'] . '/', 1], [$this->shop->categories->get((int) $b['id'])['path'], (int) $this->shop->categories->get((int) $b['id'])['depth']]);
        // 10단계: 9단계 사슬 아래에 하나는 되고, 그 아래로 두 단계짜리를 옮기는 것은 안 된다.
        $node = $x;
        for ($i = 2; $i <= 9; $i++) $node = $this->category('L' . $i, (int) $node['id']);
        $tenth = $this->category('L10', (int) $node['id']);
        self::assertSame(10, (int) $tenth['depth']);
        try { $this->category('L11', (int) $tenth['id']); self::fail('11단계'); } catch (DomainError $e) { self::assertSame(422, $e->status()); }
        try { $this->shop->categories->save($form + ['parent_id' => (string) $node['id']], (int) $b['id']); self::fail('B+C 가 11단계'); } catch (DomainError $e) { self::assertSame(422, $e->status()); }
        // 하위 적용은 path 로 건다.
        $this->shop->categories->save(['parent_id' => '', 'active' => '0', 'apply_children' => '1'] + $form, (int) $b['id']);
        self::assertSame(0, (int) $this->shop->categories->get((int) $c['id'])['active']);
        self::assertSame(1, (int) $this->shop->categories->get((int) $x['id'])['active']);
    }

    /** 목록 위·아래 HTML 의 편집기 사진은 categories/<id> 폴더에 둔다. 첫 저장 전 tmp 폴더의 사진은 저장하면서 옮기고 주소를 바꾸며, 본문에서 빠진 사진은 지우고, 분류를 지우면 폴더째 없앤다. */
    #[DataProvider('connectionProvider')]
    public function testEditorImagesLiveInTheCategoryFolder(array $config): void
    {
        $this->setupShop($config);
        $tmp = 'tmp/' . str_repeat('ab', 16);
        $tmpDir = $this->root . '/editor/' . $tmp;
        mkdir($tmpDir, 0700, true);
        $used = str_repeat('1', 32) . '.png'; $stale = str_repeat('2', 32) . '.png';
        file_put_contents($tmpDir . '/' . $used, 'x'); file_put_contents($tmpDir . '/' . $stale, 'x');
        $form = ['name' => '의류', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0', 'active' => '1'];
        $id = $this->shop->categories->save($form + ['image_key' => $tmp,
            'head_html' => '<p><img src="/media/editor/' . $tmp . '/' . $used . '" alt=""></p>', 'tail_html' => '<p><img src="/media/editor/' . $tmp . '/' . $stale . '" alt=""></p>']);
        $dir = $this->root . '/editor/categories/' . $id;
        $row = $this->shop->categories->get($id);
        self::assertStringContainsString('/media/editor/categories/' . $id . '/' . $used, $row['head_html']);
        self::assertStringContainsString('/media/editor/categories/' . $id . '/' . $stale, $row['tail_html']);
        self::assertFileExists($dir . '/' . $used); self::assertFileExists($dir . '/' . $stale); self::assertDirectoryDoesNotExist($tmpDir);
        // 수정 때 폼이 보내는 키는 무시하고 분류 폴더를 쓰며, 본문에서 빠진 사진은 지운다.
        $this->shop->categories->save($form + ['image_key' => 'tmp/' . str_repeat('cd', 16), 'head_html' => $row['head_html'], 'tail_html' => ''], $id);
        self::assertFileExists($dir . '/' . $used); self::assertFileDoesNotExist($dir . '/' . $stale);
        $this->shop->categories->delete($id);
        self::assertDirectoryDoesNotExist($dir);
    }

    #[DataProvider('connectionProvider')]
    public function testUpdateApplyChildrenDeleteGuardsAndBulk(array $config): void
    {
        $this->setupShop($config);
        $top = $this->category('의류'); $child = $this->category('셔츠', (int) $top['id']); $grand = $this->category('반팔', (int) $child['id']);
        $this->shop->categories->save(['parent_id' => '', 'name' => '의류(수정)', 'active' => '0', 'list_columns' => '4', 'list_rows' => '2',
            'image_width' => '150', 'image_height' => '150', 'head_html' => '<p>위</p><script>1</script>', 'sort_order' => '5', 'apply_children' => '1',
            'extra_label' => [1 => '라벨'], 'extra_value' => [1 => '값']], (int) $top['id']);
        $top = $this->shop->categories->get((int) $top['id']);
        // 슬러그를 비워 보내면 이름에서 다시 만든다(관리자 폼은 지금 슬러그를 그대로 보낸다).
        self::assertSame('의류(수정)', $top['slug']); self::assertSame('의류(수정)', $top['name']); self::assertSame('<p>위</p>', $top['head_html']);
        self::assertSame('라벨', $top['extra'][0]['label']);
        $grand = $this->shop->categories->get((int) $grand['id']);
        self::assertSame(0, (int) $grand['active']); self::assertSame(4, (int) $grand['list_columns']); self::assertSame(150, (int) $grand['image_height']);
        self::assertSame('반팔', $grand['name']);
        try { $this->shop->categories->delete((int) $top['id']); self::fail(); } catch (DomainError $e) { self::assertStringContainsString('하위 분류', $e->details()['category']); }
        $this->product(['category_id' => (string) $grand['id']]);
        try { $this->shop->categories->delete((int) $grand['id']); self::fail(); } catch (DomainError $e) { self::assertStringContainsString('1개 상품', $e->details()['category']); }
        $leaf = $this->category('빈 분류', (int) $child['id']);
        $this->shop->categories->delete((int) $leaf['id']);
        self::assertNull($this->shop->categories->bySlug($leaf['slug']));
        $this->shop->categories->bulk([(int) $child['id'] => ['name' => '셔츠(일괄)', 'sort_order' => '3', 'active' => '1', 'list_columns' => '2', 'list_rows' => '2', 'image_width' => '100', 'image_height' => '0']]);
        self::assertSame('셔츠(일괄)', $this->shop->categories->get((int) $child['id'])['name']);
        try {
            $this->shop->categories->bulk([(int) $child['id'] => ['name' => '', 'sort_order' => '3', 'active' => '1', 'list_columns' => '2', 'list_rows' => '2', 'image_width' => '100', 'image_height' => '0'],
                (int) $grand['id'] => ['name' => '유지', 'sort_order' => '0', 'active' => '1', 'list_columns' => '2', 'list_rows' => '2', 'image_width' => '100', 'image_height' => '0']]);
            self::fail();
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
        }
        self::assertSame('반팔', $this->shop->categories->get((int) $grand['id'])['name']);
        self::assertSame(3, $this->shop->categories->count());
    }
}
