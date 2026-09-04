<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Service\AttachmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use GnuCms\View\View;

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
        return View::fromRequest($request)->render($response, 'admin/settings', [
            'values' => $this->app->cmsService()->settings(), 'errors' => [],
            'query' => $request->getQueryParams(), 'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function settings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        try {
            $this->app->cmsService()->saveGeneralSettings($this->app->guestAcl(), $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return View::fromRequest($request)->render($response->withStatus(422), 'admin/settings', [
                'values' => $input, 'errors' => $e->details(), 'query' => [],
                'timezones' => \DateTimeZone::listIdentifiers(),
            ]);
        }
        return $this->redirect($request, $response, 'admin.settings', ['saved' => '1']);
    }

    public function writingForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        return View::fromRequest($request)->render($response, 'admin/writing_settings', [
            'values' => $this->app->cmsService()->settings(), 'errors' => [],
            'query' => $request->getQueryParams(), 'server_max_mb' => AttachmentService::serverMaxMb(),
        ]);
    }

    public function writing(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        try {
            $this->app->cmsService()->saveWritingSettings($this->app->guestAcl(), $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return View::fromRequest($request)->render($response->withStatus(422), 'admin/writing_settings', [
                'values' => $input, 'errors' => $e->details(), 'query' => [],
                'server_max_mb' => AttachmentService::serverMaxMb(),
            ]);
        }
        return $this->redirect($request, $response, 'admin.settings.writing', ['saved' => '1']);
    }

    public function securityForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderSecurityForm($request, $response,
            $this->app->turnstileSettingsService()->formValues($this->app->guestAcl()), []);
    }

    public function security(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        try {
            $this->app->turnstileSettingsService()->save($this->app->guestAcl(), $input);
            $this->app->refreshTurnstile();
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            $current = $this->app->turnstileSettingsService()->formValues($this->app->guestAcl());
            $values = $current;
            $values['enabled'] = !empty($input['enabled']);
            $values['site_key'] = is_scalar($input['site_key'] ?? null) ? (string) $input['site_key'] : '';
            $values['hostname'] = is_scalar($input['hostname'] ?? null) ? (string) $input['hostname'] : '';

            return $this->renderSecurityForm(
                $request, $response->withStatus(422), $values, $e->details()
            );
        }

        return $this->redirect($request, $response, 'admin.settings.security', ['saved' => '1']);
    }

    private function renderSecurityForm(ServerRequestInterface $request, ResponseInterface $response,
        array $values, array $errors): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'admin/security_settings', [
            'values' => $values, 'errors' => $errors, 'query' => $request->getQueryParams(),
        ]);
    }

    public function turnstileSecret(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $secret = $this->app->turnstileSettingsService()->secretKey($this->app->guestAcl());
        $response->getBody()->write((string) json_encode(
            ['secret' => $secret],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function oauthForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderOauthForm($request, $response,
            $this->app->oauthSettingsService()->formValues($this->app->guestAcl()), []);
    }

    public function oauth(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        try {
            $this->app->oauthSettingsService()->save($this->app->guestAcl(), $input);
            $this->app->refreshOauthProviders();
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            $values = $this->app->oauthSettingsService()->formValues($this->app->guestAcl());
            foreach (array_keys($values) as $key) {
                $values[$key]['enabled'] = !empty($input[$key . '_enabled']);
                $values[$key]['client_id'] = is_scalar($input[$key . '_client_id'] ?? null)
                    ? (string) $input[$key . '_client_id'] : '';
            }
            return $this->renderOauthForm($request, $response->withStatus(422), $values, $e->details());
        }
        return $this->redirect($request, $response, 'admin.settings.oauth', ['saved' => '1']);
    }

    public function oauthSecret(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $secret = $this->app->oauthSettingsService()->clientSecret(
            $this->app->guestAcl(), (string) $args['provider']
        );
        $response->getBody()->write((string) json_encode(
            ['secret' => $secret],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function renderOauthForm(ServerRequestInterface $request, ResponseInterface $response,
        array $values, array $errors): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'admin/oauth_settings', [
            'values' => $values, 'errors' => $errors, 'query' => $request->getQueryParams(),
        ]);
    }

    public function maintenance(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $acl->assertGlobalAdmin();
        return View::fromRequest($request)->render($response, 'admin/maintenance', [
            'query' => $request->getQueryParams(), 'schema' => $this->app->schemaUpgrader()->status(),
            'backup' => $this->app->backups()->status(),
            'garbage' => $this->app->attachments()->garbageCandidates($acl),
            'backup_upload_max_mb' => AttachmentService::serverMaxMb(), 'backup_error' => null,
        ]);
    }

    public function uploadsGc(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $result = $this->app->attachments()->collectGarbage($this->app->guestAcl());
        $url = RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor('admin.settings.maintenance', [], ['gc' => (string) $result['deleted']]);

        return $response->withHeader('Location', $url)->withStatus(303);
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

    public function mailPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $password = $this->app->mailSettingsService()->password($this->app->guestAcl());
        $response->getBody()->write((string) json_encode(
            ['password' => $password],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
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
        return View::fromRequest($request)->render($response, 'admin/mail', [
            'values' => $values, 'errors' => $errors, 'query' => $request->getQueryParams(),
            'test_error' => $testError,
        ]);
    }

    public function pages(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $trash = $this->app->cmsService()->trash($this->app->guestAcl());
        return View::fromRequest($request)->render($response, 'admin/pages', [
            'pages' => $this->app->cmsService()->contents($this->app->guestAcl()),
            'trash_count' => count($trash),
            'query' => $request->getQueryParams(),
        ]);
    }

    public function trash(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'admin/trash', [
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
        $pages = [];
        foreach ($this->app->cmsService()->consentPages($acl) as $page) {
            // 한 약관은 한 용도만 갖는다. 붙임이 없으면 안내만 하는 약관이다.
            $use = $page['uses'][0] ?? null;
            $page['usage'] = $use === null ? 'none'
                : ((string) $use['scope'] === 'signup' ? 'signup' : 'form');
            $page['usage_required'] = $use === null ? 1 : (int) $use['required'];
            $page['usage_order'] = $use === null ? 0 : (int) $use['sort_order'];
            $pages[] = $page;
        }
        $query = $request->getQueryParams();
        return View::fromRequest($request)->render($response, 'admin/legal', [
            'pages' => $pages,
            'saved' => ($query['saved'] ?? '') === '1',
            'created' => ($query['created'] ?? '') === '1',
            'deleted' => ($query['deleted'] ?? '') === '1',
        ]);
    }

    /**
     * 약관마다 용도를 통째로 다시 쓴다. 회원가입 동의(signup)나 신청서·등록 동의(form)를
     * 고르면 그 자리에만 붙고, 안내만 하는 약관은 어느 자리에도 붙지 않는다.
     * 값을 요청에서 믿지 않고 서버가 가진 약관 목록만 돈다.
     */
    public function consentUses(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $acl = $this->app->guestAcl();
        $usage = is_array($input['usage'] ?? null) ? $input['usage'] : [];
        $required = is_array($input['required'] ?? null) ? $input['required'] : [];
        $order = is_array($input['sort_order'] ?? null) ? $input['sort_order'] : [];

        $uses = $this->app->consentUses();
        foreach ($this->app->cmsService()->consentPages($acl) as $page) {
            $id = (int) $page['id'];
            $choice = isset($usage[$id]) && is_string($usage[$id]) ? $usage[$id] : 'none';
            // 목록 화면에는 자리 이름 칸이 없다. form 을 그대로 두면 이미 붙어 있던
            // form:{이름} 을 지켜, 일괄 저장이 이름을 조용히 지우지 않게 한다.
            $keepScope = null;
            foreach ($page['uses'] as $use) {
                if (strpos((string) $use['scope'], 'form') === 0) {
                    $keepScope = (string) $use['scope'];
                    break;
                }
            }
            // 한 약관은 한 용도만 갖는다. 용도를 바꾸면 옛 자리 붙임부터 걷는다.
            $uses->detachContent($id);
            if ($choice === 'signup' || $choice === 'form') {
                $scope = $choice === 'signup' ? 'signup' : ($keepScope ?? 'form');
                $uses->attach($scope, $id, !empty($required[$id]), (int) ($order[$id] ?? 0));
            }
        }

        return $this->redirect($request, $response, 'admin.terms', ['saved' => '1']);
    }

    /** 한 약관에 누가 동의했고 누가 안 했는지. */
    public function consents(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $page = $this->app->cmsService()->page($acl, (int) $args['id']);

        return View::fromRequest($request)->render($response, 'admin/consents', [
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
        // 약관은 약관 관리에서만 만든다. 여기로 약관 표시를 실어 보내도 무시한다.
        unset($input['is_consent']);
        $acl = $this->app->guestAcl();
        try {
            $this->app->cmsService()->createPage($acl, $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->renderPageForm($request, $response->withStatus(422),
                $this->withImageKey($input), $e->details(), true);
        }
        return $this->redirect($request, $response, 'admin.content', ['saved' => '1']);
    }

    public function termsCreateForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $values = $this->withImageKey($this->defaults());
        // 약관을 새로 만드는 까닭은 대개 가입 동의라, 회원가입 동의를 기본으로 둔다.
        $values['consent_usage'] = 'signup';
        $values['consent_required'] = 1;
        $values['consent_order'] = 0;
        // 약관은 으레 하단에 나온다. 기본으로 켠다.
        $values['show_in_menu'] = 1;
        return $this->renderPageForm($request, $response, $values, [], true, 0, true);
    }

    /** 새 약관. 여기서 만든 내용에만 약관 표시가 붙는다. */
    public function termsCreate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $input['is_consent'] = '1';
        $acl = $this->app->guestAcl();
        try {
            $id = $this->app->cmsService()->createPage($acl, $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->renderPageForm($request, $response->withStatus(422),
                $this->withImageKey($input), $e->details(), true, 0, true);
        }
        $this->applyConsentUsage($id, $input);
        return $this->redirect($request, $response, 'admin.terms', ['created' => '1']);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $page = $this->app->cmsService()->page($this->app->guestAcl(), $id);
        $legal = $this->isLegal($page);
        $values = $this->withImageKey($page);
        if ($legal) {
            $values = $this->withConsentUsage($values, $id);
        }
        return $this->renderPageForm($request, $response, $values, [], false, $id, $legal);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $id = (int) $args['id'];
        $acl = $this->app->guestAcl();
        $page = $this->app->cmsService()->page($acl, $id);
        $legal = $this->isLegal($page);
        // 약관 여부는 만들 때 정해진다. 수정 요청에 실려 온 표시는 무시해
        // 일반 내용이 슬쩍 약관이 되거나 그 반대가 되는 길을 막는다.
        unset($input['is_consent']);
        try {
            $this->app->cmsService()->updatePage($acl, $id, $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->renderPageForm($request, $response->withStatus(422),
                $this->withImageKey($input), $e->details(), false, $id, $legal);
        }
        if ($legal) {
            $this->applyConsentUsage($id, $input);
        }
        return $this->redirect($request, $response, $legal ? 'admin.terms' : 'admin.content', ['saved' => '1']);
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $page = $this->app->cmsService()->page($this->app->guestAcl(), (int) $args['id']);
        return View::fromRequest($request)->render($response, 'pages/show', [
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
        return View::fromRequest($request)->render($response, 'admin/page_form', [
            'values' => $values, 'errors' => $errors, 'create' => $create, 'page_id' => $id, 'legal' => $legal,
        ]);
    }

    /** 편집 폼에 보여 줄 사용처. 붙임이 없으면 안내만 하는 약관이다. */
    private function withConsentUsage(array $values, int $id): array
    {
        $use = $id > 0 ? ($this->app->consentUses()->listForContent($id)[0] ?? null) : null;
        $scope = $use === null ? '' : (string) $use['scope'];
        $values['consent_usage'] = $use === null ? 'none' : ($scope === 'signup' ? 'signup' : 'form');
        // 신청서 자리는 form:{이름} 으로 저장된다. 이름이 없으면 이름 없는 한 통이다.
        $values['consent_scope_key'] = strpos($scope, 'form:') === 0 ? substr($scope, 5) : '';
        $values['consent_required'] = $use === null ? 1 : (int) $use['required'];
        $values['consent_order'] = $use === null ? 0 : (int) $use['sort_order'];
        return $values;
    }

    /**
     * 신청서 자리 이름을 form:{이름} 으로 만든다. 나중에 신청서가 여럿일 때
     * 어느 폼의 약관인지 이 이름으로 가른다. 비우면 이름 없는 form 한 통이다.
     */
    private function formScope(array $input): string
    {
        $key = isset($input['consent_scope_key']) && is_scalar($input['consent_scope_key'])
            ? strtolower(trim((string) $input['consent_scope_key'])) : '';
        // scope 칸은 VARCHAR(40) 이고 'form:' 이 다섯 자를 쓴다.
        if ($key === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,34}$/D', $key) !== 1) {
            return 'form';
        }
        return 'form:' . $key;
    }

    /** 저장이 끝난 약관에 폼에서 고른 사용처를 반영한다. 한 약관은 한 용도만 갖는다. */
    private function applyConsentUsage(int $id, array $input): void
    {
        $choice = isset($input['consent_usage']) && is_string($input['consent_usage'])
            ? $input['consent_usage'] : 'none';
        $uses = $this->app->consentUses();
        $uses->detachContent($id);
        if ($choice === 'signup' || $choice === 'form') {
            $scope = $choice === 'signup' ? 'signup' : $this->formScope($input);
            $uses->attach($scope, $id, !empty($input['consent_required']),
                (int) ($input['consent_order'] ?? 0));
        }
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
