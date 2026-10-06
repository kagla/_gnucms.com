<?php

declare(strict_types=1);

namespace GnuCms\Shop\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class OperationsController extends AdminBase
{
    public function shipments(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, 'shipments');
        $data['carriers'] = \GnuCms\Shop\Settings::carriers();
        $data['default_carrier'] = $this->service->settings->all()['shipping']['default_carrier'];
        if ($request->getMethod() === 'POST') {
            try {
                $input = $data['input'];
                $id = Input::id($input['id'] ?? null);
                $from = Input::text($input['from'] ?? '', 'from', 20, false);
                if (($input['action'] ?? '') !== 'ship' || !in_array($from, ['paid', 'confirmed'], true))
                    throw DomainError::validation(['status' => '발송할 주문을 확인해 주세요.']);
                $carrier = Input::text($input['carrier'] ?? '', 'carrier', 100, false);
                if (!isset($data['carriers'][$carrier])) throw DomainError::validation(['carrier' => '택배사를 선택해 주세요.']);
                $this->service->orders->transition($id, $from, 'shipped', $data['actor'], ['carrier' => $carrier, 'tracking_number' => $input['tracking_number'] ?? '']);
                return $this->redirect($response, $data['admin_url'] . '/shipments?processed=1');
            } catch (DomainError $e) {
                $data['errors'] = $e->details() ?: [$e->getMessage()]; $response = $response->withStatus($e->status());
            }
        }
        $data['q'] = Input::text($data['input']['q'] ?? '', 'q', 100);
        $data['list'] = $this->service->fulfillment->ready($data['q'], $this->page($data['input']['page'] ?? ''));
        $processed = is_string($data['input']['processed'] ?? null) && preg_match('/^[1-9][0-9]{0,4}$/D', $data['input']['processed'])
            ? (int) $data['input']['processed'] : 0;
        $data['notice'] = $processed > 0 ? number_format($processed) . '건을 배송 중으로 변경했습니다.' : '';
        return $this->render($request, $response, 'shipments', $data);
    }

    public function shipmentExport(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ids = $this->service->fulfillment->selectedIds($this->input($request)['ids'] ?? null);
        return $this->download($response, $this->service->fulfillment->export($ids), 'shipments-' . date('Ymd-His') . '.csv');
    }

    public function shipmentPrint(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, 'shipments');
        $ids = $this->service->fulfillment->selectedIds($data['input']['ids'] ?? null);
        $data['orders'] = $this->service->fulfillment->selectedOrders($ids);
        return $this->render($request, $response, 'shipment_print', $data);
    }

    public function shipmentImport(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, 'shipments');
        try {
            $count = $this->service->fulfillment->importAndShip($this->uploadedContents($request, 2 * 1024 * 1024), $data['actor']);
            return $this->redirect($response, $data['admin_url'] . '/shipments?processed=' . $count);
        } catch (DomainError $e) {
            $data['errors'] = $e->details() ?: [$e->getMessage()];
            $data['q'] = '';
            $data['list'] = $this->service->fulfillment->ready('', 1);
            $data['carriers'] = \GnuCms\Shop\Settings::carriers();
            $data['default_carrier'] = $this->service->settings->all()['shipping']['default_carrier'];
            return $this->render($request, $response->withStatus($e->status()), 'shipments', $data);
        }
    }

    public function reports(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, 'reports');
        try {
            $data['range'] = $this->service->reports->range($data['input']['from'] ?? '', $data['input']['to'] ?? '', $data['input']['group'] ?? 'day');
            $data['report'] = $this->service->reports->sales($data['range']);
        } catch (DomainError $e) {
            $data['errors'] = $e->details() ?: [$e->getMessage()];
            $data['range'] = $this->service->reports->range('', '', 'day');
            $data['report'] = $this->service->reports->sales($data['range']);
            $response = $response->withStatus($e->status());
        }
        return $this->render($request, $response, 'reports', $data);
    }

    public function reportExport(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $range = $this->service->reports->range($input['from'] ?? '', $input['to'] ?? '', $input['group'] ?? 'day');
        return $this->download($response, $this->service->reports->csv($this->service->reports->sales($range)),
            'sales-' . $range['from'] . '-' . $range['to'] . '.csv');
    }

    public function settlements(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, 'settlements');
        $data['providers'] = $this->service->app->paymentProviders()->labels();
        $data['provider'] = Input::text($data['input']['provider'] ?? '', 'provider', 32);
        if ($data['provider'] !== '' && !isset($data['providers'][$data['provider']])) $data['provider'] = '';
        $data['status_filter'] = Input::text($data['input']['status'] ?? '', 'status', 20);
        try {
            $data['range'] = $this->service->reports->range($data['input']['from'] ?? '', $data['input']['to'] ?? '', 'day');
        } catch (DomainError $e) {
            $data['errors'] = $e->details() ?: [$e->getMessage()];
            $data['range'] = $this->service->reports->range('', '', 'day');
            $response = $response->withStatus($e->status());
        }
        $data['list'] = $this->service->settlements->reconcile($data['range'], $data['provider'], $data['status_filter']);
        $imported = is_string($data['input']['imported'] ?? null) && preg_match('/^[0-9]{1,5}$/D', $data['input']['imported']) ? (int) $data['input']['imported'] : null;
        $skipped = is_string($data['input']['skipped'] ?? null) && preg_match('/^[0-9]{1,5}$/D', $data['input']['skipped']) ? (int) $data['input']['skipped'] : 0;
        if ($imported !== null) $data['notice'] = number_format($imported) . '건을 가져왔습니다.' . ($skipped > 0 ? ' 중복 ' . number_format($skipped) . '건은 건너뛰었습니다.' : '');
        return $this->render($request, $response, 'settlements', $data);
    }

    public function settlementImport(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, 'settlements');
        try {
            $result = $this->service->settlements->import($this->uploadedContents($request, 5 * 1024 * 1024));
            return $this->redirect($response, $data['admin_url'] . '/settlements?imported=' . $result['inserted'] . '&skipped=' . $result['skipped']);
        } catch (DomainError $e) {
            $query = $request->getQueryParams();
            $data['errors'] = $e->details() ?: [$e->getMessage()];
            $data['providers'] = $this->service->app->paymentProviders()->labels(); $data['provider'] = ''; $data['status_filter'] = '';
            $data['range'] = $this->service->reports->range($query['from'] ?? '', $query['to'] ?? '', 'day');
            $data['list'] = $this->service->settlements->reconcile($data['range']);
            return $this->render($request, $response->withStatus($e->status()), 'settlements', $data);
        }
    }

    public function settlementTemplate(ResponseInterface $response): ResponseInterface
    {
        return $this->download($response, $this->service->settlements->adapter()->template(), 'settlement-template.csv');
    }

    private function uploadedContents(ServerRequestInterface $request, int $limit): string
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) throw DomainError::validation(['file' => 'CSV 파일을 선택해 주세요.']);
        $size = $file->getSize();
        if ($size === null || $size < 1 || $size > $limit) throw DomainError::validation(['file' => number_format($limit / 1024 / 1024) . 'MB 이하 CSV 파일을 선택해 주세요.']);
        $stream = $file->getStream(); $stream->rewind();
        return $stream->getContents();
    }

    private function download(ResponseInterface $response, string $contents, string $filename): ResponseInterface
    {
        $response->getBody()->write($contents);
        return $response->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Cache-Control', 'no-store');
    }
}
