<?php

declare(strict_types=1);

namespace GnuCms\Shop\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Commerce\Orders;
use GnuCms\Payment\Journal;
use GnuCms\Shop\HomeBanner;
use GnuCms\Shop\Input;
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
        if ($page === 'payment-failures') return $this->paymentFailures($request, $response, $data);
        throw DomainError::notFound('페이지를 찾을 수 없습니다.');
    }

    private function paymentFailures(ServerRequestInterface $request, ResponseInterface $response, array $data): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            try {
                $reference = Input::text($data['input']['reference'] ?? '', 'reference', 32, false);
                $intent = $this->service->checkoutIntents->find($reference);
                if ($intent === null) throw DomainError::notFound('결제 요청을 찾을 수 없습니다.');
                $order = $this->service->checkoutIntents->reconcile($intent);
                return $this->redirect($response, $data['admin_url'] . '/orders/detail?id=' . (int) $order['id']);
            } catch (DomainError $error) {
                $data['errors']['payment'] = $error->getMessage();
            }
        }
        $filter = Input::text($data['input']['status'] ?? '', 'status', 20);
        if (!in_array($filter, ['', 'declined', 'review'], true)) $filter = '';
        $search = Input::text($data['input']['q'] ?? '', 'q', 100);
        $journal = new Journal($this->service->app->paymentSettings('inicis'));
        $journal->indexUnindexed();
        $data['index_remaining'] = $journal->unindexedCount();
        $data['filter'] = $filter;
        $data['q'] = $search;
        $data['list'] = $journal->failures($filter, $search, $this->page($data['input']['page'] ?? ''));
        $data['statuses'] = ['declined' => '결제 거절', 'review' => '결과 확인 필요'];
        $data['payment_methods'] = \GnuCms\Shop\Commerce\Payments::METHODS;
        return $this->render($request, $response, 'payment_failures', $data);
    }

    private function dashboard(ServerRequestInterface $request, ResponseInterface $response, array $data): ResponseInterface
    {
        $data['stats'] = $this->service->products->stats();
        $data['order_stats'] = [];
        foreach ($this->service->store->select('SELECT status, COUNT(*) AS count FROM ' . $this->service->store->table('yc_orders') . ' GROUP BY status') as $row) {
            $data['order_stats'][$row['status']] = (int) $row['count'];
        }
        $data['low_stock'] = $this->service->products->lowStock();
        $data['recent_orders'] = array_slice($this->service->orders->listing(null, '', 1, true)['items'], 0, 5);
        $data['statuses'] = Orders::STATUSES;
        return $this->render($request, $response, 'dashboard', $data);
    }

    private function settings(ServerRequestInterface $request, ResponseInterface $response, array $data): ResponseInterface
    {
        $data['payment_providers'] = $this->service->app->paymentProviders()->labels();
        $inicisPayment = $this->service->app->paymentSettings('inicis');
        $data['inicis_environments'] = ['test' => $inicisPayment->summary('test'), 'live' => $inicisPayment->summary('live')];
        $data['inicis_fields'] = $inicisPayment->definition()->fields();
        $data['types'] = Settings::TYPE_LABELS;
        $data['categories'] = $this->service->categories->optionDetails();
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
        if (($data['input']['payment_saved'] ?? '') === '1') $data['notice'] = '이니시스 결제 설정을 저장했습니다. 선택한 환경과 결제 수단에 적용됩니다.';
        if (($data['input']['payment_disabled'] ?? '') === '1') $data['notice'] = '선택한 이니시스 환경의 결제 실행을 정지했습니다.';
        $data['values'] = $this->flatten($this->service->settings->all());
        return $this->render($request, $response, 'settings', $data);
    }

    /** 저장 구조를 폼 입력 이름으로 편다. */
    private function flatten(array $settings): array
    {
        $flat = [];
        $flat['visible'] = $settings['visible'] ? '1' : '0';
        foreach ($settings['banner'] as $key => $value) if ($key !== 'image') $flat['banner_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
        foreach (Settings::TYPES as $type) foreach ($settings['main'][$type] as $key => $value) $flat['main_' . $type . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) ($value ?? '');
        // 분류 블록은 줄 목록 그대로 편다(폼이 main_categories[i][…] 로 되돌려 보낸다).
        $flat['main_categories'] = array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'columns' => (string) $row['columns'], 'rows' => (string) $row['rows']], $settings['main']['categories']);
        foreach ($settings['auto'] as $key => $days) $flat['auto_' . $key] = (string) $days;
        foreach (['category', 'type', 'search', 'related', 'detail'] as $section) foreach ($settings[$section] as $key => $value) $flat[$section . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
        $flat['show_tax'] = $settings['show_tax'] ? '1' : '0';
        $flat['shipping_content'] = $settings['shipping']['content'];
        $flat['shipping_fee'] = (string) $settings['shipping']['fee'];
        $flat['shipping_free_minimum'] = (string) $settings['shipping']['free_minimum'];
        $flat['order_notice'] = $settings['order_notice'];
        $flat['exchange_content'] = $settings['exchange']['content'];
        $flat['payment_provider'] = $settings['payment']['provider'];
        $flat['payment_environment'] = $settings['payment']['environment'];
        foreach (['card', 'bank_transfer', 'virtual_account', 'mobile'] as $method) $flat['payment_method_' . $method] = !empty($settings['payment']['methods'][$method]) ? '1' : '0';
        $flat['payment_manual_enabled'] = $settings['payment']['manual']['enabled'] ? '1' : '0';
        foreach (['bank', 'account', 'holder'] as $key) $flat['payment_manual_' . $key] = $settings['payment']['manual'][$key];
        foreach ($settings['payment']['deadline_hours'] as $key => $hours) $flat['payment_deadline_' . $key] = (string) $hours;
        return $flat;
    }
}
