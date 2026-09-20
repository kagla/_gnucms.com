<?php

declare(strict_types=1);

namespace GnuCms\Shop\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Commerce\Orders;
use GnuCms\Shop\HomeBanner;
use GnuCms\Shop\Schema;
use GnuCms\Shop\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class AdminController extends AdminBase
{
    public function handle(string $page, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, $page);
        if ($page === 'dashboard') return $this->dashboard($request, $response, $data);
        if ($page === 'settings') return $this->settings($request, $response, $data);
        throw DomainError::notFound('페이지를 찾을 수 없습니다.');
    }

    private function dashboard(ServerRequestInterface $request, ResponseInterface $response, array $data): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            if (($data['input']['action'] ?? '') !== 'install') throw DomainError::validation(['action' => '작업을 확인해 주세요.']);
            $this->service->install();
            return $this->redirect($response, $data['admin_url'] . '?installed=1');
        }
        $data['status'] = $this->service->schema()->status(Schema::KEY);
        $data['version'] = Schema::VERSION;
        $data['stats'] = $data['ready'] ? $this->service->products->stats() : null;
        $data['order_stats'] = [];
        if ($data['ready']) foreach ($this->service->store->select('SELECT status, COUNT(*) AS count FROM ' . $this->service->store->table('yc_orders') . ' GROUP BY status') as $row) {
            $data['order_stats'][$row['status']] = (int) $row['count'];
        }
        $data['low_stock'] = $data['ready'] ? $this->service->products->lowStock() : ['products' => [], 'options' => []];
        $data['recent_orders'] = $data['ready'] ? array_slice($this->service->orders->listing(null, '', 1, true)['items'], 0, 5) : [];
        $data['statuses'] = Orders::STATUSES;
        if (($data['input']['installed'] ?? '') === '1') $data['notice'] = '쇼핑몰 데이터를 설치했습니다.';
        if (($data['input']['install'] ?? '') === '1') $data['notice'] = '먼저 쇼핑몰 데이터를 설치해 주세요.';
        return $this->render($request, $response, 'dashboard', $data);
    }

    private function settings(ServerRequestInterface $request, ResponseInterface $response, array $data): ResponseInterface
    {
        if ($redirect = $this->requireReady($response, $data)) return $redirect;
        $data['types'] = Settings::TYPE_LABELS;
        $current = $this->service->settings->all();
        $data['banner_modes'] = HomeBanner::MODES;
        $data['banner_products'] = $this->service->banner->choices();
        $data['banner_image_url'] = $current['banner']['image'] === '' ? '' : HomeBanner::imageUrl($data['public_url'], $current['banner']['image']);
        if ($request->getMethod() === 'POST') {
            $upload = $request->getUploadedFiles()['banner_image'] ?? null;
            try {
                $this->service->banner->saveSettings($data['input'], $upload);
                return $this->redirect($response, $data['admin_url'] . '/settings?saved=1');
            } catch (DomainError $e) {
                $response = $response->withStatus($e->status());
                $data['errors'] = $e->details() ?: [$e->getMessage()];
                $data['values'] = array_filter($data['input'], static fn ($value) => is_string($value) || is_int($value)) + $this->flatten($current);
                if ($upload !== null && (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_NO_FILE)) $data['errors'][] = '저장되지 않았습니다. 업로드할 이미지를 다시 선택해 주세요.';
                return $this->render($request, $response, 'settings', $data);
            }
        }
        if (($data['input']['saved'] ?? '') === '1') $data['notice'] = '설정을 저장했습니다.';
        $data['values'] = $this->flatten($this->service->settings->all());
        return $this->render($request, $response, 'settings', $data);
    }

    /** 저장 구조를 폼 입력 이름으로 편다. */
    private function flatten(array $settings): array
    {
        $flat = [];
        foreach ($settings['banner'] as $key => $value) if ($key !== 'image') $flat['banner_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
        foreach ($settings['main'] as $type => $block) foreach ($block as $key => $value) $flat['main_' . $type . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
        foreach (['category', 'type', 'search', 'related', 'detail'] as $section) foreach ($settings[$section] as $key => $value) $flat[$section . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
        $flat['show_tax'] = $settings['show_tax'] ? '1' : '0';
        $flat['shipping_content'] = $settings['shipping']['content'];
        $flat['shipping_fee'] = (string) $settings['shipping']['fee'];
        $flat['shipping_free_minimum'] = (string) $settings['shipping']['free_minimum'];
        $flat['order_notice'] = $settings['order_notice'];
        $flat['exchange_content'] = $settings['exchange']['content'];
        $flat['payment_environment'] = $settings['payment']['environment'];
        $flat['payment_manual_enabled'] = $settings['payment']['manual']['enabled'] ? '1' : '0';
        foreach (['bank', 'account', 'holder'] as $key) $flat['payment_manual_' . $key] = $settings['payment']['manual'][$key];
        foreach ($settings['payment']['deadline_hours'] as $key => $hours) $flat['payment_deadline_' . $key] = (string) $hours;
        return $flat;
    }
}
