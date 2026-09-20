<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Modules\YoungCart\Commerce\Orders;
use GnuCms\Modules\YoungCart\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OrderController extends AdminBase
{
    public function handle(string $page, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, $page);
        if ($redirect = $this->requireReady($response, $data)) return $redirect;
        $data['statuses'] = Orders::STATUSES;
        if ($page === 'orders') {
            $data['status_filter'] = Input::text($data['input']['status'] ?? '', 'status', 20);
            $data['q'] = Input::text($data['input']['q'] ?? '', 'q', 100);
            $data['list'] = $this->service->orders->listing(null, $data['status_filter'], $this->page($data['input']['page'] ?? ''), true, $data['q']);
            return $this->render($request, $response, 'orders', $data);
        }
        $id = Input::id($data['input']['id'] ?? null);
        if ($request->getMethod() === 'POST') {
            try {
                $this->service->orders->transition($id, Input::text($data['input']['from'] ?? '', 'from', 20), Input::text($data['input']['status'] ?? '', 'status', 20),
                    $data['actor'], $data['input']);
                return $this->redirect($response, $data['admin_url'] . '/orders/detail?id=' . $id . '&saved=1');
            } catch (DomainError $e) { $data['errors'] = $e->details() ?: [$e->getMessage()]; $response = $response->withStatus($e->status()); }
        }
        $data['order'] = $this->service->orders->get($id);
        $data['next'] = Orders::NEXT[$data['order']['status']];
        if (($data['input']['saved'] ?? '') === '1') $data['notice'] = '주문 상태를 변경했습니다.';
        return $this->render($request, $response, 'order', $data);
    }
}
