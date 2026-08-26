<?php

declare(strict_types=1);

namespace ApiBoard;

use ApiBoard\Http\Request;
use ApiBoard\Http\Response;
use ApiBoard\Http\Router;

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

        $router->post('/auth/login', static function (Request $request, array $params) use ($app): Response {
            $v = new \ApiBoard\Validation\Validator($request->body());
            $id = $v->requiredString('id', 64);
            $password = $v->requiredString('password', 255);
            $v->check();

            return Response::json(['token' => $app->auth()->login($id, $password)]);
        });

        $router->get('/boards', static function (Request $request, array $params) use ($app): Response {
            return Response::json(['data' => $app->boardService()->listBoards($app->aclFor($request))]);
        });

        $router->post('/boards', static function (Request $request, array $params) use ($app): Response {
            $board = $app->boardService()->create($app->aclFor($request), $request->body());

            return Response::json(['data' => $board], 201);
        });

        $router->get('/boards/{key}', static function (Request $request, array $params) use ($app): Response {
            return Response::json(['data' => $app->boardService()->get($app->aclFor($request), $params['key'])]);
        });

        $router->patch('/boards/{key}', static function (Request $request, array $params) use ($app): Response {
            $board = $app->boardService()->update($app->aclFor($request), $params['key'], $request->body());

            return Response::json(['data' => $board]);
        });

        $router->delete('/boards/{key}', static function (Request $request, array $params) use ($app): Response {
            $app->boardService()->delete($app->aclFor($request), $params['key']);

            return Response::json([], 204);
        });

        $router->get('/boards/{key}/posts', static function (Request $request, array $params) use ($app): Response {
            return Response::json(
                $app->postService()->listPosts($app->aclFor($request), $params['key'], $request->body() + $_GET)
            );
        });

        $router->post('/boards/{key}/posts', static function (Request $request, array $params) use ($app): Response {
            $app->attachments();
            $post = $app->postService()->create($app->aclFor($request), $params['key'], $request->body());

            return Response::json(['data' => $post], 201);
        });

        $router->get('/posts/{id}', static function (Request $request, array $params) use ($app): Response {
            $password = $request->input('password');
            $post = $app->postService()->get(
                $app->aclFor($request),
                (int) $params['id'],
                $password === null ? null : (string) $password
            );

            return Response::json(['data' => $post]);
        });

        $router->patch('/posts/{id}', static function (Request $request, array $params) use ($app): Response {
            $app->attachments();
            $post = $app->postService()->update($app->aclFor($request), (int) $params['id'], $request->body());

            return Response::json(['data' => $post]);
        });

        $router->delete('/posts/{id}', static function (Request $request, array $params) use ($app): Response {
            $password = $request->input('password');
            $app->postService()->delete(
                $app->aclFor($request),
                (int) $params['id'],
                $password === null ? null : (string) $password
            );

            return Response::json([], 204);
        });

        $router->post('/posts/{id}/restore', static function (Request $request, array $params) use ($app): Response {
            $post = $app->postService()->restore($app->aclFor($request), (int) $params['id']);

            return Response::json(['data' => $post]);
        });

        $router->get('/posts/{id}/comments', static function (Request $request, array $params) use ($app): Response {
            $password = $request->input('password');

            return Response::json(['data' => $app->commentService()->listComments(
                $app->aclFor($request),
                (int) $params['id'],
                $password === null ? null : (string) $password
            )]);
        });

        $router->post('/posts/{id}/comments', static function (Request $request, array $params) use ($app): Response {
            $comment = $app->commentService()->create($app->aclFor($request), (int) $params['id'], $request->body());

            return Response::json(['data' => $comment], 201);
        });

        $router->patch('/comments/{id}', static function (Request $request, array $params) use ($app): Response {
            $comment = $app->commentService()->update($app->aclFor($request), (int) $params['id'], $request->body());

            return Response::json(['data' => $comment]);
        });

        $router->delete('/comments/{id}', static function (Request $request, array $params) use ($app): Response {
            $password = $request->input('password');
            $app->commentService()->delete(
                $app->aclFor($request),
                (int) $params['id'],
                $password === null ? null : (string) $password
            );

            return Response::json([], 204);
        });

        $router->post('/uploads', static function (Request $request, array $params) use ($app): Response {
            $boardKey = (string) $request->input('board_key', '');
            $files = $request->files();
            if (!isset($files['file'])) {
                throw \ApiBoard\Http\ApiError::validation(['file' => '파일이 없습니다.']);
            }

            return Response::json(['data' => $app->attachments()->upload(
                $app->aclFor($request),
                $boardKey,
                $files['file']
            )], 201);
        });

        $router->get('/posts/{id}/files/{index}', static function (Request $request, array $params) use ($app) {
            $password = $request->input('password');

            return $app->attachments()->download(
                $app->aclFor($request),
                (int) $params['id'],
                (int) $params['index'],
                $password === null ? null : (string) $password
            );
        });

        $router->post('/maintenance/gc', static function (Request $request, array $params) use ($app): Response {
            return Response::json(['data' => $app->attachments()->collectGarbage($app->aclFor($request))]);
        });
    }
}
