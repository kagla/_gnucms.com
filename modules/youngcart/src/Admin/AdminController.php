<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Modules\YoungCart\Schema;
use GnuCms\Modules\YoungCart\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
        $data['low_stock'] = $data['ready'] ? $this->service->products->lowStock() : ['products' => [], 'options' => []];
        if (($data['input']['installed'] ?? '') === '1') $data['notice'] = '쇼핑몰 데이터를 설치했습니다.';
        if (($data['input']['install'] ?? '') === '1') $data['notice'] = '먼저 쇼핑몰 데이터를 설치해 주세요.';
        return $this->render($request, $response, 'dashboard', $data);
    }

    private function settings(ServerRequestInterface $request, ResponseInterface $response, array $data): ResponseInterface
    {
        if ($redirect = $this->requireReady($response, $data)) return $redirect;
        $data['types'] = Settings::TYPE_LABELS;
        if ($request->getMethod() === 'POST') {
            try {
                $this->service->settings->save($data['input']);
                return $this->redirect($response, $data['admin_url'] . '/settings?saved=1');
            } catch (DomainError $e) {
                $response = $response->withStatus($e->status());
                $data['errors'] = $e->details() ?: [$e->getMessage()];
                $data['values'] = $data['input'];
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
        foreach ($settings['main'] as $type => $block) foreach ($block as $key => $value) $flat['main_' . $type . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
        foreach (['category', 'type', 'search', 'related', 'detail'] as $section) foreach ($settings[$section] as $key => $value) $flat[$section . '_' . $key] = $key === 'use' ? ($value ? '1' : '0') : (string) $value;
        $flat['show_tax'] = $settings['show_tax'] ? '1' : '0';
        $flat['shipping_content'] = $settings['shipping']['content'];
        $flat['exchange_content'] = $settings['exchange']['content'];
        return $flat;
    }
}
