<?php

declare(strict_types=1);

namespace ApiBoard\Web\Controller;

use ApiBoard\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
        $list = $this->app->postService()->listPosts($acl, $key, $query);

        return Twig::fromRequest($request)->render($response, 'posts/index.html.twig', [
            'board' => $board,
            'list'  => $list,
            'query' => [
                'q'        => isset($query['q']) ? (string) $query['q'] : null,
                'category' => isset($query['category']) ? (string) $query['category'] : null,
            ],
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $id = (int) $args['id'];

        // 1단계에는 비밀글 비밀번호를 받을 폼이 없다. 비밀글은 403 으로 막힌다.
        $post = $this->app->postService()->get($acl, $id, null);
        $comments = $this->app->commentService()->listComments($acl, $id, null);

        return Twig::fromRequest($request)->render($response, 'posts/show.html.twig', [
            'post'     => $post,
            'comments' => $comments,
        ]);
    }
}
