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

        return Twig::fromRequest($request)->render($response, 'boards/index.html.twig', [
            'boards' => $boards,
        ]);
    }
}
