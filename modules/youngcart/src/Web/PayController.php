<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart\Web;

use GnuCms\Error\DomainError;
use GnuCms\Modules\YoungCart\Commerce\Payments;
use GnuCms\Modules\YoungCart\Input;
use GnuCms\Modules\YoungCart\Service;
use GnuCms\Payment\CallbackToken;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * 결제 페이지(주문 주인만)와 이니시스가 부르는 콜백(세션 없음). 콜백은 ExternalRequests 가
 * 인증기를 본문보다 먼저 부르므로 인증은 쿼리(order=원장 키, state=HMAC)만으로 한다.
 */
final class PayController
{
    public function __construct(private Service $service, private string $routePrefix, private ?string $adminRoutePrefix) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $base = RouteContext::fromRequest($request)->getBasePath();
        $url = $base . $this->routePrefix;
        $view = View::forExtension($request, 'youngcart', dirname(__DIR__, 2) . '/templates');
        $response = $response->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
        $this->service->requireReady();
        $identity = $this->service->app->guestAcl()->identity();
        $userId = $identity->isGuest() ? null : (int) $identity->sub();
        $_SESSION['yc_guest_orders'] ??= [];
        $number = Input::text($request->getQueryParams()['number'] ?? '', 'number', 32, false);
        $order = $this->service->orders->owned($number, $userId, $_SESSION['yc_guest_orders']);
        $site = $this->siteUrl();
        try {
            $payment = $this->service->payments->checkout($order, self::device($request->getHeaderLine('User-Agent')),
                $site . $this->routePrefix . '/order?number=' . rawurlencode($number) . '&pay=closed', $site . $this->routePrefix . '/pay/callback');
        } catch (DomainError $e) {
            if ($e->status() >= 500) throw $e;
            return $response->withStatus(303)->withHeader('Location', $url . '/order?number=' . rawurlencode($number) . '&pay=failed');
        }
        return $view->render($response, 'pay', ['url' => $url, 'base' => $base, 'order' => $order, 'payment' => $payment,
            'method_label' => Payments::METHODS[$order['payment_method']] ?? $order['payment_method']]);
    }

    /** ExternalRequests 인증기: 쿼리의 원장 키로 주문을 찾고 state 가 그 주문·결제사·설정 판의 HMAC 과 맞아야 한다. */
    public function callbackAuthenticate(ServerRequestInterface $request): bool
    {
        $order = $this->orderFromQuery($request);
        return $order !== null && CallbackToken::verify($this->service->app, Payments::gatewayOrder($order), $request->getQueryParams()['state'] ?? null);
    }

    /** ExternalRequests 처리기: 승인·조회 뒤 주문 화면으로 보낸다. 실패는 pay=failed 로 알린다. */
    public function callback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $order = $this->orderFromQuery($request);
        if ($order === null) throw DomainError::forbidden('주문을 확인할 수 없습니다.');
        $body = $request->getParsedBody();
        $suffix = '';
        try {
            $this->service->payments->complete($order, is_array($body) ? $body : []);
        } catch (DomainError) {
            $suffix = '&pay=failed';
        }
        return $response->withStatus(303)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Location', $this->siteUrl() . $this->routePrefix . '/order?number=' . rawurlencode($order['number']) . $suffix);
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

    private function siteUrl(): string
    {
        return rtrim((string) $this->service->app->config('app.url', GNUCMS_URL), '/');
    }
}
