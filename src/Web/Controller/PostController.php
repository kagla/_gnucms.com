<?php

declare(strict_types=1);

namespace ApiBoard\Web\Controller;

use ApiBoard\App;
use ApiBoard\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

final class PostController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $key = (string) $args['key'];
        $query = $request->getQueryParams();

        $board = $this->app->boardService()->get($acl, $key);
        $boardEntity = $this->app->boardService()->getEntity($acl, $key);
        $list = $this->app->postService()->listPosts($acl, $key, $query);

        return Twig::fromRequest($request)->render($response, 'posts/index.html.twig', [
            'board' => $board,
            'list'  => $list,
            'can_write' => $acl->canWrite($boardEntity),
            'query' => [
                'q'        => isset($query['q']) ? (string) $query['q'] : null,
                'category' => isset($query['category']) ? (string) $query['category'] : null,
            ],
        ]);
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $key = (string) $args['key'];
        $entity = $this->app->boardService()->getEntity($acl, $key);
        $acl->assertCanWrite($entity);

        return Twig::fromRequest($request)->render($response, 'posts/create.html.twig', [
            'board' => $this->app->boardService()->get($acl, $key),
            'errors' => [],
            'values' => [],
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
            return Twig::fromRequest($request)->render(
                $response->withStatus(422),
                'posts/create.html.twig',
                [
                    'board' => $this->app->boardService()->get($acl, $key),
                    'errors' => $e->details(),
                    'values' => $input,
                ]
            );
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('posts.show', ['id' => (string) $post['id']]);
        return $response->withHeader('Location', $url)->withStatus(303);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $id = (int) $args['id'];

        // 1단계에는 비밀글 비밀번호를 받을 폼이 없다. 비밀글은 403 으로 막힌다.
        // PostService::detail() 은 board_key 를 담지 않으므로, 목록으로 돌아갈 링크를
        // 만들려면 loadForRead() 로 얻은 게시판 키로 게시판을 따로 불러야 한다.
        $loaded = $this->app->postService()->loadForRead($acl, $id, null);
        $board = $this->app->boardService()->get($acl, (string) $loaded['board']['board_key']);

        $post = $this->app->postService()->get($acl, $id, null);
        $comments = $this->app->commentService()->listComments($acl, $id, null);

        return Twig::fromRequest($request)->render($response, 'posts/show.html.twig', [
            'post'     => $post,
            'board'    => $board,
            'comments' => $comments,
        ]);
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
