<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

final class AdminCmsController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function settingsForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        return Twig::fromRequest($request)->render($response, 'admin/settings.html.twig', [
            'values' => $this->app->cmsService()->settings(), 'errors' => [],
            'query' => $request->getQueryParams(),
        ]);
    }

    public function settings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        try {
            $this->app->cmsService()->saveSettings($this->app->guestAcl(), $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return Twig::fromRequest($request)->render($response->withStatus(422), 'admin/settings.html.twig', [
                'values' => $input, 'errors' => $e->details(), 'query' => [],
            ]);
        }
        return $this->redirect($request, $response, 'admin.settings', ['saved' => '1']);
    }

    public function mailForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderMailForm($request, $response,
            $this->app->mailSettingsService()->formValues($this->app->guestAcl()), [], null);
    }

    public function mail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        try {
            $this->app->mailSettingsService()->save($this->app->guestAcl(), $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            $current = $this->app->mailSettingsService()->formValues($this->app->guestAcl());
            $input['password'] = '';
            $input['password_set'] = $current['password_set'];
            return $this->renderMailForm($request, $response->withStatus(422), $input, $e->details(), null);
        }
        return $this->redirect($request, $response, 'admin.mail', ['saved' => '1']);
    }

    public function mailTest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->guestAcl()->assertGlobalAdmin();
        try {
            $this->app->sendMailTest();
        } catch (DomainError $e) {
            return $this->renderMailForm(
                $request,
                $response->withStatus($e->status() === 422 ? 422 : 502),
                $this->app->mailSettingsService()->formValues($this->app->guestAcl()),
                $e->details(),
                $e->getMessage()
            );
        }
        return $this->redirect($request, $response, 'admin.mail', ['tested' => '1']);
    }

    private function renderMailForm(ServerRequestInterface $request, ResponseInterface $response,
        array $values, array $errors, ?string $testError): ResponseInterface
    {
        return Twig::fromRequest($request)->render($response, 'admin/mail.html.twig', [
            'values' => $values, 'errors' => $errors, 'query' => $request->getQueryParams(),
            'test_error' => $testError,
        ]);
    }

    public function pages(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $trash = $this->app->cmsService()->trash($this->app->guestAcl());
        return Twig::fromRequest($request)->render($response, 'admin/pages.html.twig', [
            'pages' => $this->app->cmsService()->contents($this->app->guestAcl()),
            'trash_count' => count($trash),
            'query' => $request->getQueryParams(),
        ]);
    }

    public function trash(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Twig::fromRequest($request)->render($response, 'admin/trash.html.twig', [
            'pages' => $this->app->cmsService()->trash($this->app->guestAcl()),
            'query' => $request->getQueryParams(),
        ]);
    }

    public function restore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->cmsService()->restorePage($this->app->guestAcl(), (int) $args['id']);
        return $this->redirect($request, $response, 'admin.content.trash', ['restored' => '1']);
    }

    public function permanentlyDelete(ServerRequestInterface $request, ResponseInterface $response,
        array $args): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->cmsService()->permanentlyDeletePage($this->app->guestAcl(), (int) $args['id']);
        return $this->redirect($request, $response, 'admin.content.trash', ['deleted' => '1']);
    }

    /** 약관 관리. 약관 전부와 자리별 붙임을 보여 준다. */
    public function legal(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        return Twig::fromRequest($request)->render($response, 'admin/legal.html.twig', [
            'pages' => $this->app->cmsService()->consentPages($acl),
            'scope' => 'signup',
            'saved' => ($request->getQueryParams()['saved'] ?? '') === '1',
        ]);
    }

    /** 한 자리의 붙임을 통째로 다시 쓴다. 체크가 풀린 약관은 떼어 낸다. */
    public function consentUses(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $acl = $this->app->guestAcl();
        $scope = isset($input['scope']) && is_string($input['scope']) && $input['scope'] !== ''
            ? $input['scope'] : 'signup';
        // 자리 이름은 VARCHAR(40) 이다. 넘치거나 엉뚱한 글자가 오면 MySQL 이 500 을 낸다.
        if (preg_match('/^[a-z][a-z0-9:_-]{0,39}$/D', $scope) !== 1) {
            $scope = 'signup';
        }
        $use = is_array($input['use'] ?? null) ? $input['use'] : [];
        $required = is_array($input['required'] ?? null) ? $input['required'] : [];
        $order = is_array($input['sort_order'] ?? null) ? $input['sort_order'] : [];

        $uses = $this->app->consentUses();
        foreach ($this->app->cmsService()->consentPages($acl) as $page) {
            $id = (int) $page['id'];
            if (empty($use[$id])) {
                $uses->detach($scope, $id);
                continue;
            }
            $uses->attach($scope, $id, !empty($required[$id]), (int) ($order[$id] ?? 0));
        }

        return $this->redirect($request, $response, 'admin.terms', ['saved' => '1']);
    }

    /** 한 약관에 누가 동의했고 누가 안 했는지. */
    public function consents(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $page = $this->app->cmsService()->page($acl, (int) $args['id']);

        return Twig::fromRequest($request)->render($response, 'admin/consents.html.twig', [
            'page' => $page,
            'rows' => $this->app->consents()->forContent((int) $page['id']),
            'counts' => $this->app->consents()->countsForContent((int) $page['id']),
        ]);
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        return $this->renderPageForm($request, $response, $this->withImageKey($this->defaults()), [], true);
    }

    public function legalSetup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->cmsService()->ensureLegalDrafts($this->app->guestAcl());
        return $this->redirect($request, $response, 'admin.terms', ['created' => '1']);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $acl = $this->app->guestAcl();
        try {
            $id = $this->app->cmsService()->createPage($acl, $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->renderPageForm($request, $response->withStatus(422),
                $this->withImageKey($input), $e->details(), true);
        }
        // 방금 만든 값 그대로가 아니라 저장된 행을 다시 읽어 약관 여부를 판단한다.
        $legal = $this->isLegal($this->app->cmsService()->page($acl, $id));
        return $this->redirect($request, $response, $legal ? 'admin.terms' : 'admin.content', ['saved' => '1']);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $page = $this->app->cmsService()->page($this->app->guestAcl(), (int) $args['id']);
        return $this->renderPageForm($request, $response, $this->withImageKey($page), [], false, (int) $args['id'],
            $this->isLegal($page));
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $id = (int) $args['id'];
        $acl = $this->app->guestAcl();
        $page = $this->app->cmsService()->page($acl, $id);
        $legal = $this->isLegal($page);
        try {
            $this->app->cmsService()->updatePage($acl, $id, $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            // 저장이 엎어졌으니 폼은 고치기 전 표시를 그대로 다시 그린다.
            return $this->renderPageForm($request, $response->withStatus(422),
                $this->withImageKey($input), $e->details(), false, $id, $legal);
        }
        // 이번 저장에서 약관 표시가 켜지거나 꺼졌을 수 있으므로, 저장된 행을 다시 읽어
        // 돌아갈 곳을 정한다. 고치기 전 값으로 정하면 엉뚱한 목록으로 보낸다.
        $legal = $this->isLegal($this->app->cmsService()->page($acl, $id));
        return $this->redirect($request, $response, $legal ? 'admin.terms' : 'admin.content', ['saved' => '1']);
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $page = $this->app->cmsService()->page($this->app->guestAcl(), (int) $args['id']);
        return Twig::fromRequest($request)->render($response, 'pages/show.html.twig', [
            'page' => $page,
            'preview' => true,
        ]);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $id = (int) $args['id'];
        $page = $this->app->cmsService()->page($this->app->guestAcl(), $id);
        $legal = $this->isLegal($page);
        $this->app->cmsService()->deletePage($this->app->guestAcl(), $id);
        return $this->redirect($request, $response, $legal ? 'admin.terms' : 'admin.content', ['deleted' => '1']);
    }

    private function renderPageForm(ServerRequestInterface $request, ResponseInterface $response, array $values,
        array $errors, bool $create, int $id = 0, bool $legal = false): ResponseInterface
    {
        return Twig::fromRequest($request)->render($response, 'admin/page_form.html.twig', [
            'values' => $values, 'errors' => $errors, 'create' => $create, 'page_id' => $id, 'legal' => $legal,
        ]);
    }

    /** 약관 여부는 슬러그가 아니라 is_consent 표시가 가른다. 슬러그는 누구나 바꿀 수 있지만
     *  표시는 약관 관리 화면에서만 붙고 떨어진다. */
    private function isLegal(array $page): bool
    {
        return (int) ($page['is_consent'] ?? 0) === 1;
    }

    private function defaults(): array
    {
        return ['slug' => '', 'title' => '', 'content' => '', 'seo_description' => '',
            'status' => 'draft', 'show_in_menu' => false, 'sort_order' => 0];
    }

    private function withImageKey(array $values): array
    {
        if (!isset($values['image_key']) || preg_match('/^[a-f0-9]{32}$/D', (string) $values['image_key']) !== 1) {
            $values['image_key'] = bin2hex(random_bytes(16));
        }
        return $values;
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
