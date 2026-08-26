<?php

declare(strict_types=1);

namespace StandardBoard;

use StandardBoard\Http\Request;
use StandardBoard\Http\Response;
use StandardBoard\Http\Router;

/**
 * 경로 등록의 단일 지점. 이후 태스크가 여기에 자기 경로를 추가한다.
 */
final class Routes
{
    public static function register(Router $router, App $app): void
    {
        $router->get('/health', static function (Request $request, array $params) use ($app): Response {
            return Response::json([
                'ok'      => true,
                'dialect' => $app->db()->dialect()->name(),
            ]);
        });
    }
}
