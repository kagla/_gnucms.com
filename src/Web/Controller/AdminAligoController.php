<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Aligo\PhoneNumber;
use GnuCms\Aligo\TransportFailure;
use GnuCms\Error\DomainError;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * 알리고 설정 화면. 계정(사용자ID·API 키)·발신번호·발신프로필을 저장하고, 채널별
 * 발송 허용 여부를 켜고 끈다. API 키는 저장된 뒤로는 화면·로그·오류 어디에도
 * 다시 나타나지 않는다 — 저장 여부만 보여 준다(AdminCmsController::mail() 과 같은 규칙).
 */
final class AdminAligoController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function form(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $query = $request->getQueryParams();
        $notice = ($query['saved'] ?? '') === '1' ? '설정을 저장했습니다.' : null;

        return $this->render($request, $response, null, [], null, null, [], $notice);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $this->app->aligo()->settings->save($input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->render(
                $request, $response->withStatus(422), $this->reshow($input), $e->details(), null, null, []
            );
        }

        return $this->redirect($request, $response, 'admin.aligo', ['saved' => '1']);
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $verified = $this->app->aligo()->verify();
        } catch (DomainError $e) {
            return $this->render($request, $response->withStatus($e->status() === 422 ? 422 : 502),
                null, $e->details(), $e->getMessage(), null, []);
        }

        return $this->render($request, $response, null, [], null, $verified, []);
    }

    public function profiles(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $profiles = $this->app->aligo()->profiles();
        } catch (DomainError | TransportFailure $e) {
            return $this->render($request, $response->withStatus(502), null, [], $e->getMessage(), null, []);
        }

        return $this->render($request, $response, null, [], null, null, $profiles);
    }

    public function toggle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $this->app->aligo()->settings->setEnabled(
                (string) ($input['channel'] ?? ''), ($input['action'] ?? '') === 'enable'
            );
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->render($request, $response->withStatus(422), null, $e->details(), null, null, []);
        }

        return $this->redirect($request, $response, 'admin.aligo', ['saved' => '1']);
    }

    /**
     * 저장이 실패했을 때 다시 보여줄 값. 관리자가 방금 입력한 값을 그대로 돌려주되
     * API 키 두 칸만은 절대 담지 않는다 — 입력이 잘못됐다고 해서 화면이 방금 받은
     * 평문 키를 그대로 되비추면 안 된다. "저장됨" 표시는 실패 전 저장소 상태를 쓴다.
     */
    private function reshow(array $input): array
    {
        $current = $this->app->aligo()->settings->formValues();

        $values = $input;
        $values['api_key'] = '';
        $values['api_key_set'] = $current['api_key_set'];
        $values['alimtalk_api_key'] = '';
        $values['alimtalk_api_key_set'] = $current['alimtalk_api_key_set'];
        $values['test_mode'] = !empty($input['test_mode']);
        // 채널 허용 스위치는 이 폼에 없다. 저장이 실패해도 바뀌지 않았으므로 그대로 보여준다.
        $values['sms_enabled'] = $current['sms_enabled'];
        $values['alimtalk_enabled'] = $current['alimtalk_enabled'];

        return $values;
    }

    private function render(ServerRequestInterface $request, ResponseInterface $response,
        ?array $values, array $errors, ?string $error, ?array $verified, array $profiles,
        ?string $notice = null): ResponseInterface
    {
        $values ??= $this->app->aligo()->settings->formValues();
        // 발신번호는 언제나 하이픈 붙은 표시용 형태로 보여준다. PhoneNumber::format() 은
        // 숫자가 아닌 문자를 걸러내고 패턴에 안 맞으면 숫자만 그대로 돌려주므로, 저장된
        // 값이든 방금 입력해 실패한 값이든 이 자리 하나에서 안전하게 처리된다.
        $values['sender'] = PhoneNumber::format((string) ($values['sender'] ?? ''));

        return View::fromRequest($request)->render($response, 'admin/aligo_settings', [
            'values' => $values,
            'status' => $this->app->aligo()->status(),
            'errors' => $errors,
            'error' => $error,
            'verified' => $verified,
            'profiles' => $profiles,
            'notice' => $notice,
            'query' => $request->getQueryParams(),
        ]);
    }

    private function input(ServerRequestInterface $request): array
    {
        $input = $request->getParsedBody();

        return is_array($input) ? $input : [];
    }

    private function assertCsrf(array $input): void
    {
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        $given = isset($input['csrf_token']) && is_scalar($input['csrf_token']) ? (string) $input['csrf_token'] : '';
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw DomainError::forbidden('요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }
    }

    private function redirect(ServerRequestInterface $request, ResponseInterface $response, string $route,
        array $query = []): ResponseInterface
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($route);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $response->withHeader('Location', $url)->withStatus(303);
    }
}
