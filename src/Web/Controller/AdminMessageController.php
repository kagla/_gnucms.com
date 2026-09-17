<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * 운영 › 알림톡·문자 화면. 3단계(발송·템플릿·이력)의 시작이며, 이 파일은 그중
 * 템플릿 탭만 담당한다. 여기서 템플릿을 만들거나 고치지 않는다 — 카카오가 승인한
 * 템플릿의 사본을 가져오고, 그중 승인·정상 상태인 것만 이 사이트에서 쓰도록 켠다.
 */
final class AdminMessageController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function templates(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $query = $request->getQueryParams();
        $notice = is_string($query['notice'] ?? null) ? $query['notice'] : null;

        return $this->render($request, $response, null, $notice);
    }

    public function fetchTemplates(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $counts = $this->app->aligo()->templates->fetch();
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->render($request, $response->withStatus(422), $this->firstError($e));
        }

        $notice = sprintf(
            '가져오기 %d건, 갱신 %d건, 사용 중지 %d건', $counts['imported'], $counts['updated'], $counts['disabled']
        );

        return $this->redirect($request, $response, 'admin.messages.templates', ['notice' => $notice]);
    }

    public function toggleTemplate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $this->app->aligo()->templates->setEnabled(
                (string) ($input['tpl_code'] ?? ''), ($input['action'] ?? '') === 'enable'
            );
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            return $this->render($request, $response->withStatus(422), $this->firstError($e));
        }

        return $this->redirect($request, $response, 'admin.messages.templates');
    }

    /** 필드 오류 배열에서 화면 상단 알림에 쓸 문장 하나를 고른다. */
    private function firstError(DomainError $e): string
    {
        $details = $e->details();

        return $details === [] ? $e->getMessage() : (string) reset($details);
    }

    private function render(ServerRequestInterface $request, ResponseInterface $response,
        ?string $error, ?string $notice = null): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'admin/message/templates', [
            'copies' => $this->app->aligo()->templates->all(),
            'error' => $error,
            'notice' => $notice,
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
