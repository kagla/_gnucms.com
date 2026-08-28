<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Service\BoardService;
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
        $acl = $this->app->guestAcl();
        $boards = [];
        foreach ($this->app->boardService()->listBoards($acl) as $board) {
            // 메인 노출 글 수가 0 이면 그 게시판은 메인에 내지 않는다.
            $limit = (int) ($board['home_limit'] ?? BoardService::DEFAULT_HOME_LIMIT);
            if ($limit < 1) {
                continue;
            }
            $board['latest_posts'] = $this->app->postService()->latestPosts(
                $acl,
                (string) $board['board_key'],
                $limit
            );
            $boards[] = $board;
        }

        return Twig::fromRequest($request)->render($response, 'home/index.html.twig', [
            'boards' => $boards,
        ]);
    }
}
