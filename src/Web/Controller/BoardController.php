<?php

declare(strict_types=1);

namespace ApiBoard\Web\Controller;

use ApiBoard\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class BoardController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $boards = $this->app->boardService()->listBoards($this->app->guestAcl());
        foreach ($boards as &$board) {
            $board['latest_posts'] = $this->app->postService()->latestPosts(
                $this->app->guestAcl(),
                (string) $board['board_key'],
                5
            );
        }
        unset($board);

        return Twig::fromRequest($request)->render($response, 'home/index.html.twig', [
            'boards' => $boards,
        ]);
    }
}
