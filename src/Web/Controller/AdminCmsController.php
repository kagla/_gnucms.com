<?php

declare(strict_types=1);

namespace ApiBoard\Web\Controller;

use ApiBoard\App;
use ApiBoard\Error\DomainError;
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

    public function legal(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Twig::fromRequest($request)->render($response, 'admin/legal.html.twig', [
            'legal' => $this->app->cmsService()->legalOverview($this->app->guestAcl()),
            'query' => $request->getQueryParams(),
        ]);
    }

    public function legalEditForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $type = (string) $args['type'];
        $values = $this->withImageKey($this->requiredLegalPage($type));
        return Twig::fromRequest($request)->render($response, 'admin/legal_form.html.twig', [
            'type' => $type, 'values' => $values, 'errors' => [],
        ]);
    }

    public function legalUpdate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $type = (string) $args['type'];
        $page = $this->requiredLegalPage($type);
        $input['slug'] = $this->legalSlug($type);
        $input['show_in_menu'] = '0';
        try {
            $this->app->cmsService()->updatePage($this->app->guestAcl(), (int) $page['id'], $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return Twig::fromRequest($request)->render($response->withStatus(422), 'admin/legal_form.html.twig', [
                'type' => $type, 'values' => $this->withImageKey($input), 'errors' => $e->details(),
            ]);
        }
        return $this->redirect($request, $response, 'admin.terms', ['saved' => '1']);
    }

    public function legalPreview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $type = (string) $args['type'];
        return Twig::fromRequest($request)->render($response, 'pages/show.html.twig', [
            'page' => $this->requiredLegalPage($type), 'preview' => true, 'preview_legal_type' => $type,
            'legal_type' => $type,
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
        try {
            $this->app->cmsService()->createPage($this->app->guestAcl(), $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->renderPageForm($request, $response->withStatus(422),
                $this->withImageKey($input), $e->details(), true);
        }
        return $this->redirect($request, $response, 'admin.content', ['saved' => '1']);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $page = $this->app->cmsService()->page($this->app->guestAcl(), (int) $args['id']);
        $this->assertRegularContent($page);
        return $this->renderPageForm($request, $response, $this->withImageKey($page), [], false, (int) $args['id'],
            $this->isLegal($page));
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $id = (int) $args['id'];
        $page = $this->app->cmsService()->page($this->app->guestAcl(), $id);
        $this->assertRegularContent($page);
        $legal = false;
        try {
            $this->app->cmsService()->updatePage($this->app->guestAcl(), $id, $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->renderPageForm($request, $response->withStatus(422),
                $this->withImageKey($input), $e->details(), false, $id, $legal);
        }
        return $this->redirect($request, $response, $legal ? 'admin.terms' : 'admin.content', ['saved' => '1']);
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $page = $this->app->cmsService()->page($this->app->guestAcl(), (int) $args['id']);
        $this->assertRegularContent($page);
        return Twig::fromRequest($request)->render($response, 'pages/show.html.twig', [
            'page' => $page,
            'preview' => true,
            'preview_legal_type' => null,
            'legal_type' => null,
        ]);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $id = (int) $args['id'];
        $page = $this->app->cmsService()->page($this->app->guestAcl(), $id);
        $this->assertRegularContent($page);
        $legal = false;
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

    private function isLegal(array $page): bool
    {
        return in_array($page['slug'], ['terms', 'privacy'], true);
    }

    private function requiredLegalPage(string $type): array
    {
        $legal = $this->app->cmsService()->legalOverview($this->app->guestAcl());
        $slug = $this->legalSlug($type);
        if (!array_key_exists($slug, $legal) || $legal[$slug] === null) {
            throw DomainError::notFound('약관 초안을 먼저 만들어 주세요.');
        }
        return $legal[$slug];
    }

    private function legalSlug(string $type): string
    {
        return $type === 'service' ? 'terms' : 'privacy';
    }

    private function assertRegularContent(array $page): void
    {
        if ($this->isLegal($page)) {
            throw DomainError::notFound('약관은 약관 관리에서 수정해 주세요.');
        }
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
