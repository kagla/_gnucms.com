<?php

declare(strict_types=1);

namespace GnuCms\Shop\Web;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Catalog\Listing;
use GnuCms\Shop\Catalog\Options;
use GnuCms\Shop\Catalog\Pricing;
use GnuCms\Shop\Images;
use GnuCms\Shop\Input;
use GnuCms\Shop\ProductInfo;
use GnuCms\Shop\Service;
use GnuCms\Shop\Settings;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

final class ShopController
{
    public function __construct(private Service $service, private string $routePrefix, private ?string $adminRoutePrefix) {}

    public function handle(string $page, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $base = RouteContext::fromRequest($request)->getBasePath();
        $url = $base . $this->routePrefix;
        $admin = $this->service->app->guestAcl()->identity()->isAdmin();
        $query = [];
        foreach ($request->getQueryParams() as $key => $value) if (is_string($value)) $query[$key] = $value;
        $view = View::forShop($request);
        $data = ['url' => $url, 'admin_url' => $base . ($this->adminRoutePrefix ?? '/admin/shop'), 'base' => $base, 'admin' => $admin, 'query' => $query, 'page' => $page,
            'img' => static fn (int $productId, ?string $file, string $size): ?string => $file === null ? null : Images::url($url, $productId, $file, $size),
            'type_labels' => Settings::TYPE_LABELS, 'sort_labels' => Listing::SORT_LABELS];
        $data['settings'] = $this->service->settings->all();
        $response = $response->withHeader('Cache-Control', 'no-store');
        if (!$data['settings']['visible']) {
            // 관리자 화면이 이 공개 주소로 상품·배너 그림을 끼워 넣는다. 관리자는 평소 처리로 흘려보낸다.
            if (!in_array($page, ['image', 'banner-image'], true)) return $view->render($response, 'closed', $data);
            if (!$admin) throw DomainError::notFound('이미지를 찾을 수 없습니다.');
        }
        $data['menu'] = $this->service->categories->children('', true);
        $data['cart_count'] = array_sum(array_column($_SESSION['yc_cart'] ?? [], 'quantity'));
        $sort = $query['sort'] ?? '';
        $dir = ($query['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        if (preg_match('/^([a-z]+)_(asc|desc)$/D', $query['sortdir'] ?? '', $m)) { $sort = $m[1]; $dir = $m[2]; }
        $pageNo = preg_match('/^[1-9][0-9]{0,5}$/D', $query['page'] ?? '') ? (int) $query['page'] : 1;
        $data += ['sort' => $sort, 'dir' => $dir];
        switch ($page) {
            case 'index':
                $data['blocks'] = $this->service->listing->main();
                $data['banner'] = $this->service->banner->view($data['settings']['banner'], $data['blocks'], $data['menu'], $url, $base);
                return $view->render($response, 'index', $data);
            case 'banner-image':
                return $this->service->banner->imageResponse($query['f'] ?? '', $admin, $response);
            case 'list':
                $category = $this->service->categories->byCode($query['ca'] ?? '');
                if ($category === null || (int) $category['active'] !== 1) throw DomainError::notFound('분류를 찾을 수 없습니다.');
                $data['category'] = $category;
                $data['path'] = $this->service->categories->path($category['code']);
                $data['children'] = $this->service->categories->children($category['code'], true);
                $data['list'] = $this->service->listing->category($category, $sort, $dir, $pageNo);
                return $view->render($response, 'list', $data);
            case 'type':
                $type = $query['t'] ?? '';
                if (!isset(Settings::TYPE_LABELS[$type])) throw DomainError::notFound('상품 유형을 찾을 수 없습니다.');
                $data['type'] = $type;
                $data['list'] = $this->service->listing->type($type, $sort, $dir, $pageNo);
                return $view->render($response, 'type', $data);
            case 'search':
                $q = mb_substr(trim($query['q'] ?? ''), 0, 50, 'UTF-8');
                $ca = preg_match('/^[0-9a-z]{2,10}$/D', $query['ca'] ?? '') ? $query['ca'] : '';
                $min = preg_match('/^[0-9]{1,10}$/D', $query['min'] ?? '') ? (int) $query['min'] : 0;
                $max = preg_match('/^[0-9]{1,10}$/D', $query['max'] ?? '') ? (int) $query['max'] : 0;
                $data += ['q' => $q, 'ca' => $ca, 'min' => $min, 'max' => $max];
                $data['list'] = $this->service->listing->search($q, $ca, $min, $max, $sort, $dir, $pageNo);
                return $view->render($response, 'search', $data);
            case 'item':
                $product = ($query['id'] ?? '') !== '' ? $this->service->products->byCode($query['id'])
                    : (($query['slug'] ?? '') !== '' ? $this->service->products->bySlug($query['slug']) : null);
                if ($product === null) throw DomainError::notFound('상품을 찾을 수 없습니다.');
                $visible = (int) $product['active'] === 1 && (int) ($product['categories'][1]['active'] ?? 0) === 1;
                if (!$visible && !$admin) throw DomainError::notFound('상품을 찾을 수 없습니다.');
                $cookie = 'yc_hit_' . $product['id'];
                if ($visible && !isset($request->getCookieParams()[$cookie])) {
                    $this->service->store->execute('UPDATE ' . $this->service->store->table('yc_products') . ' SET hit = hit + 1 WHERE id = ?', [(int) $product['id']]);
                    $product['hit'] = (int) $product['hit'] + 1;
                    $response = $response->withAddedHeader('Set-Cookie', $cookie . '=1; Max-Age=3600; Path=' . ($base === '' ? '/' : $base) . '; SameSite=Lax; HttpOnly');
                }
                $data['product'] = $product;
                $data['preview'] = !$visible;
                $data['path'] = isset($product['categories'][1]) ? $this->service->categories->path($product['categories'][1]['code']) : [];
                $data['adjacent'] = $this->service->listing->adjacent($product);
                $data['related'] = $data['settings']['related']['use'] ? $this->service->listing->related((int) $product['id']) : [];
                $data['options_json'] = Options::pageJson($product, $product['options']);
                $data['sold_out'] = $product['sold_out_computed'];
                $data['display_price'] = Pricing::display($product);
                $data['point_label'] = Pricing::pointLabel($product);
                $data['info_label'] = ProductInfo::labels()[$product['info_group']] ?? '';
                $data['info_articles'] = ProductInfo::articles($product['info_group']);
                return $view->render($response, 'item', $data);
            case 'image':
                $productId = Input::id($query['p'] ?? '');
                $product = $this->service->products->find($productId) ?? throw DomainError::notFound('이미지를 찾을 수 없습니다.');
                $size = $query['s'] ?? 'list';
                $width = $size === 'list' ? $this->service->images->width('list', $this->service->store->find('yc_categories', (int) $product['category_id'])) : null;
                return $this->service->images->response($productId, $query['f'] ?? '', $size, $response->withoutHeader('Cache-Control'), $width);
        }
        throw DomainError::notFound('페이지를 찾을 수 없습니다.');
    }
}
