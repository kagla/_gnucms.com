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
}
