<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use GnuCms\View\View;

final class AdminController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->app->adminService()->dashboard($this->app->guestAcl());
        $data['page_count'] = $this->app->cmsService()->countPages();
        $data['query'] = $request->getQueryParams();
        // SMTP 가 없으면 가입 인증·비밀번호 변경 알림이 서버 기본 메일로만 나가 안 닿기 쉽다. 늘 보여 준다.
        $data['mail_configured'] = $this->app->mailSettingsService()->runtime() !== null;
        return View::fromRequest($request)->render($response, 'admin/index', $data);
    }

    public function boards(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'admin/boards', [
            'boards' => $this->app->adminService()->boards($this->app->guestAcl()),
            'query' => $request->getQueryParams(),
        ]);
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        return $this->renderBoardForm($request, $response, $this->defaults(), [], true);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $values = $this->boardInput($input);
        try {
            $this->app->adminService()->createBoard($this->app->guestAcl(), $values);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->renderBoardForm($request, $response->withStatus(422), $input, $e->details(), true);
        }
        return $this->redirect($request, $response, 'admin.boards', ['saved' => '1']);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->app->adminService()->board($this->app->guestAcl(), (string) $args['key']);
        return $this->renderBoardForm($request, $response, $this->formValues($board), [], false, (string) $args['key']);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $key = (string) $args['key'];
        try {
            $this->app->adminService()->updateBoard($this->app->guestAcl(), $key, $this->boardInput($input));
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->renderBoardForm($request, $response->withStatus(422), $input, $e->details(), false, $key);
        }
        return $this->redirect($request, $response, 'admin.boards', ['saved' => '1']);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $this->app->adminService()->deleteBoard($this->app->guestAcl(), (string) $args['key']);
        return $this->redirect($request, $response, 'admin.boards', ['deleted' => '1']);
    }

    public function members(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $query = $params['q'] ?? '';
        $query = is_scalar($query) ? trim((string) $query) : '';
        return View::fromRequest($request)->render($response, 'admin/members', [
            'members' => $this->app->adminService()->members($this->app->guestAcl(), $query),
            'query' => $query,
            'saved' => ($params['saved'] ?? '') === '1',
            'mail_failed' => ($params['mail'] ?? '') === 'failed',
        ]);
    }


    public function memberEditForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $member = $this->app->adminService()->member($this->app->guestAcl(), (int) $args['id']);
        return $this->renderMemberForm($request, $response, $member, []);
    }

    public function memberUpdate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $id = (int) $args['id'];
        $member = $this->app->adminService()->member($this->app->guestAcl(), $id);
        try {
            $this->app->adminService()->updateMember($this->app->guestAcl(), $id, $input);
            // 내 비밀번호를 바꾸면 session_epoch 가 올라가 지금 세션도 끊긴다. 방금 바꾼 사람은
            // 그대로 두고, 다른 기기만 끊기게 세션의 epoch 를 새 값으로 맞춘다.
            $changedPassword = isset($input['password']) && is_scalar($input['password']) && (string) $input['password'] !== '';
            $me = (string) $this->app->guestAcl()->identity()->sub() === (string) $id;
            if ($me && $changedPassword) {
                $fresh = $this->app->users()->findById($id);
                if ($fresh !== null) {
                    $_SESSION['session_epoch'] = (int) $fresh['session_epoch'];
                }
            }
            $mailFailed = $changedPassword && !$this->app->accountService()->notifyPasswordChanged($id);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->renderMemberForm(
                $request,
                $response->withStatus(422),
                array_merge($member, $input),
                $e->details()
            );
        }
        return $this->redirect($request, $response, 'admin.members',
            ['saved' => '1'] + ($mailFailed ? ['mail' => 'failed'] : []));
    }


    public function toggleStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->assertCsrf($this->input($request));
        $this->app->adminService()->toggleStatus($this->app->guestAcl(), (int) $args['id']);
        return $this->redirect($request, $response, 'admin.members');
    }

    private function renderBoardForm(ServerRequestInterface $request, ResponseInterface $response, array $values,
        array $errors, bool $create, string $key = ''): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'admin/board_form', [
            'values' => $values, 'errors' => $errors, 'create' => $create, 'board_key' => $key,
        ]);
    }

    private function renderMemberForm(ServerRequestInterface $request, ResponseInterface $response, array $values,
        array $errors): ResponseInterface
    {
        $providerLabels = ['google' => 'Google', 'naver' => '네이버', 'kakao' => '카카오'];
        $identities = $this->app->identities()->listForUser((int) $values['id']);
        foreach ($identities as &$identity) {
            $key = (string) $identity['provider'];
            $identity['label'] = $providerLabels[$key] ?? ucfirst($key);
        }
        unset($identity);

        return View::fromRequest($request)->render($response, 'admin/member_form', [
            'values' => $values,
            'errors' => $errors,
            'member_identities' => $identities,
            'member_login_events' => $this->app->loginEvents()->recentForUser((int) $values['id']),
            // 가입 동의 내역은 고칠 수 없는 기록이라 폼 밖에 따로 보여 준다.
            'member_consents' => $this->app->consents()
                ->forSubjectWithDocument('user', (int) $values['id']),
        ]);
    }

    /** 게시판을 가로지르는 전체 글 목록. 대시보드의 게시글 카드에서 들어온다. */
    public function posts(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $query = $request->getQueryParams();

        return View::fromRequest($request)->render($response, 'admin/posts', [
            'list'   => $this->app->postService()->listAllPosts($acl, $query),
            'boards' => $this->app->adminService()->boards($acl),
            'query'  => [
                'q'     => isset($query['q']) ? (string) $query['q'] : null,
                'board' => isset($query['board']) ? (string) $query['board'] : null,
            ],
        ]);
    }

    private function boardInput(array $input): array
    {
        return [
            'board_key' => trim((string) ($input['board_key'] ?? '')),
            'name' => trim((string) ($input['name'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'categories' => $this->newlineItems((string) ($input['categories_text'] ?? '')),
            'managers' => $this->lines((string) ($input['managers_text'] ?? '')),
            'perm_read' => (string) ($input['perm_read'] ?? 'guest'),
            'perm_write' => (string) ($input['perm_write'] ?? 'member'),
            'perm_comment' => (string) ($input['perm_comment'] ?? 'member'),
            'use_secret' => isset($input['use_secret']) ? '1' : '0',
            'use_file' => isset($input['use_file']) ? '1' : '0',
            'use_category' => isset($input['use_category']) ? '1' : '0',
            'show_in_header' => isset($input['show_in_header']) ? '1' : '0',
            'show_list_below_view' => isset($input['show_list_below_view']) ? '1' : '0',
            'list_type' => (string) ($input['list_type'] ?? 'list'),
            'home_limit' => (string) ($input['home_limit'] ?? '5'),
            'per_page' => (string) ($input['per_page'] ?? '20'),
            'sort_order' => (string) ($input['sort_order'] ?? '0'),
        ];
    }

    private function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []),
            static fn(string $item): bool => $item !== ''));
    }

    private function newlineItems(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: []),
            static fn(string $item): bool => $item !== ''));
    }

    private function formValues(array $board): array
    {
        $board['categories_text'] = implode("\n", $board['categories'] ?? []);
        $board['managers_text'] = implode("\n", $board['managers'] ?? []);
        return $board;
    }

    private function defaults(): array
    {
        return ['board_key' => '', 'name' => '', 'description' => '', 'categories_text' => '', 'managers_text' => '',
            'perm_read' => 'guest', 'perm_write' => 'member', 'perm_comment' => 'member', 'use_secret' => false,
            'use_file' => false, 'use_category' => false, 'list_type' => 'list',
            'show_in_header' => false, 'show_list_below_view' => false,
            'home_limit' => 5, 'per_page' => 20, 'sort_order' => 0];
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

    private function redirect(ServerRequestInterface $request, ResponseInterface $response, string $route, array $query = []): ResponseInterface
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($route);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $response->withHeader('Location', $url)->withStatus(303);
    }
}
