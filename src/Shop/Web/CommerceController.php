<?php

declare(strict_types=1);

namespace GnuCms\Shop\Web;

use GnuCms\Account\UserRepository;
use GnuCms\Error\DomainError;
use GnuCms\Shop\Commerce\Orders;
use GnuCms\Shop\Images;
use GnuCms\Shop\Input;
use GnuCms\Shop\Service;
use GnuCms\Shop\Settings;
use GnuCms\View\View;
use GnuCms\Web\LoginRedirect;
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
        $view = View::forShop($request);
        $response = $response->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', in_array($page, ['orders', 'order', 'order/cancel'], true) ? 'no-referrer' : 'same-origin');
        $data = ['url' => $url, 'base' => $base, 'admin_url' => $base . ($this->adminRoutePrefix ?? '/admin/shop'), 'admin' => $identity->isAdmin(),
            'page' => $page, 'user_id' => $userId, 'input' => [], 'errors' => [], 'notice' => '', 'type_labels' => Settings::TYPE_LABELS,
            'statuses' => Orders::STATUSES, 'order_ref' => static fn (array $order): string => Orders::reference($order), 'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'img' => static fn (int $id, ?string $file, string $size): ?string => $file === null || $file === '' ? null : Images::url($url, $id, $file, $size)];
        $data['settings'] = $this->service->settings->all();
        // 영수증(order)은 예외다. 공개를 끄기 전에 받은 주문과 진행 중인 결제가 돌아올 곳이다.
        if (!$data['settings']['visible'] && $page !== 'order') return $view->render($response, 'closed', $data);
        if ($userId === null && in_array($page, ['checkout', 'checkout/previous-addresses', 'orders', 'order', 'order/cancel'], true)) {
            $destination = $url . match ($page) {
                'checkout', 'checkout/previous-addresses' => '/checkout' . (($input['flow'] ?? '') === 'buy' ? '?flow=buy' : ''),
                'orders' => '/orders',
                default => '/order' . (is_string($input['ref'] ?? null) ? '?ref=' . rawurlencode($input['ref'])
                    : (is_string($input['number'] ?? null) ? '?number=' . rawurlencode($input['number']) : '')),
            };
            return $this->redirect($response, LoginRedirect::loginUrl(RouteContext::fromRequest($request)->getRouteParser(), $destination));
        }
        if ($page === 'checkout/previous-addresses') {
            if ($userId === null) throw DomainError::forbidden('로그인이 필요합니다.');
            $search = Input::text($input['q'] ?? '', 'q', 100);
            $pageNumber = $this->page($input['page'] ?? '1');
            return $this->json($response, $this->service->orders->previousAddressesPageFor($userId, $search, $pageNumber));
        }
        $data['menu'] = $this->service->categories->children(null, true, true);
        $_SESSION['yc_cart'] ??= [];
        $_SESSION['yc_owner'] ??= bin2hex(random_bytes(32));
        $data['cart_count'] = $this->service->cart->productCount($_SESSION['yc_cart']);
        if ($page === 'cart/add') {
            $json = stripos($request->getHeaderLine('Accept'), 'application/json') !== false;
            $buy = ($input['action'] ?? '') === 'buy';
            try {
                $cart = $this->service->cart->add($buy ? [] : $_SESSION['yc_cart'], $input);
                $_SESSION[$buy ? 'yc_buy' : 'yc_cart'] = $cart;
                if ($buy) unset($_SESSION['yc_buy_source']);
                $next = $url . ($buy ? '/checkout?flow=buy' : '/cart?added=1');
                return $json ? $this->json($response, ['redirect' => $next]) : $this->redirect($response, $next);
            } catch (DomainError $e) {
                if ($e->status() >= 500) throw $e;
                if ($json) {
                    $availability = null;
                    if ($e->status() === 422 && ($id = Input::filterId($input['product_id'] ?? null)) !== null) {
                        try { $availability = $this->service->cart->availability($id, $buy ? [] : $_SESSION['yc_cart']); }
                        catch (DomainError $missing) { if ($missing->status() !== 404) throw $missing; }
                    }
                    return $this->json($response->withStatus($e->status()), [
                        'error' => ['message' => $e->getMessage(), 'details' => $e->details()], 'availability' => $availability,
                    ]);
                }
                $data['errors'] = $e->details() ?: [$e->getMessage()];
                $response = $response->withStatus($e->status());
                $page = $data['page'] = 'cart';
            }
        }
        if ($page === 'cart') {
            if ($request->getMethod() === 'POST' && $data['errors'] === []) {
                try {
                    $action = (string) ($input['cart_action'] ?? 'update');
                    if (in_array($action, ['preview_selection', 'update_quantities'], true)) {
                        $quantities = $input['quantities'] ?? [];
                        if (!is_array($quantities)) throw DomainError::validation(['quantity' => '수량을 확인해 주세요.']);
                        $previewCart = $this->service->cart->update($_SESSION['yc_cart'], $quantities);
                        if ($action === 'update_quantities') $_SESSION['yc_cart'] = $previewCart;
                        $rawSelected = $input['selected_products'] ?? [];
                        if (!is_array($rawSelected)) throw DomainError::validation(['selection' => '상품 선택을 확인해 주세요.']);
                        $selected = array_fill_keys(array_map(static fn ($id): int => Input::id($id), $rawSelected), true);
                        $selectedCart = array_filter($previewCart, static fn ($line): bool => isset($selected[(int) $line['product_id']]));
                        $preview = $this->service->cart->quote($selectedCart, [], true);
                        $allItems = $this->service->cart->quote($previewCart)['items'];
                        $lines = array_map(static fn (array $item): array => ['key' => $item['key'], 'total' => $item['total'],
                            'available' => $item['available'], 'error' => $item['error']], $allItems);
                        return $this->json($response, ['subtotal' => $preview['subtotal'], 'shipping_fee' => $preview['shipping_fee'],
                            'cod_fee' => $preview['cod_fee'], 'total' => $preview['total'], 'valid' => $selectedCart !== [] && $preview['errors'] === [],
                            'lines' => $lines, 'saved' => $action === 'update_quantities']);
                    }
                    if ($action === 'remove_line') {
                        $quantities = $input['quantities'] ?? [];
                        if (!is_array($quantities)) throw DomainError::validation(['quantity' => '수량을 확인해 주세요.']);
                        $remove = Input::text($input['remove'] ?? '', 'remove', 40, false);
                        $quantities[$remove] = 0;
                    } elseif (isset($input['remove'])) {
                        $remove = Input::text($input['remove'], 'remove', 40, false);
                        $quantities = [$remove => 0];
                    } else {
                        $quantities = $input['quantities'] ?? [];
                        if (!is_array($quantities)) throw DomainError::validation(['quantity' => '수량을 확인해 주세요.']);
                    }
                    $_SESSION['yc_cart'] = $this->service->cart->update($_SESSION['yc_cart'], $quantities);
                    if (!in_array($action, ['update', 'remove_line', 'delete_selected', 'checkout_selected'], true)) {
                        throw DomainError::validation(['cart_action' => '장바구니 작업을 확인해 주세요.']);
                    }
                    if (in_array($action, ['delete_selected', 'checkout_selected'], true)) {
                        $rawSelected = $input['selected_products'] ?? [];
                        if (!is_array($rawSelected)) throw DomainError::validation(['selection' => '상품 선택을 확인해 주세요.']);
                        $selected = array_values(array_unique(array_map(static fn ($id): int => Input::id($id), $rawSelected)));
                        if ($selected === []) throw DomainError::validation(['selection' => '상품을 하나 이상 선택해 주세요.']);
                        $selectedSet = array_fill_keys($selected, true);
                        if ($action === 'delete_selected') {
                            $remove = [];
                            foreach ($_SESSION['yc_cart'] as $key => $line) if (isset($selectedSet[(int) $line['product_id']])) $remove[$key] = 0;
                            $_SESSION['yc_cart'] = $this->service->cart->update($_SESSION['yc_cart'], $remove);
                        } else {
                            $selectedCart = array_filter($_SESSION['yc_cart'], static fn ($line): bool => isset($selectedSet[(int) $line['product_id']]));
                            if ($selectedCart === []) throw DomainError::validation(['selection' => '선택한 상품이 장바구니에 없습니다.']);
                            $selectedQuote = $this->service->cart->quote($selectedCart, [], true);
                            if ($selectedQuote['errors'] !== []) throw DomainError::validation($selectedQuote['errors']);
                            $_SESSION['yc_buy'] = $selectedCart;
                            $_SESSION['yc_buy_source'] = 'cart';
                            return $this->redirect($response, $url . '/checkout?flow=buy');
                        }
                    }
                    if (in_array($action, ['remove_line', 'delete_selected'], true)
                        && stripos($request->getHeaderLine('Accept'), 'application/json') !== false) {
                        $rawSelected = $input['selected_products'] ?? [];
                        if (!is_array($rawSelected)) $rawSelected = [];
                        $selected = array_fill_keys(array_map(static fn ($id): int => Input::id($id), $rawSelected), true);
                        $selectedCart = array_filter($_SESSION['yc_cart'], static fn ($line): bool => isset($selected[(int) $line['product_id']]));
                        $preview = $this->service->cart->quote($selectedCart, [], true);
                        $cartQuote = $this->service->cart->quote($_SESSION['yc_cart']);
                        $cartGroups = \GnuCms\Shop\Commerce\ItemGroups::build($cartQuote['items']);
                        return $this->json($response, ['subtotal' => $preview['subtotal'], 'shipping_fee' => $preview['shipping_fee'],
                            'cod_fee' => $preview['cod_fee'], 'total' => $preview['total'], 'valid' => $preview['errors'] === [],
                            'cart_count' => $this->service->cart->productCount($_SESSION['yc_cart']),
                            'product_count' => count($cartGroups['groups']), 'selection_count' => $cartGroups['count']]);
                    }
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
            $data['list'] = $this->service->orders->listing($userId, '', $this->page($input['page'] ?? '1'));
            return $view->render($response, 'orders', $data);
        }
        if ($page === 'order' || $page === 'order/cancel') {
            if ($page === 'order' && isset($input['ref'])) {
                $reference = Input::text($input['ref'], 'ref', 65, false);
                $order = $this->service->orders->ownedReference($reference, $userId);
                $canonicalReference = Orders::reference($order);
                if (!hash_equals($canonicalReference, $reference)) {
                    $pay = in_array($input['pay'] ?? '', ['closed', 'failed'], true) ? '&pay=' . $input['pay'] : '';
                    return $this->redirect($response, $url . '/order?ref=' . rawurlencode($canonicalReference) . $pay);
                }
            } else {
                $number = Input::text($input['number'] ?? '', 'number', 32, false);
                $order = $this->service->orders->owned($number, $userId);
                if ($page === 'order') {
                    $pay = in_array($input['pay'] ?? '', ['closed', 'failed'], true) ? '&pay=' . $input['pay'] : '';
                    return $this->redirect($response, $url . '/order?ref=' . rawurlencode(Orders::reference($order)) . $pay);
                }
            }
            $number = $order['number'];
            $virtualAccountIssued = $order['payment_method'] === 'virtual_account' && isset($order['payment']['virtual_account']);
            if ($page === 'order' && ($order['status'] !== 'pending' || $virtualAccountIssued) && $this->service->payments->isPgOrder($order)) {
                $intent = $this->service->checkoutIntents->find($order['payment_id'], $order['payment_provider']);
                $token = $intent['token'] ?? '';
                if ($intent !== null && ($intent['owner'] ?? '') === $_SESSION['yc_owner']
                    && (int) ($intent['user_id'] ?? 0) === $userId && $token === $order['checkout_key']
                    && isset($_SESSION['yc_checkout'][$token])) {
                    $flow = $intent['flow'];
                    if ($flow === 'cart' || ($_SESSION['yc_buy_source'] ?? null) === 'cart') {
                        foreach ($intent['lines'] as $key => $line) {
                            if (!isset($_SESSION['yc_cart'][$key])) continue;
                            $left = (int) $_SESSION['yc_cart'][$key]['quantity'] - (int) $line['quantity'];
                            if ($left > 0) $_SESSION['yc_cart'][$key]['quantity'] = $left;
                            else unset($_SESSION['yc_cart'][$key]);
                        }
                    }
                    if ($flow === 'buy') $_SESSION['yc_buy'] = [];
                    unset($_SESSION['yc_buy_source'], $_SESSION['yc_checkout'][$token]);
                    $data['cart_count'] = $this->service->cart->productCount($_SESSION['yc_cart']);
                }
            }
            if ($page === 'order/cancel') {
                try {
                    // 승인이 오가는 중인 주문은 돈이 움직였을 수 있다. 결과가 확정된 뒤에 취소한다.
                    if ($this->service->payments->inProgress($order)) {
                        throw DomainError::validation(['status' => '결제 결과를 확인하는 중입니다. 잠시 후 다시 시도하거나 상점에 문의해 주세요.']);
                    }
                    $this->service->orders->transition((int) $order['id'], $order['status'], 'cancelled', 'user:' . $userId,
                        $input, true);
                    return $this->redirect($response, $url . '/order?ref=' . rawurlencode(Orders::reference($order)));
                } catch (DomainError $e) { $data['errors'] = $e->details() ?: [$e->getMessage()]; $response = $response->withStatus($e->status()); }
                $order = $this->service->orders->owned($number, $userId);
            }
            $data['order'] = $order;
            $data['cancel_attempted'] = $page === 'order/cancel';
            if ($data['cancel_attempted']) $data['input'] = $this->safeValues($input);
            $data['cancel_reasons'] = \GnuCms\Shop\Commerce\CancellationReason::OPTIONS;
            $payState = $input['pay'] ?? '';
            $data['pay_state'] = in_array($payState, ['closed', 'failed'], true) ? $payState : '';
            $pgPending = $order['status'] === 'pending' && $this->service->payments->isPgOrder($order);
            $overdue = (int) $order['pay_by'] > 0 && (int) $order['pay_by'] <= \GnuCms\Support\Clock::timestamp();
            $data['pay_url'] = $pgPending && !$overdue && !$virtualAccountIssued ? $url . '/pay?ref=' . rawurlencode(Orders::reference($order)) : null;
            $data['pay_expired'] = $pgPending && $overdue;
            // 원장을 읽지 못하면(표 없음·키 교체) 진행 중 표시만 포기한다. 주문 상세 자체는 열려야 한다.
            try { $data['pay_in_progress'] = $this->service->payments->inProgress($order); }
            catch (\Throwable) { $data['pay_in_progress'] = false; }
            if ($virtualAccountIssued && $order['status'] === 'pending') $data['pay_in_progress'] = false;
            $data['method_labels'] = \GnuCms\Shop\Commerce\Payments::METHODS;
            return $view->render($response, 'order', $data);
        }
        throw DomainError::notFound('페이지를 찾을 수 없습니다.');
    }

    private function checkout(ServerRequestInterface $request, ResponseInterface $response, \GnuCms\View\PhpView $view, array $data, array $input, int $userId): ResponseInterface
    {
        $post = $request->getMethod() === 'POST';
        $flow = ($input['flow'] ?? '') === 'buy' ? 'buy' : 'cart';
        $_SESSION['yc_checkout'] ??= [];
        $token = $post ? Input::text($input['checkout_token'] ?? '', 'checkout_token', 64) : bin2hex(random_bytes(32));
        if (!$post) {
            foreach (array_reverse($_SESSION['yc_checkout'], true) as $oldToken => $old) {
                if (($old['flow'] ?? '') !== $flow || ($old['user_id'] ?? null) !== $userId || !is_string($old['payment_id'] ?? null)) continue;
                if ($this->service->orders->submitted($oldToken, $_SESSION['yc_owner'], $userId) !== null) continue;
                $token = $oldToken;
                break;
            }
        }
        if ($post) {
            $issued = $_SESSION['yc_checkout'][$token] ?? null;
            if ($issued === null || $issued['flow'] !== $flow || $issued['user_id'] !== $userId) throw DomainError::forbidden('주문서를 다시 열어 주세요.');
            if ($existing = $this->service->orders->submitted($token, $_SESSION['yc_owner'], $userId)) {
                return $this->redirect($response, $data['url'] . '/order?ref=' . rawurlencode(Orders::reference($existing)));
            }
        }
        $cart = $_SESSION['yc_' . $flow] ?? [];
        if ($cart === []) return $this->redirect($response, $data['url'] . '/cart');
        $member = $this->service->app->users()->findById($userId);
        if ($member === null) throw DomainError::forbidden('회원정보를 확인할 수 없습니다. 다시 로그인해 주세요.');
        // 주문자 정보는 주문서에서 수정할 수 있으며 회원 프로필은 바꾸지 않는다.
        if (!$post) {
            $input['buyer_name'] = (string) $member['display_name'];
            $input['phone'] = (string) ($member['phone'] ?? '');
            $input['email'] = UserRepository::isSocialPlaceholderEmail((string) $member['email']) ? '' : (string) $member['email'];
        }
        $choices = $post ? ($input['shipping'] ?? []) : ($_SESSION['yc_shipping_' . $flow] ?? []);
        if (!is_array($choices)) throw DomainError::validation(['shipping' => '배송 방식을 확인해 주세요.']);
        $quote = $this->service->cart->quote($cart, $choices, true);
        $methods = $this->service->payments->methods();
        if (!$post && ($input['pay'] ?? '') === 'closed') $data['errors']['payment'] = '결제창이 닫혔지만 결제 결과를 확인하지 못했습니다. 다시 결제하지 말고 결제 내역을 확인하거나 상점에 문의해 주세요.';
        if (!$post && ($input['pay'] ?? '') === 'failed') {
            $code = $input['code'] ?? '';
            $reference = $input['reference'] ?? '';
            $data['errors']['payment'] = '결제 결과를 확인하지 못했습니다. 중복 결제를 피하려면 다시 결제하지 말고 결제 내역을 확인하거나 상점에 문의해 주세요.'
                . (is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D', $code) ? ' 오류 코드: ' . $code . '.' : '')
                . (is_string($reference) && preg_match('/^[a-f0-9]{32}$/D', $reference) ? ' 결제 참조번호: ' . $reference . '.' : '');
        }
        if (!$post && ($input['pay'] ?? '') === 'declined') {
            $code = $input['code'] ?? '';
            $message = '';
            $declinedMethod = '';
            $reference = $input['reference'] ?? '';
            $sessionPaymentId = $_SESSION['yc_checkout'][$token]['payment_id'] ?? '';
            $paymentId = is_string($reference) && preg_match('/^[a-f0-9]{32}$/D', $reference)
                && is_string($sessionPaymentId) && hash_equals($sessionPaymentId, $reference)
                    ? $reference
                    : (is_string($sessionPaymentId) && preg_match('/^[a-f0-9]{32}$/D', $sessionPaymentId) ? $sessionPaymentId : '');
            if ($paymentId !== '') {
                $declined = $this->service->checkoutIntents->find($paymentId);
                if (($declined['status'] ?? '') === 'declined') {
                    $message = (string) ($declined['failure_message'] ?? '');
                    $declinedMethod = (string) ($declined['payment']['method'] ?? '');
                }
            }
            $guidance = match ($declinedMethod) {
                'bank_transfer' => '실시간 계좌이체가 거절된 경우 계좌를 개설한 금융기관에 문의하거나 다른 결제수단을 선택해 주세요.',
                'mobile' => '휴대폰 결제 한도와 가입 상태를 확인하거나 다른 결제수단을 선택해 주세요.',
                'virtual_account' => '가상계좌 발급이 거절되었습니다. 다른 결제수단을 선택해 주세요.',
                default => '결제 정보를 확인하거나 다른 결제수단을 선택해 주세요.',
            };
            $data['errors']['payment'] = '결제사가 결제를 거절했습니다. ' . $guidance
                . ($message !== '' ? ' ' . $message : '')
                . (is_string($code) && preg_match('/^[A-Za-z0-9_-]{1,16}$/D', $code) ? ' 응답 코드: ' . $code . '.' : '');
        }
        if (!$post && ($input['pay'] ?? '') === 'refunded') $data['errors']['payment'] = '결제 후 주문 조건이 변경되어 접수하지 못했습니다. 승인 금액의 전액 취소를 요청했습니다.';
        if (!$post && ($input['pay'] ?? '') === 'review') {
            $reference = $input['reference'] ?? '';
            $data['errors']['payment'] = '결제 결과 또는 주문 접수·취소 결과를 확인하지 못했습니다. 다시 결제하지 말고 상점에 문의해 주세요.'
                . (is_string($reference) && preg_match('/^[a-f0-9]{32}$/D', $reference) ? ' 결제 참조번호: ' . $reference : '');
        }
        $_SESSION['yc_shipping_' . $flow] = $choices;
        if ($post && ($input['action'] ?? '') !== 'refresh') {
            try {
                $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
                (new \GnuCms\Spam\WriteRateLimiter($this->service->app->db(), ['yc_order' => [[600, 20]]]))
                    ->consume('yc_order', $this->service->app->guestAcl(), is_string($ip) ? $ip : null);
                $this->service->payments->expireOverdue();
                $payment = $this->service->payments->forPlacing($input);
                if (in_array(($payment['method'] ?? ''), Orders::PG_METHODS, true)) {
                    $intent = $this->service->checkoutIntents->stage($cart, $input, $token, $_SESSION['yc_owner'], $userId,
                        $issued['fingerprint'], $choices, $payment, $flow, $issued['payment_id'] ?? null);
                    $site = rtrim((string) $this->service->app->config('app.url', GNUCMS_URL), '/');
                    $data['checkout_payment'] = $this->service->checkoutIntents->checkout($intent,
                        PayController::device($request->getHeaderLine('User-Agent')),
                        $site . $this->routePrefix . '/checkout?flow=' . $flow . '&pay=closed',
                        $site . $this->routePrefix . '/pay/callback?provider=' . rawurlencode($intent['payment']['provider']));
                    $data['checkout_template'] = $this->service->app->paymentProviders()->get($intent['payment']['provider'])->checkoutTemplate();
                    $issued['payment_id'] = $intent['payment']['id'];
                    $data['notice'] = '결제창을 열고 있습니다. 결제가 완료되면 주문이 접수됩니다.';
                    $response = $response->withHeader('Referrer-Policy', 'no-referrer');
                } else {
                    $order = $this->service->orders->place($cart, $input, $token, $_SESSION['yc_owner'], $userId, $issued['fingerprint'], $choices, $payment);
                    if ($flow === 'buy' && ($_SESSION['yc_buy_source'] ?? null) === 'cart') {
                        foreach ($cart as $key => $line) {
                            if (!isset($_SESSION['yc_cart'][$key])) continue;
                            $remaining = (int) $_SESSION['yc_cart'][$key]['quantity'] - (int) $line['quantity'];
                            if ($remaining > 0) $_SESSION['yc_cart'][$key]['quantity'] = $remaining;
                            else unset($_SESSION['yc_cart'][$key]);
                        }
                    }
                    $_SESSION['yc_' . $flow] = [];
                    unset($_SESSION['yc_buy_source']);
                    return $this->redirect($response, $data['url'] . '/order?ref=' . rawurlencode(Orders::reference($order)));
                }
            } catch (DomainError $e) {
                if ($e->status() >= 500) throw $e;
                $data['errors'] = $e->details() ?: [$e->getMessage()]; $response = $response->withStatus($e->status());
                $quote = $this->service->cart->quote($cart, $choices, true);
            }
        } elseif ($post) $data['notice'] = '배송비를 반영했습니다. 주문 금액을 확인해 주세요.';
        $_SESSION['yc_checkout'][$token] = ['flow' => $flow, 'user_id' => $userId, 'fingerprint' => $quote['fingerprint']]
            + ($issued ?? []);
        $_SESSION['yc_checkout'] = array_slice($_SESSION['yc_checkout'], -12, null, true);
        $data += ['flow' => $flow, 'checkout_token' => $token, 'quote' => $quote, 'payment_methods' => $methods, 'payment' => $this->service->settings->all()['payment']];
        $data['errors'] += $quote['errors'];
        $data['input'] = $this->safeValues($input);
        $data['profile_buyer_name'] = (string) $member['display_name'];
        $data['profile_phone'] = (string) ($member['phone'] ?? '');
        $data['profile_email'] = UserRepository::isSocialPlaceholderEmail((string) $member['email']) ? '' : (string) $member['email'];
        $data['has_previous_addresses'] = $this->service->orders->hasPreviousAddressesFor($userId);
        if (!$post) {
            $address = $this->service->orders->defaultAddressFor($userId);
            if ($address !== null) {
                // 기본 배송지(없으면 최근 주문)에 저장된 주문자 정보도 다음 주문서 기본값으로 쓴다.
                $data['input']['buyer_name'] = (string) $address['buyer_name'];
                $data['input']['phone'] = (string) $address['phone'];
                $data['input']['email'] = (string) $address['email'];
            }
            $data['input'] += $address ?? ['recipient' => $member['display_name'], 'recipient_phone' => (string) ($member['phone'] ?? '')];
        }
        return $view->render($response, 'checkout', $data);
    }

    private function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /** 민감 정보와 배열형 입력을 폼에 다시 출력하지 않는다. */
    private function safeValues(array $input): array
    {
        unset($input['password']);
        return array_filter($input, 'is_string');
    }

    private function page(mixed $value): int { return is_string($value) && preg_match('/^[1-9][0-9]{0,5}$/D', $value) ? (int) $value : 1; }
    private function redirect(ResponseInterface $response, string $url): ResponseInterface { return $response->withStatus(303)->withHeader('Location', $url); }
}
