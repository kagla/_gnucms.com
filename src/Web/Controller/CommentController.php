<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

final class CommentController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $postId = (int) $args['id'];
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $this->assertCsrf($input);

        try {
            $this->app->commentService()->create($acl, $postId, $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            // 검증 실패는 글 화면을 다시 그리면서 입력값과 오류를 함께 보여 준다.
            return $this->renderPost($request, $response->withStatus(422), $postId, $input, $e->details());
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor('posts.show', ['id' => (string) $postId]);

        return $response->withHeader('Location', $url . '#comments')->withStatus(303);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $comment = $this->app->commentService()->getForEdit($this->app->guestAcl(), $id);

        return $this->renderEditForm($request, $response, $comment, [
            'content' => $comment['content'],
            // 사진이 없던 댓글도 수정하면서 넣을 수 있어야 하므로 없으면 새로 만든다.
            'image_key' => $comment['image_key'] ?? bin2hex(random_bytes(16)),
        ], []);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $this->assertCsrf($input);

        $comment = $this->app->commentService()->getForEdit($this->app->guestAcl(), $id);

        try {
            $this->app->commentService()->update($this->app->guestAcl(), $id, $input);
        } catch (DomainError $e) {
            $input['image_key'] = $input['image_key'] ?? $comment['image_key'] ?? bin2hex(random_bytes(16));

            return $this->editFormAfterFailure($request, $response, $comment, $input, $e);
        }

        return $this->backToComment($request, $response, (int) $comment['post_id'], $id);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $this->assertCsrf($input);

        $comment = $this->app->commentService()->getForEdit($this->app->guestAcl(), $id);
        $password = isset($input['password']) && is_scalar($input['password']) ? (string) $input['password'] : null;

        try {
            $this->app->commentService()->delete($this->app->guestAcl(), $id, $password);
        } catch (DomainError $e) {
            return $this->editFormAfterFailure($request, $response, $comment, [
                'content' => $comment['content'],
                'image_key' => $comment['image_key'] ?? bin2hex(random_bytes(16)),
            ], $e);
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor('posts.show', ['id' => (string) $comment['post_id']]);

        return $response->withHeader('Location', $url . '#comments')->withStatus(303);
    }

    /**
     * 비밀번호가 틀리거나 내용이 비었을 때. 오류 화면 대신 수정 폼으로 돌려보낸다.
     * 권한과 상관없는 오류라면 그대로 흘려보낸다.
     */
    private function editFormAfterFailure(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $comment,
        array $values,
        DomainError $e
    ): ResponseInterface {
        if (!in_array($e->status(), [401, 403, 422], true)) {
            throw $e;
        }

        $errors = $e->status() === 422 ? $e->details() : ['password' => $e->getMessage()];

        return $this->renderEditForm($request, $response->withStatus(422), $comment, $values, $errors);
    }

    private function renderEditForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $comment,
        array $values,
        array $errors
    ): ResponseInterface {
        $acl = $this->app->guestAcl();
        $loaded = $this->app->postService()->loadForRead($acl, (int) $comment['post_id'], null);

        return Twig::fromRequest($request)->render($response, 'posts/comment_edit.html.twig', [
            'comment' => $comment,
            'post' => $this->app->postService()->get($acl, (int) $comment['post_id'], null),
            'board' => $this->app->boardService()->get($acl, (string) $loaded['board']['board_key']),
            'values' => $values,
            'errors' => $errors,
            // 비회원이 쓴 댓글은 비밀번호로 주인을 확인한다. 관리자는 물어보지 않는다.
            'needs_password' => $comment['author_id'] === null && !$acl->isAdminFor($loaded['board']),
        ]);
    }

    private function backToComment(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $postId,
        int $commentId
    ): ResponseInterface {
        $url = RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor('posts.show', ['id' => (string) $postId]);

        return $response->withHeader('Location', $url . '#comment-' . $commentId)->withStatus(303);
    }

    /** 댓글 권한은 글이 속한 게시판을 기준으로 판단한다. */
    private function boardOfPost(int $postId): array
    {
        return $this->app->postService()->loadForRead($this->app->guestAcl(), $postId, null)['board'];
    }

    private function renderPost(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $postId,
        array $values,
        array $errors
    ): ResponseInterface {
        $acl = $this->app->guestAcl();
        $loaded = $this->app->postService()->loadForRead($acl, $postId, null);

        return Twig::fromRequest($request)->render($response, 'posts/show.html.twig', [
            'post' => $this->app->postService()->get($acl, $postId, null),
            'board' => $this->app->boardService()->get($acl, (string) $loaded['board']['board_key']),
            'comments' => $this->app->commentService()->listComments($acl, $postId, null),
            'can_comment' => $acl->canComment($loaded['board']),
            'comment_errors' => $errors,
            'comment_values' => $values,
        ]);
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
