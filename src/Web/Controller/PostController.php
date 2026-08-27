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
}
