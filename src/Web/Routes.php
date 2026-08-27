<?php

declare(strict_types=1);

namespace ApiBoard\Web;

use ApiBoard\App;
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
            $response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');

            return Twig::fromRequest($request)->render($response, 'health.html.twig', [
                'dialect' => $app->db()->dialect()->name(),
            ]);
        });
    }
}
