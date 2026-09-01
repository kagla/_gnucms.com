<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * 게시글·댓글 본문 편집기가 올리는 이미지.
 *
 * 관리자 전용인 CmsImageController 와 저장 방식은 같지만, 권한 판단이 다르다.
 * 글쓰기는 게시판의 쓰기 권한, 댓글은 댓글 권한을 본다.
 */
final class EditorImageController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function uploadForBoard(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->app->boardService()->getEntity($this->app->guestAcl(), (string) $args['key']);

        return $this->upload($request, $response, function ($acl, $upload, $key) use ($board) {
            return $this->app->contentImages()->uploadForBoard($acl, $board, $upload, $key);
        });
    }

    public function discardForBoard(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->app->boardService()->getEntity($this->app->guestAcl(), (string) $args['key']);

        return $this->discard($request, $response, function ($acl, $key, $files) use ($board) {
            $this->app->contentImages()->discardForBoard($acl, $board, $key, $files);
        });
    }

    public function uploadForComment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $loaded = $this->app->postService()->loadForRead($this->app->guestAcl(), (int) $args['id'], null);
        $this->app->guestAcl()->assertCanCommentOnPost($loaded['board'], $loaded['post']);
        $board = $loaded['board'];

        return $this->upload($request, $response, function ($acl, $upload, $key) use ($board) {
            return $this->app->contentImages()->uploadForComment($acl, $board, $upload, $key);
        });
    }

    public function discardForComment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $loaded = $this->app->postService()->loadForRead($this->app->guestAcl(), (int) $args['id'], null);
        $this->app->guestAcl()->assertCanCommentOnPost($loaded['board'], $loaded['post']);
        $board = $loaded['board'];

        return $this->discard($request, $response, function ($acl, $key, $files) use ($board) {
            $this->app->contentImages()->discardForComment($acl, $board, $key, $files);
        });
    }

    private function upload(ServerRequestInterface $request, ResponseInterface $response, callable $store): ResponseInterface
    {
        $query = $request->getQueryParams();
        $this->assertCsrf($query);
        $key = strtolower((string) ($query['image_key'] ?? ''));
        $upload = $request->getUploadedFiles()['upload'] ?? null;

        if ($upload === null) {
            return $this->json($response, ['error' => ['message' => '업로드할 이미지를 받지 못했습니다. 서버의 업로드 용량 제한을 확인해 주세요.']], 422);
        }

        try {
            $image = $store($this->app->guestAcl(), $upload, $key);
        } catch (DomainError $e) {
            $detail = $e->details()['upload'] ?? null;
            $message = is_scalar($detail) && (string) $detail !== '' ? (string) $detail : $e->getMessage();

            return $this->json($response, ['error' => ['message' => $message]], $e->status());
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('editor.owned_image', $image);

        return $this->json($response, ['uploaded' => 1, 'fileName' => $image['file'], 'url' => $url]);
    }

    private function discard(ServerRequestInterface $request, ResponseInterface $response, callable $remove): ResponseInterface
    {
        $query = $request->getQueryParams();
        $this->assertCsrf($query);
        $body = $request->getParsedBody();
        $files = is_array($body) && isset($body['files']) && is_array($body['files']) ? $body['files'] : [];

        $remove($this->app->guestAcl(), strtolower((string) ($query['image_key'] ?? '')), $files);

        return $response->withStatus(204);
    }

    /** 댓글 권한은 글이 속한 게시판을 기준으로 판단한다. */
    private function boardOfPost(int $postId): array
    {
        return $this->app->postService()->loadForRead($this->app->guestAcl(), $postId, null)['board'];
    }

    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function assertCsrf(array $input): void
    {
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        $given = isset($input['csrf_token']) && is_scalar($input['csrf_token']) ? (string) $input['csrf_token'] : '';
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw DomainError::forbidden('요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }
    }
}
