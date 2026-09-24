<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;
use GnuCms\View\View;
use GnuCms\Web\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/** 설정 → 결제. 전체 관리자 전용이며 POST는 세션 CSRF를 검사한다. */
final class SettingsController
{
    public function __construct(private Settings $settings)
    {
    }

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->settings->app->guestAcl()->assertGlobalAdmin();
        if ($request->getMethod() === 'POST') Csrf::assert($request);
        $input = $request->getMethod() === 'POST' ? $request->getParsedBody() : $request->getQueryParams();
        $input = is_array($input) ? $input : [];
        foreach ($input as $value) if (!is_string($value) && !is_int($value)) throw DomainError::validation(['input' => '단일 입력값을 사용해 주세요.']);
        $environment = is_string($input['environment'] ?? null) ? Settings::environment($input['environment']) : 'test';
        $settings = $this->settings->app->paymentSettings((string) ($input['provider'] ?? 'inicis'));
        $provider = $settings->definition();
        $notice = '';
        $completedAction = '';
        $errors = [];
        try {
            if ($request->getMethod() === 'POST') {
                $action = $input['action'] ?? '';
                if ($action === 'save') {
                    $settings->save($environment, $input);
                    $notice = '결제 설정을 저장했습니다. 선택한 환경과 결제 수단에 적용됩니다.';
                    $completedAction = 'payment_saved';
                } else {
                    throw DomainError::validation(['action' => '작업을 확인해 주세요.']);
                }
            }
        } catch (DomainError $e) {
            $response = $response->withStatus($e->status());
            $errors = $e->status() >= 500 ? ['설정 저장에 실패했습니다. 암호화 키와 저장소 상태를 확인해 주세요.'] : ($e->details() ?: [$e->getMessage()]);
        }
        if ($completedAction !== '' && ($input['return_to'] ?? '') === 'shop' && $provider->id() === 'inicis') {
            $selectedEnvironment = $input['payment_environment'] ?? null;
            if (is_string($selectedEnvironment) && in_array($selectedEnvironment, ['live', 'test'], true)) {
                $this->settings->app->shop()->settings->setPaymentEnvironment($selectedEnvironment);
            }
            $url = RouteContext::fromRequest($request)->getBasePath() . '/admin/shop/settings?' . $completedAction . '=1#settings-payment';
            return $response->withStatus(303)->withHeader('Location', $url)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
        }
        return View::fromRequest($request)->render(
            $response->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer'),
            'admin/payment_settings',
            ['provider' => $provider->id(), 'providers' => $settings->app->paymentProviders()->labels(), 'fields' => $provider->fields(), 'manual' => $provider->manual(),
                'label' => $provider->label(), 'environment' => $environment,
                'settings' => $settings->summary($environment), 'notice' => $notice, 'errors' => $errors]
        );
    }

    /** 저장된 PG 비밀키는 관리자가 명시적으로 요청할 때만 전송한다. */
    public function secret(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->settings->app->guestAcl()->assertGlobalAdmin();
        Csrf::assert($request);
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $environment = is_string($input['environment'] ?? null) ? $input['environment'] : '';
        $field = is_string($input['field'] ?? null) ? $input['field'] : '';
        $secret = $this->settings->secret($environment, $field);
        $response->getBody()->write((string) json_encode(
            ['secret' => $secret],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
