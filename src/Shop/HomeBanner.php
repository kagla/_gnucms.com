<?php

declare(strict_types=1);

namespace GnuCms\Shop;

use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

final class HomeBanner
{
    // 상품 ID는 1부터 시작한다. 업로드·백업 경로 안의 0번 폴더를 배너 전용으로 쓴다.
    public const IMAGE_OWNER = 0;
    public const MODES = ['auto' => '메인 진열 상품 자동 표시', 'random' => '메인 진열 상품 랜덤 표시', 'product' => '특정 상품 선택', 'upload' => '이미지 직접 업로드'];

    public function __construct(private Store $store, private Settings $settings, private Images $images) {}

    public static function defaults(): array
    {
        return ['use' => true, 'eyebrow' => 'EVERYDAY FINDS', 'title' => "일상에 필요한 모든 것,\n발견하는 즐거움.",
            'description' => "새로운 상품부터 마음에 드는 추천까지.\n필요한 순간을 위한 쇼핑을 시작해 보세요.",
            'button_label' => '상품 둘러보기', 'button_url' => '', 'mode' => 'auto', 'product_id' => 0,
            'image' => '', 'image_alt' => '', 'image_caption' => '', 'image_url' => ''];
    }

    /** 새 필드가 없는 기존 테마 폼은 저장된 배너를 보존한다. 파일명은 HTTP 입력에서 받지 않는다. */
    public static function validate(Store $store, array $input, array $previous, ?string $image): array
    {
        $banner = $previous;
        if (array_key_exists('banner_use', $input)) {
            $banner['use'] = Input::code($input['banner_use'], 'banner_use', '/^[01]$/D', '표시 여부를 확인해 주세요.') === '1';
        }
        foreach (['eyebrow' => 80, 'title' => 200, 'description' => 1000, 'button_label' => 50, 'image_alt' => 200, 'image_caption' => 200] as $key => $max) {
            if (array_key_exists('banner_' . $key, $input)) $banner[$key] = Input::text($input['banner_' . $key], 'banner_' . $key, $max);
        }
        foreach (['button_url', 'image_url'] as $key) {
            if (!array_key_exists('banner_' . $key, $input)) continue;
            $value = Input::text($input['banner_' . $key], 'banner_' . $key, 2000);
            // 사이트 내 /경로, #앵커와 HTTP(S)만 허용하며 브라우저의 URL 정규화도 고려한다.
            $decoded = rawurldecode($value);
            $local = str_starts_with($value, '/') && !str_starts_with($decoded, '//');
            $external = preg_match('~^https?://~i', $value) && filter_var($value, FILTER_VALIDATE_URL) !== false;
            if ($value !== '' && (preg_match('/[\x00-\x20\x7f\\\\]/', $value) || preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded)
                || (!$local && !str_starts_with($value, '#') && !$external))) {
                throw DomainError::validation(['banner_' . $key => '/로 시작하는 사이트 내 경로나 http://, https:// 주소를 입력해 주세요.']);
            }
            $banner[$key] = $value;
        }
        if (array_key_exists('banner_mode', $input)) {
            $banner['mode'] = Input::code($input['banner_mode'], 'banner_mode', '/^(auto|random|product|upload)$/D', '이미지 표시 방식을 선택해 주세요.');
        }
        if (array_key_exists('banner_product_id', $input)) {
            $banner['product_id'] = Input::int($input['banner_product_id'], 'banner_product_id', 0, 999999999999, 0);
        }
        if ($image !== null) {
            if ($image !== '' && !Images::validName($image)) throw DomainError::validation(['banner_image' => '이미지를 확인해 주세요.']);
            $banner['image'] = $image;
        }
        // 기존 폼 저장은 이후 비공개/삭제된 상품 때문에 막지 않는다.
        $editing = $image !== null || array_filter(array_keys($input), static fn ($key) => is_string($key) && str_starts_with($key, 'banner_')) !== [];
        if ($banner['use'] && $editing) {
            if ($banner['title'] === '') throw DomainError::validation(['banner_title' => '배너 제목을 입력해 주세요.']);
            if ($banner['mode'] === 'product' && (self::product($store, $banner['product_id'])['image'] ?? null) === null) {
                throw DomainError::validation(['banner_product_id' => '대표 이미지가 있는 공개 상품을 선택해 주세요.']);
            }
            if ($banner['mode'] === 'upload' && $banner['image'] === '') {
                throw DomainError::validation(['banner_image' => '배너 이미지를 업로드해 주세요.']);
            }
        }
        return $banner;
    }

    /** 설정 저장 실패 시 새 파일만 제거하고, 성공한 뒤에 이전 파일을 정리한다. */
    public function saveSettings(array $input, mixed $upload = null): array
    {
        if ($upload !== null && !$upload instanceof UploadedFileInterface) throw DomainError::validation(['banner_image' => '이미지 한 장을 선택해 주세요.']);
        $previous = $this->settings->all()['banner']['image'];
        $newImage = null;
        $remove = Input::bool($input['banner_image_delete'] ?? '0') === 1;
        try {
            if ($upload !== null && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
                try { $newImage = $this->images->save(self::IMAGE_OWNER, $upload); }
                catch (DomainError $e) {
                    if ($e->status() !== 422) throw $e;
                    throw DomainError::validation(['banner_image' => $e->details()['images'] ?? $e->getMessage()]);
                }
            }
            if ($remove && $newImage === null && ($input['banner_mode'] ?? $this->settings->all()['banner']['mode']) === 'upload') $input['banner_mode'] = 'auto';
            $saved = $this->settings->save($input, $newImage ?? ($remove ? '' : null));
        } catch (Throwable $e) {
            if ($newImage !== null) $this->images->delete(self::IMAGE_OWNER, $newImage);
            throw $e;
        }
        if ($previous !== '' && $previous !== $saved['banner']['image']) $this->images->delete(self::IMAGE_OWNER, $previous);
        return $saved;
    }

    public function choices(): array
    {
        return $this->store->select('SELECT p.id, p.code, p.name FROM ' . $this->store->table('yc_products') . ' p JOIN '
            . $this->store->table('yc_categories') . ' c ON c.id = p.category_id WHERE p.active = 1 AND c.active = 1 AND EXISTS (SELECT 1 FROM '
            . $this->store->table('yc_product_images') . ' i WHERE i.product_id = p.id) ORDER BY p.name, p.id');
    }

    private static function product(Store $store, int $id): ?array
    {
        return $store->selectOne('SELECT p.id, p.code, p.name, (SELECT i.filename FROM ' . $store->table('yc_product_images')
            . ' i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image FROM ' . $store->table('yc_products')
            . ' p JOIN ' . $store->table('yc_categories') . ' c ON c.id = p.category_id WHERE p.id = ? AND p.active = 1 AND c.active = 1', [$id]);
    }

    public function view(array $banner, array $blocks, array $menu, string $url, string $base): array
    {
        $product = null;
        $type = null;
        foreach ($blocks as $key => $rows) { if ($rows !== []) { $product = $rows[0]; $type = $key; break; } }
        $buttonUrl = $type !== null ? $url . '/type?t=' . rawurlencode($type)
            : ($menu !== [] ? $url . '/list?ca=' . rawurlencode($menu[0]['code']) : '');
        if ($banner['mode'] === 'product') $product = self::product($this->store, $banner['product_id']);
        if ($banner['mode'] === 'random') $product = self::randomProduct($blocks);
        $image = null; $caption = ''; $alt = ''; $imageUrl = '';
        if ($banner['mode'] === 'upload') {
            if ($banner['image'] !== '') $image = self::imageUrl($url, $banner['image']);
            $caption = $banner['image_caption'];
            $alt = $banner['image_alt'];
            $imageUrl = self::link($banner['image_url'], $base);
        } elseif ($product !== null && $product['image'] !== null) {
            $image = Images::url($url, (int) $product['id'], $product['image'], 'detail');
            $caption = $alt = $product['name'];
            $imageUrl = $url . '/item?id=' . rawurlencode($product['code']);
            if (in_array($banner['mode'], ['product', 'random'], true)) $buttonUrl = $imageUrl;
        }
        if ($banner['button_url'] !== '') $buttonUrl = self::link($banner['button_url'], $base);
        return ['settings' => $banner, 'image' => $image, 'caption' => $caption, 'alt' => $alt, 'image_url' => $imageUrl, 'button_url' => $buttonUrl];
    }

    /** 공개 메인 목록을 재사용하며 여러 유형에 속한 상품도 같은 확률로 선택한다. */
    private static function randomProduct(array $blocks): ?array
    {
        $candidates = [];
        foreach ($blocks as $rows) foreach ($rows as $product) {
            if (($product['image'] ?? '') !== '') $candidates[(int) $product['id']] = $product;
        }
        if ($candidates === []) return null;
        $candidates = array_values($candidates);
        return $candidates[random_int(0, count($candidates) - 1)];
    }

    private static function link(string $value, string $base): string
    {
        return str_starts_with($value, '/') ? $base . $value : $value;
    }

    public static function imageUrl(string $url, string $filename): string { return $url . '/banner-image?f=' . rawurlencode($filename); }

    public function imageResponse(string $filename, bool $admin, ResponseInterface $response): ResponseInterface
    {
        $banner = $this->settings->all()['banner'];
        if ($filename === '' || $filename !== $banner['image'] || (!$admin && (!$banner['use'] || $banner['mode'] !== 'upload'))) {
            throw DomainError::notFound('이미지를 찾을 수 없습니다.');
        }
        return $this->images->response(self::IMAGE_OWNER, $filename, 'original', $response)
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
