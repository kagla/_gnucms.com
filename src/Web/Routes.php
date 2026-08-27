<?php

declare(strict_types=1);

namespace ApiBoard\Web;

use ApiBoard\App;
use ApiBoard\Web\Controller\BoardController;
use ApiBoard\Web\Controller\PostController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App as SlimApp;
use Slim\Views\Twig;

final class Routes
{
    public static function register(SlimApp $slim, App $app): void
    {
        $slim->get('/health', static function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($app): ResponseInterface {
            return Twig::fromRequest($request)->render($response, 'health.html.twig', [
                'dialect' => $app->db()->dialect()->name(),
            ]);
        });

        $slim->get('/', [new BoardController($app), 'index'])->setName('boards.index');
        $slim->get('/b/{key}', [new PostController($app), 'index'])->setName('posts.index');
    }
}
