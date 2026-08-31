<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Service\BoardService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use GnuCms\View\View;

final class PostController
{
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
                $errors = ['password' => $e->getMessage()];
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

        return View::fromRequest($request)->render($response, 'posts/edit', [
            'board'  => $this->app->boardService()->get($acl, (string) $loaded['board']['board_key']),
            'post'   => ['id' => $id, 'author_id' => $post['author_id']],
            'errors' => $errors,
            'values' => $values,
            // 비회원이 쓴 글은 비밀번호로 주인을 확인한다. 관리자는 그냥 고칠 수 있다.
            'needs_password' => $post['author_id'] === null && !$acl->isAdminFor($loaded['board']),
        ]);
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
            $post = $this->app->postService()->create($acl, $key, $input);
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

        $post = $this->app->postService()->get($acl, $id, null);
        $comments = $this->app->commentService()->listComments($acl, $id, null);

        return View::fromRequest($request)->render($response, 'posts/show', [
            'post'     => $post,
            'board'    => $board,
            'comments' => $comments,
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

    private function renderSecretPassword(ServerRequestInterface $request, ResponseInterface $response,
        array $challenge, array $errors): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'posts/password', [
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
