<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Service\BoardService;
use GnuCms\Support\IpAddress;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use GnuCms\View\View;

final class PostController
{
    /**
     * PostService::update() 의 공지 가드(Acl::assertGlobalAdmin/assertAdminFor)가
     * 던지는, 상세 없는 401/403 메시지. update() 안에서 이 문구가 나올 곳은
     * 공지 가드뿐이므로(다른 권한 거부는 다른 문구를 쓴다) 이걸로 오류를
     * password 칸이 아니라 공지 칸으로 보낼지 가른다.
     */
    private const NOTICE_GUARD_MESSAGES = ['전역 관리자만 할 수 있습니다.', '이 게시판의 관리자만 할 수 있습니다.'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** 게시판을 넘나드는 전체 글. */
    public function all(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        return View::fromRequest($request)->render($response, 'posts/all', [
            'list' => $this->app->postService()->listRecentPosts($this->app->guestAcl(), $query),
            'query' => ['q' => isset($query['q']) && is_scalar($query['q']) ? (string) $query['q'] : null],
        ]);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $key = (string) $args['key'];
        $query = $request->getQueryParams();

        $board = $this->app->boardService()->get($acl, $key);
        $boardEntity = $this->app->boardService()->getEntity($acl, $key);
        $list = $this->app->postService()->listPosts($acl, $key, $query);

        return View::fromRequest($request)->render($response, 'posts/index', [
            'board' => $board,
            'list'  => $list,
            'can_write' => $acl->canWrite($boardEntity),
            'view' => $this->resolveView($query, $board),
            'view_types' => BoardService::LIST_TYPES,
            'query' => [
                'q'        => isset($query['q']) ? (string) $query['q'] : null,
                'category' => isset($query['category']) ? (string) $query['category'] : null,
            ],
        ]);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $id = (int) $args['id'];
        $loaded = $this->app->postService()->loadForRead($acl, $id, null);

        $attachments = [];
        foreach ($loaded['post']['attachments'] as $stored) {
            // 저장본에는 서명이 없다. 폼이 다시 제출할 수 있게 여기서 붙인다.
            $attachments[] = $this->app->attachments()->withSignature($stored);
        }

        return $this->renderEditForm($request, $response, $id, [
            'title'     => $loaded['post']['title'],
            'content'   => $loaded['post']['content'],
            'category'  => $loaded['post']['category'],
            'is_secret' => (bool) $loaded['post']['is_secret'],
            'image_key' => (string) ($loaded['post']['image_key'] ?? '') ?: bin2hex(random_bytes(16)),
            'attachments' => $attachments,
            'notice' => $this->noticeChoiceOf($loaded['post']),
        ], []);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $id = (int) $args['id'];
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $this->assertCsrf($input);

        try {
            $this->app->postService()->update($acl, $id, $input);
        } catch (DomainError $e) {
            // 비밀번호가 틀린 경우(401/403)도 오류 화면 대신 폼에서 알려 준다.
            if (!in_array($e->status(), [401, 403, 422], true)) {
                throw $e;
            }
            $errors = $e->details();
            if ($errors === []) {
                // needs_password 가 거짓인 요청(예: 자기 게시판 글을 고치는 게시판
                // 관리자)에는 password 칸이 아예 없어, 그리로 보내면 오류가 화면 어디에도
                // 나타나지 않는다. 공지 가드가 던진 오류는 공지 칸 아래로 보낸다.
                $field = in_array($e->getMessage(), self::NOTICE_GUARD_MESSAGES, true) ? 'notice' : 'password';
                $errors = [$field => $e->getMessage()];
            }

            return $this->renderEditForm($request, $response->withStatus(422), $id, $input, $errors);
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('posts.show', ['id' => (string) $id]);

        return $response->withHeader('Location', $url)->withStatus(303);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $id = (int) $args['id'];
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $this->assertCsrf($input);

        $boardKey = (string) $this->app->postService()
            ->loadForRead($acl, $id, null)['board']['board_key'];

        try {
            $this->app->postService()->delete($acl, $id, isset($input['password']) ? (string) $input['password'] : null);
        } catch (DomainError $e) {
            if (!in_array($e->status(), [401, 403, 422], true)) {
                throw $e;
            }

            // 422 는 칸별 상세(비밀번호 오류 등)를 그대로 보여 준다. getMessage() 만 쓰면
            // '입력값을 확인해 주세요' 라는 껍데기 문구가 비밀번호 칸에 붙는다.
            $errors = $e->details();
            if ($errors === []) {
                $errors = ['password' => $e->getMessage()];
            }

            return $this->renderEditForm($request, $response->withStatus(422), $id, $input, $errors);
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor('posts.index', ['key' => $boardKey]);

        return $response->withHeader('Location', $url)->withStatus(303);
    }

    private function renderEditForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id,
        array $values,
        array $errors
    ): ResponseInterface {
        $acl = $this->app->guestAcl();
        $loaded = $this->app->postService()->loadForRead($acl, $id, null);
        $post = $loaded['post'];

        // 422 재렌더는 원 요청 그대로인 $values 를 쓴다. 게시판 관리자에게는 전체 공지
        // 라디오가 없으므로 그 관리자가 제출한 요청에는 notice 키가 아예 없을 수 있는데,
        // 그걸 그냥 두면 화면의 def() 가 '공지 아님'으로 떨어져 폼에 잘못 체크되고,
        // 그대로 재제출하면 이미 있던 전체 공지가 조용히 내려간다. GET 폼(editForm())과
        // 똑같이 저장된 상태에서 채워 넣어야 그 재렌더가 안전하다.
        if (!array_key_exists('notice', $values)) {
            $values['notice'] = $this->noticeChoiceOf($post);
        }

        return View::fromRequest($request)->render($response, 'posts/edit', [
            'board'  => $this->app->boardService()->get($acl, (string) $loaded['board']['board_key']),
            'post'   => ['id' => $id, 'author_id' => $post['author_id']],
            'errors' => $errors,
            'values' => $values,
            // 비회원이 쓴 글은 비밀번호로 주인을 확인한다. 관리자는 그냥 고칠 수 있다.
            'needs_password' => $post['author_id'] === null && !$acl->isAdminFor($loaded['board']),
            'can_manage_board' => $acl->isAdminFor($loaded['board']),
            // 전체 공지 선택지는 사이트 관리자에게만 보인다. 게시판 관리자는 이 게시판
            // 공지까지만 고를 수 있다.
            'can_pin_global' => $acl->isGlobalAdmin(),
            // 상태 줄("현재 전체 공지입니다")은 제출된 값이 아니라 저장된 값을 본다.
            // $values['notice'] 는 요청이 조작할 수 있어 이걸로 판단하면 안 된다.
            'notice_current' => $this->noticeChoiceOf($post),
        ]);
    }

    /** 저장된 글의 공지 상태를 폼의 notice 라디오 값(none|board|global)으로 바꾼다. */
    private function noticeChoiceOf(array $post): string
    {
        if (!$post['is_notice']) {
            return 'none';
        }

        return ($post['notice_scope'] ?? 'board') === 'global' ? 'global' : 'board';
    }

    /**
     * 목록 형태를 정한다. 게시판 설정이 기본값이고 ?view= 로 잠시 바꿔 볼 수 있다.
     * 이 값은 템플릿 파일 이름에 들어가므로 반드시 허용 목록 안에서만 고른다.
     */
    private function resolveView(array $query, array $board): string
    {
        $requested = isset($query['view']) ? (string) $query['view'] : '';
        if (in_array($requested, BoardService::LIST_TYPES, true)) {
            return $requested;
        }

        $configured = (string) ($board['list_type'] ?? 'list');

        return in_array($configured, BoardService::LIST_TYPES, true) ? $configured : 'list';
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $key = (string) $args['key'];
        $entity = $this->app->boardService()->getEntity($acl, $key);
        $acl->assertCanWrite($entity);

        return View::fromRequest($request)->render($response, 'posts/create', [
            'board' => $this->app->boardService()->get($acl, $key),
            'errors' => [],
            // 편집기가 올린 이미지를 한 폴더로 묶는 키. 저장할 때 본문에 남은 것만 남긴다.
            'values' => ['image_key' => bin2hex(random_bytes(16))],
            'can_manage_board' => $acl->isAdminFor($entity),
            // 전체 공지 선택지는 사이트 관리자에게만 보인다.
            'can_pin_global' => $acl->isGlobalAdmin(),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $key = (string) $args['key'];
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $this->assertCsrf($input);

        try {
            $post = $this->app->postService()->create(
                $acl,
                $key,
                $input,
                IpAddress::fromServer($request->getServerParams())
            );
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return View::fromRequest($request)->render(
                $response->withStatus(422),
                'posts/create',
                [
                    'board' => $this->app->boardService()->get($acl, $key),
                    'errors' => $e->details(),
                    'values' => $input,
                    // 관리자에게만 보이는 공지 선택지. 여기서 빠뜨리면 검증 실패로 폼이
                    // 되돌아올 때 고른 공지 범위가 화면에서 사라진다.
                    'can_manage_board' => $acl->isAdminFor($this->app->boardService()->getEntity($acl, $key)),
                    // 전체 공지 선택지는 사이트 관리자에게만 보인다.
                    'can_pin_global' => $acl->isGlobalAdmin(),
                ]
            );
        }

        $grant = $this->app->postService()->guestOwnershipGrant((int) $post['id']);
        if ($grant !== null) {
            $_SESSION['secret_posts'] = isset($_SESSION['secret_posts']) && is_array($_SESSION['secret_posts'])
                ? $_SESSION['secret_posts'] : [];
            $_SESSION['secret_posts'][(string) $post['id']] = $grant;
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('posts.show', ['id' => (string) $post['id']]);
        return $response->withHeader('Location', $url)->withStatus(303);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $id = (int) $args['id'];
        $challenge = $this->app->postService()->secretChallenge($acl, $id);
        if ($challenge !== null) {
            return $this->renderSecretPassword($request, $response, $challenge, []);
        }

        // PostService::detail() 은 board_key 를 담지 않으므로, 목록으로 돌아갈 링크를
        // 만들려면 loadForRead() 로 얻은 게시판 키로 게시판을 따로 불러야 한다.
        $loaded = $this->app->postService()->loadForRead($acl, $id, null);
        $board = $this->app->boardService()->get($acl, (string) $loaded['board']['board_key']);

        $alreadyViewed = $this->hasViewedPost($id);
        $post = $this->app->postService()->get($acl, $id, null, !$alreadyViewed);
        if (!$alreadyViewed) {
            $this->rememberViewedPost($id);
        }
        $comments = $this->app->commentService()->listComments($acl, $id, null);
        $query = $request->getQueryParams();
        $navigationScope = ($query['scope'] ?? '') === 'all' ? 'all' : 'board';
        $belowViewList = $board['show_list_below_view']
            ? $this->app->postService()->listPosts($acl, (string) $board['board_key'], $query) : null;

        return View::fromRequest($request)->render($response, 'posts/show', [
            'post'     => $post,
            'board'    => $board,
            'comments' => $comments,
            'adjacent_posts' => $this->app->postService()->adjacentPosts($acl, $id, $navigationScope === 'all'),
            'navigation_scope' => $navigationScope,
            'below_view_list' => $belowViewList,
            'below_view' => $this->resolveView($query, $board),
            'below_view_query' => [
                'q' => isset($query['q']) && is_scalar($query['q']) ? (string) $query['q'] : null,
                'category' => isset($query['category']) && is_scalar($query['category'])
                    ? (string) $query['category'] : null,
            ],
            'view_types' => BoardService::LIST_TYPES,
            'can_write' => $acl->canWrite($loaded['board']),
            'can_comment' => $acl->canCommentOnPost($loaded['board'], $loaded['post']),
            'comment_errors' => [],
            'comment_values' => ['image_key' => bin2hex(random_bytes(16))],
        ]);
    }

    public function unlockSecret(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $this->assertCsrf($input);
        $acl = $this->app->guestAcl();
        $challenge = $this->app->postService()->secretChallenge($acl, $id);
        if ($challenge === null) {
            return $this->redirectToPost($request, $response, $id);
        }
        $password = isset($input['password']) && is_scalar($input['password']) ? (string) $input['password'] : '';
        if ($password === '') {
            return $this->renderSecretPassword($request, $response->withStatus(422), $challenge,
                ['password' => '비밀번호를 입력해 주세요.']);
        }
        try {
            $loaded = $this->app->postService()->loadForRead($acl, $id, $password);
        } catch (DomainError $e) {
            if (!in_array($e->status(), [403, 422], true)) {
                throw $e;
            }
            $message = $e->status() === 422 && isset($e->details()['password'])
                ? (string) $e->details()['password'] : '비밀번호가 올바르지 않습니다.';
            return $this->renderSecretPassword($request, $response->withStatus(422), $challenge,
                ['password' => $message]);
        }
        $_SESSION['secret_posts'] = isset($_SESSION['secret_posts']) && is_array($_SESSION['secret_posts'])
            ? $_SESSION['secret_posts'] : [];
        $_SESSION['secret_posts'][(string) $id] = \GnuCms\Auth\Acl::secretGrantFor($loaded['post']);

        return $this->redirectToPost($request, $response, $id);
    }

    private function hasViewedPost(int $id): bool
    {
        $viewed = $_SESSION['viewed_posts'] ?? null;
        return is_array($viewed) && array_key_exists((string) $id, $viewed);
    }

    private function rememberViewedPost(int $id): void
    {
        $viewed = isset($_SESSION['viewed_posts']) && is_array($_SESSION['viewed_posts'])
            ? $_SESSION['viewed_posts'] : [];
        $viewed[(string) $id] = time();
        $_SESSION['viewed_posts'] = $viewed;
    }

    private function renderSecretPassword(ServerRequestInterface $request, ResponseInterface $response,
        array $challenge, array $errors): ResponseInterface
    {
        return View::fromRequest($request)->render(
            $response->withHeader('X-Robots-Tag', 'noindex, nofollow'),
            'posts/password', [
            'post_id' => $challenge['post_id'], 'board' => $challenge['board'], 'errors' => $errors,
        ]);
    }

    private function redirectToPost(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('posts.show', ['id' => (string) $id]);

        return $response->withHeader('Location', $url)->withStatus(303);
    }

    private function assertCsrf(array $input): void
    {
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        $given = isset($input['csrf_token']) && is_scalar($input['csrf_token']) ? (string) $input['csrf_token'] : '';
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw DomainError::forbidden('요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }
    }
}
