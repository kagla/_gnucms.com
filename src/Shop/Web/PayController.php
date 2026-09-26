<?php

declare(strict_types=1);

namespace GnuCms\Shop\Web;

use GnuCms\Error\DomainError;
use GnuCms\Payment\CallbackToken;
use GnuCms\Shop\Commerce\CheckoutIntents;
use GnuCms\Shop\Commerce\Orders;
use GnuCms\Shop\Commerce\Payments;
use GnuCms\Shop\Input;
use GnuCms\Shop\Service;
use GnuCms\View\View;
use GnuCms\Web\LoginRedirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * 결제 페이지(주문 주인만)와 PG가 부르는 콜백(세션 없음). 콜백은 ExternalRequests 가
 * 인증기를 본문보다 먼저 부르므로 인증은 쿼리(order=원장 키, state=HMAC)만으로 한다.
 */
final class PayController
{
    public function __construct(private Service $service, private string $routePrefix, private ?string $adminRoutePrefix) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $base = RouteContext::fromRequest($request)->getBasePath();
        $url = $base . $this->routePrefix;
        $view = View::forShop($request);
        $response = $response->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
        $identity = $this->service->app->guestAcl()->identity();
        $userId = $identity->isGuest() ? null : (int) $identity->sub();
        if ($userId === null) {
            $query = $request->getQueryParams();
            $destination = $url . '/pay' . (is_string($query['ref'] ?? null) ? '?ref=' . rawurlencode($query['ref'])
                : (is_string($query['number'] ?? null) ? '?number=' . rawurlencode($query['number']) : ''));
            return $response->withStatus(303)->withHeader('Location', LoginRedirect::loginUrl(RouteContext::fromRequest($request)->getRouteParser(), $destination));
        }
        $query = $request->getQueryParams();
        if (isset($query['ref'])) {
            $reference = Input::text($query['ref'], 'ref', 65, false);
            $order = $this->service->orders->ownedReference($reference, $userId);
        } else {
            $number = Input::text($query['number'] ?? '', 'number', 32, false);
            $order = $this->service->orders->owned($number, $userId);
            return $response->withStatus(303)->withHeader('Location', $url . '/pay?ref=' . rawurlencode(Orders::reference($order)));
        }
        $requestedReference = $reference;
        $reference = Orders::reference($order);
        if (!hash_equals($reference, $requestedReference)) {
            return $response->withStatus(303)->withHeader('Location', $url . '/pay?ref=' . rawurlencode($reference));
        }
        $site = $this->siteUrl();
        try {
            $payment = $this->service->payments->checkout($order, self::device($request->getHeaderLine('User-Agent')),
                $site . $this->routePrefix . '/order?ref=' . rawurlencode($reference) . '&pay=closed', $site . $this->routePrefix . '/pay/callback');
        } catch (DomainError $e) {
            if ($e->status() >= 500) throw $e;
            return $response->withStatus(303)->withHeader('Location', $url . '/order?ref=' . rawurlencode($reference) . '&pay=failed');
        }
        return $view->render($response, 'pay', ['url' => $url, 'base' => $base, 'order' => $order, 'payment' => $payment,
            'order_ref' => $reference,
            'checkout_template' => $this->service->app->paymentProviders()->get($order['payment_provider'])->checkoutTemplate(),
            'method_label' => Payments::METHODS[$order['payment_method']] ?? $order['payment_method']]);
    }

    /** ExternalRequests 인증기: 쿼리의 원장 키로 주문을 찾고 state 가 그 주문·결제사·설정 판의 HMAC 과 맞아야 한다. */
    public function callbackAuthenticate(ServerRequestInterface $request): bool
    {
        $order = $this->orderFromQuery($request);
        if ($order !== null) return CallbackToken::verify($this->service->app, Payments::gatewayOrder($order), $request->getQueryParams()['state'] ?? null);
        $intent = $this->intentFromQuery($request);
        return $intent !== null && CallbackToken::verify($this->service->app, CheckoutIntents::gatewayOrder($intent), $request->getQueryParams()['state'] ?? null);
    }

    /** 토스 SDK는 GET 리다이렉트를 사용하므로 서명된 쿼리를 직접 검증한다. */
    public function tossReturn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $order = $this->orderFromQuery($request);
        $intent = $order === null ? $this->intentFromQuery($request) : null;
        $provider = $order['payment_provider'] ?? $intent['payment']['provider'] ?? '';
        if ($provider !== 'toss' || !$this->callbackAuthenticate($request)) {
            throw DomainError::forbidden('토스 결제 결과의 서명을 확인할 수 없습니다.');
        }
        return $this->callback($request, $response);
    }

    /** ExternalRequests 처리기: 승인·조회 뒤 주문 화면으로 보낸다. 실패는 pay=failed 로 알린다. */
    public function callback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($request->getQueryParams()['event'] ?? '') === 'notify') {
            $order = $this->orderFromQuery($request);
            $body = $request->getParsedBody();
            if ($order === null || $order['payment_method'] !== 'virtual_account' || !is_array($body)
                || ($body['P_STATUS'] ?? '') !== '02' || ($body['P_TYPE'] ?? '') !== 'VBANK'
                || ($body['P_OID'] ?? '') !== $order['payment_id'] || (string) ($body['P_AMT'] ?? '') !== (string) $order['total']) {
                throw DomainError::forbidden('가상계좌 입금 통보를 확인할 수 없습니다.');
            }
            $updated = $this->service->payments->sync($order);
            if ($updated['status'] !== 'paid') throw DomainError::serviceUnavailable('가상계좌 입금 결과가 아직 확인되지 않았습니다.');
            $response->getBody()->write('OK');
            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        }
        $order = $this->orderFromQuery($request);
        $intent = $order === null ? $this->intentFromQuery($request) : null;
        if ($order === null && $intent === null) throw DomainError::forbidden('주문서를 확인할 수 없습니다.');
        $body = $request->getParsedBody();
        $provider = (string) ($intent['payment']['provider'] ?? $order['payment_provider'] ?? '');
        $query = $request->getQueryParams();
        if ($provider === 'toss') {
            // 성공/실패 리다이렉트의 PG 항목만 본문처럼 다룬다. 인증용 order/state는 그대로 둔다.
            $body = array_intersect_key($query, array_flip(['paymentKey', 'orderId', 'amount', 'code', 'message', 'result']));
        }
        $declineCode = !is_array($body) ? null : match ($provider) {
            'kcp' => $body['res_cd'] ?? null,
            'nicepay' => $body['authResultCode'] ?? null,
            'toss' => ($body['result'] ?? '') === 'fail' ? 'TOSS_FAILED' : null,
            default => $body['P_STATUS'] ?? null,
        };
        $declineSuccess = in_array($provider, ['kcp', 'nicepay'], true) ? '0000' : '00';
        if ($intent !== null && is_string($declineCode) && $declineCode !== $declineSuccess) {
            $code = $declineCode;
            if (preg_match('/^[A-Za-z0-9_-]{1,16}$/D', $code)) {
                try {
                    $message = $body['P_RMESG'] ?? $body['P_RMESG1'] ?? $body['res_msg'] ?? $body['authResultMsg'] ?? $body['message'] ?? '';
                    $this->service->checkoutIntents->decline($intent, $code, is_string($message) ? $message : '');
                    error_log('GNUCMS payment authentication failed: ' . $intent['payment']['id'] . ' (status=' . $code . ')');
                    $destination = '/checkout?flow=' . rawurlencode($intent['flow']) . '&pay=declined&code=' . rawurlencode($code);
                } catch (DomainError) {
                    $destination = '/checkout?flow=' . rawurlencode($intent['flow']) . '&pay=review&reference=' . rawurlencode($intent['payment']['id']);
                }
                return $response->withStatus(303)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer')
                    ->withHeader('Location', $this->siteUrl() . $this->routePrefix . $destination);
            }
        }
        $suffix = '';
        $payState = 'failed';
        $failureCode = '';
        try {
            $order = $intent !== null
                ? $this->service->checkoutIntents->complete($intent, is_array($body) ? $body : [])
                : $this->service->payments->complete($order, is_array($body) ? $body : []);
        } catch (\Throwable $error) {
            $reference = $request->getQueryParams()['order'] ?? '';
            $failureCode = $error instanceof DomainError ? $error->code() : 'UNEXPECTED';
            $reason = $error instanceof DomainError
                ? $error->code() . ':' . implode(',', array_keys($error->details())) . ':' . $error->getMessage()
                : $error::class;
            error_log('GNUCMS payment callback failed: ' . (is_string($reference) ? $reference : '') . ' (' . $reason . ')');
            $suffix = '&pay=failed';
            $id = $request->getQueryParams()['order'] ?? '';
            if (is_string($id)) $order = $this->service->orders->byPaymentId($id);
            if ($order !== null && $order['status'] !== 'pending') $suffix = '';
            if ($intent !== null && is_string($id)) {
                $updated = $this->service->checkoutIntents->find($id, $intent['payment']['provider']);
                $payState = match ($updated['status'] ?? '') { 'refunded' => 'refunded', 'declined' => 'declined', 'needs_review', 'approval_review' => 'review', default => 'failed' };
                if ($payState === 'declined') $failureCode = (string) ($updated['failure_code'] ?? '');
            }
        }
        $destination = $order !== null ? '/order?ref=' . rawurlencode(Orders::reference($order)) . $suffix
            : '/checkout?flow=' . rawurlencode($intent['flow']) . '&pay=' . $payState
                . (in_array($payState, ['failed', 'review', 'declined'], true) ? '&reference=' . rawurlencode($intent['payment']['id']) : '')
                . (in_array($payState, ['failed', 'declined'], true) && $failureCode !== '' ? '&code=' . rawurlencode($failureCode) : '');
        return $response->withStatus(303)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Location', $this->siteUrl() . $this->routePrefix . $destination);
    }

    public static function device(string $userAgent): string
    {
        return preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $userAgent) ? 'mobile' : 'web';
    }

    private function orderFromQuery(ServerRequestInterface $request): ?array
    {
        $id = $request->getQueryParams()['order'] ?? '';
        return is_string($id) ? $this->service->orders->byPaymentId($id) : null;
    }

    private function intentFromQuery(ServerRequestInterface $request): ?array
    {
        $query = $request->getQueryParams();
        $id = $query['order'] ?? null;
        $provider = $query['provider'] ?? null;
        return is_string($id) && is_string($provider) ? $this->service->checkoutIntents->find($id, $provider) : null;
    }

    private function siteUrl(): string
    {
        return rtrim((string) $this->service->app->config('app.url', GNUCMS_URL), '/');
    }
}
