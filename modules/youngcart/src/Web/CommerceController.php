<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart\Web;

use GnuCms\Account\UserRepository;
use GnuCms\Error\DomainError;
use GnuCms\Modules\YoungCart\Commerce\Orders;
use GnuCms\Modules\YoungCart\Images;
use GnuCms\Modules\YoungCart\Input;
use GnuCms\Modules\YoungCart\Service;
use GnuCms\Modules\YoungCart\Settings;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

final class CommerceController
{
    public function __construct(private Service $service, private string $routePrefix, private ?string $adminRoutePrefix) {}

    public function handle(string $page, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $base = RouteContext::fromRequest($request)->getBasePath();
        $url = $base . $this->routePrefix;
        $identity = $this->service->app->guestAcl()->identity();
        $userId = $identity->isGuest() ? null : (int) $identity->sub();
        $input = $request->getMethod() === 'POST' ? $request->getParsedBody() : $request->getQueryParams();
        if (!is_array($input)) $input = [];
        $view = View::forExtension($request, 'youngcart', dirname(__DIR__, 2) . '/templates');
        $response = $response->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'same-origin');
        $data = ['url' => $url, 'base' => $base, 'admin_url' => $base . ($this->adminRoutePrefix ?? '/admin/shop'), 'admin' => $identity->isAdmin(),
            'page' => $page, 'user_id' => $userId, 'input' => [], 'errors' => [], 'notice' => '', 'type_labels' => Settings::TYPE_LABELS,
            'statuses' => Orders::STATUSES, 'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'img' => static fn (int $id, ?string $file, string $size): ?string => $file === null || $file === '' ? null : Images::url($url, $id, $file, $size)];
        if (!$this->service->ready()) return $view->render($response, 'notready', $data);
        $data['settings'] = $this->service->settings->all();
        $data['menu'] = $this->service->categories->children('', true);
        $_SESSION['yc_cart'] ??= [];
        $_SESSION['yc_owner'] ??= bin2hex(random_bytes(32));
        $_SESSION['yc_guest_orders'] ??= [];
        $data['cart_count'] = array_sum(array_column($_SESSION['yc_cart'], 'quantity'));
        if ($page === 'cart/add') {
            try {
                $buy = ($input['action'] ?? '') === 'buy';
                $cart = $this->service->cart->add($buy ? [] : $_SESSION['yc_cart'], $input);
                $_SESSION[$buy ? 'yc_buy' : 'yc_cart'] = $cart;
                return $this->redirect($response, $url . ($buy ? '/checkout?flow=buy' : '/cart?added=1'));
            } catch (DomainError $e) {
                $data['errors'] = $e->details() ?: [$e->getMessage()];
                $response = $response->withStatus($e->status());
                $page = $data['page'] = 'cart';
            }
        }
        if ($page === 'cart') {
            if ($request->getMethod() === 'POST' && $data['errors'] === []) {
                try {
                    if (isset($input['remove'])) {
                        $remove = Input::text($input['remove'], 'remove', 40, false);
                        $quantities = [$remove => 0];
                    } else {
                        $quantities = $input['quantities'] ?? [];
                        if (!is_array($quantities)) throw DomainError::validation(['quantity' => '수량을 확인해 주세요.']);
                    }
                    $_SESSION['yc_cart'] = $this->service->cart->update($_SESSION['yc_cart'], $quantities);
                    return $this->redirect($response, $url . '/cart?updated=1');
                } catch (DomainError $e) { $data['errors'] = $e->details() ?: [$e->getMessage()]; $response = $response->withStatus($e->status()); }
            }
            if (($input['added'] ?? '') === '1') $data['notice'] = '장바구니에 상품을 담았습니다.';
            if (($input['updated'] ?? '') === '1') $data['notice'] = '장바구니를 변경했습니다.';
            $data['quote'] = $this->service->cart->quote($_SESSION['yc_cart']);
            $data['errors'] += $data['quote']['errors'];
            return $view->render($response, 'cart', $data);
        }
        if ($page === 'checkout') return $this->checkout($request, $response, $view, $data, $input, $userId);
        if ($page === 'orders') {
            if ($request->getMethod() === 'POST') {
                try {
                    $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
                    $order = $this->service->orders->lookup($input, is_string($ip) ? $ip : null);
                    $this->grantGuest((int) $order['id']);
                    return $this->redirect($response, $url . '/order?number=' . rawurlencode($order['number']));
                } catch (DomainError $e) { $data['errors'] = $e->details() ?: [$e->getMessage()]; $response = $response->withStatus($e->status()); }
            }
            $data['input'] = $this->safeValues($input);
            $data['list'] = $this->service->orders->listing($userId, '', $this->page($input['page'] ?? '1'));
            return $view->render($response, 'orders', $data);
        }
        if ($page === 'order' || $page === 'order/cancel') {
            $number = Input::text($input['number'] ?? '', 'number', 32, false);
            $order = $this->service->orders->owned($number, $userId, $_SESSION['yc_guest_orders']);
            if ($page === 'order/cancel') {
                try {
                    $this->service->orders->transition((int) $order['id'], $order['status'], 'cancelled', $userId === null ? 'guest' : 'user:' . $userId,
                        ['note' => '구매자가 주문을 취소했습니다.'], true);
                    return $this->redirect($response, $url . '/order?number=' . rawurlencode($number));
                } catch (DomainError $e) { $data['errors'] = $e->details() ?: [$e->getMessage()]; $response = $response->withStatus($e->status()); }
                $order = $this->service->orders->owned($number, $userId, $_SESSION['yc_guest_orders']);
            }
            $data['order'] = $order;
            $payState = $input['pay'] ?? '';
            $data['pay_state'] = in_array($payState, ['closed', 'failed'], true) ? $payState : '';
            $data['pay_url'] = $order['status'] === 'pending' && $this->service->payments->isPgOrder($order) && ((int) $order['pay_by'] === 0 || (int) $order['pay_by'] > \GnuCms\Support\Clock::timestamp())
                ? $url . '/pay?number=' . rawurlencode($number) : null;
            $data['method_labels'] = \GnuCms\Modules\YoungCart\Commerce\Payments::METHODS;
            $data['just_ordered'] = ($_SESSION['yc_just_ordered'] ?? null) === $number;
            unset($_SESSION['yc_just_ordered']);
            return $view->render($response, 'order', $data);
        }
        throw DomainError::notFound('페이지를 찾을 수 없습니다.');
    }

    private function checkout(ServerRequestInterface $request, ResponseInterface $response, \GnuCms\View\PhpView $view, array $data, array $input, ?int $userId): ResponseInterface
    {
        $post = $request->getMethod() === 'POST';
        $flow = ($input['flow'] ?? '') === 'buy' ? 'buy' : 'cart';
        $_SESSION['yc_checkout'] ??= [];
        $token = $post ? Input::text($input['checkout_token'] ?? '', 'checkout_token', 64) : bin2hex(random_bytes(32));
        if ($post) {
            $issued = $_SESSION['yc_checkout'][$token] ?? null;
            if ($issued === null || $issued['flow'] !== $flow || $issued['user_id'] !== $userId) throw DomainError::forbidden('주문서를 다시 열어 주세요.');
            if ($existing = $this->service->orders->submitted($token, $_SESSION['yc_owner'], $userId)) {
                return $this->redirect($response, $data['url'] . '/order?number=' . rawurlencode($existing['number']));
            }
        }
        $cart = $_SESSION['yc_' . $flow] ?? [];
        if ($cart === []) return $this->redirect($response, $data['url'] . '/cart');
        $choices = $post ? ($input['shipping'] ?? []) : ($_SESSION['yc_shipping_' . $flow] ?? []);
        if (!is_array($choices)) throw DomainError::validation(['shipping' => '배송 방식을 확인해 주세요.']);
        $quote = $this->service->cart->quote($cart, $choices, true);
        $methods = $this->service->payments->methods();
        $_SESSION['yc_shipping_' . $flow] = $choices;
        if ($post && ($input['action'] ?? '') !== 'refresh') {
            try {
                $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
                (new \GnuCms\Spam\WriteRateLimiter($this->service->app->db(), ['yc_order' => [[600, 20]]]))
                    ->consume('yc_order', $this->service->app->guestAcl(), is_string($ip) ? $ip : null);
                $this->service->payments->expireOverdue();
                $payment = $this->service->payments->forPlacing($input);
                $order = $this->service->orders->place($cart, $input, $token, $_SESSION['yc_owner'], $userId, $issued['fingerprint'], $choices, $payment);
                if ($userId === null) $this->grantGuest((int) $order['id']);
                $_SESSION['yc_' . $flow] = [];
                $_SESSION['yc_just_ordered'] = $order['number'];
                $next = $payment !== [] && $this->service->payments->isPgOrder($order) ? '/pay' : '/order';
                return $this->redirect($response, $data['url'] . $next . '?number=' . rawurlencode($order['number']));
            } catch (DomainError $e) {
                if ($e->status() >= 500) throw $e;
                $data['errors'] = $e->details() ?: [$e->getMessage()]; $response = $response->withStatus($e->status());
                $quote = $this->service->cart->quote($cart, $choices, true);
            }
        } elseif ($post) $data['notice'] = '배송비를 반영했습니다. 주문 금액을 확인해 주세요.';
        $_SESSION['yc_checkout'][$token] = ['flow' => $flow, 'user_id' => $userId, 'fingerprint' => $quote['fingerprint']];
        $_SESSION['yc_checkout'] = array_slice($_SESSION['yc_checkout'], -12, null, true);
        $data += ['flow' => $flow, 'checkout_token' => $token, 'quote' => $quote, 'payment_methods' => $methods, 'payment' => $this->service->settings->all()['payment']];
        $data['errors'] += $quote['errors'];
        $data['input'] = $this->safeValues($input);
        // Prefill only when opening the form; preserve edits and intentionally empty values on POST.
        if (!$post && $userId !== null) {
            $user = $this->service->app->users()->findById($userId);
            if ($user !== null) {
                $data['input'] += ['buyer_name' => $user['display_name'], 'recipient' => $user['display_name'],
                    'email' => UserRepository::isSocialPlaceholderEmail($user['email']) ? '' : $user['email']];
            }
        }
        return $view->render($response, 'checkout', $data);
    }

    private function grantGuest(int $id): void
    {
        $_SESSION['yc_guest_orders'] = array_slice(array_values(array_unique([...$_SESSION['yc_guest_orders'], $id])), -30);
    }

    /** 비밀번호와 배열형 입력을 폼에 다시 출력하지 않는다. */
    private function safeValues(array $input): array
    {
        unset($input['password']);
        return array_filter($input, 'is_string');
    }

    private function page(mixed $value): int { return is_string($value) && preg_match('/^[1-9][0-9]{0,5}$/D', $value) ? (int) $value : 1; }
    private function redirect(ResponseInterface $response, string $url): ResponseInterface { return $response->withStatus(303)->withHeader('Location', $url); }
}
