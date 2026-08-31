<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use GnuCms\View\View;

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
            $comment = $this->app->commentService()->create($acl, $postId, $input);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }

            // 검증 실패는 글 화면을 다시 그리면서 입력값과 오류를 함께 보여 준다.
            return $this->renderPost($request, $response->withStatus(422), $postId, $input, $e->details());
        }

        $grant = $this->app->commentService()->guestOwnershipGrant((int) $comment['id']);
        if ($grant !== null) {
            $_SESSION['secret_comments'] = isset($_SESSION['secret_comments']) && is_array($_SESSION['secret_comments'])
                ? $_SESSION['secret_comments'] : [];
            $_SESSION['secret_comments'][(string) $comment['id']] = $grant;
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor('posts.show', ['id' => (string) $postId]);

        return $response->withHeader('Location', $url . '#comments')->withStatus(303);
    }

    // Task 4 에서 채운다. 지금은 글쓴이 모달의 링크가 가리킬 빈 자리만 만든다.
    public function byAuthor(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'posts/comments_by_author', [
            'list' => ['data' => [], 'page' => 1, 'total' => 0, 'total_pages' => 0],
            'author' => null,
        ]);
    }

    public function passwordForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $challenge = $this->app->commentService()->secretChallenge($this->app->guestAcl(), $id);
        if ($challenge === null) {
            return $this->backToComment($request, $response, $this->postIdOf($id), $id);
        }

        return $this->renderPassword($request, $response, $challenge, []);
    }

    public function unlockSecret(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $this->assertCsrf($input);
        $challenge = $this->app->commentService()->secretChallenge($this->app->guestAcl(), $id);
        if ($challenge === null) {
            return $this->backToComment($request, $response, $this->postIdOf($id), $id);
        }

        $password = isset($input['password']) && is_scalar($input['password']) ? (string) $input['password'] : '';
        try {
            $unlocked = $this->app->commentService()->unlockSecret($this->app->guestAcl(), $id, $password);
        } catch (DomainError $e) {
            if ($e->status() !== 422) {
                throw $e;
            }
            return $this->renderPassword(
                $request,
                $response->withStatus(422),
                $challenge,
                $e->details()
            );
        }

        if ($unlocked['kind'] === 'post') {
            $_SESSION['secret_posts'] = isset($_SESSION['secret_posts']) && is_array($_SESSION['secret_posts'])
                ? $_SESSION['secret_posts'] : [];
            $_SESSION['secret_posts'][(string) $unlocked['post_id']] = $unlocked['grant'];
        } elseif (in_array($unlocked['kind'], ['comment', 'parent'], true)) {
            $_SESSION['secret_comments'] = isset($_SESSION['secret_comments']) && is_array($_SESSION['secret_comments'])
                ? $_SESSION['secret_comments'] : [];
            $_SESSION['secret_comments'][(string) $unlocked['grant_comment_id']] = $unlocked['grant'];
        }

        return $this->backToComment($request, $response, $unlocked['post_id'], $id);
    }

    /** 비회원 댓글 수정 버튼의 비밀번호 확인 모달이 호출한다. */
    public function verifyOwnership(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        try {
            $this->assertCsrf($input);
            $password = isset($input['password']) && is_scalar($input['password'])
                ? (string) $input['password'] : '';
            $verified = $this->app->commentService()->verifyGuestOwnership(
                $this->app->guestAcl(),
                $id,
                $password
            );
        } catch (DomainError $e) {
            $details = $e->details();
            $message = isset($details['password']) ? (string) $details['password'] : $e->getMessage();

            return $this->json($response->withStatus($e->status()), [
                'ok' => false,
                'message' => $message,
            ]);
        }

        $_SESSION['comment_edits'] = isset($_SESSION['comment_edits']) && is_array($_SESSION['comment_edits'])
            ? $_SESSION['comment_edits'] : [];
        $_SESSION['comment_edits'][(string) $verified['comment_id']] = $verified['grant'];
        // 수정 비밀번호 확인은 댓글 작성자라는 더 강한 증명이므로 비밀 댓글 열람도 함께 연다.
        $_SESSION['secret_comments'] = isset($_SESSION['secret_comments']) && is_array($_SESSION['secret_comments'])
            ? $_SESSION['secret_comments'] : [];
        $_SESSION['secret_comments'][(string) $verified['comment_id']] = $verified['grant'];
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor(
            'posts.show',
            ['id' => (string) $verified['post_id']],
            ['edit_comment' => (string) $verified['comment_id']]
        );

        return $this->json($response, [
            'ok' => true,
            'redirect' => $url . '#comment-' . $verified['comment_id'],
        ]);
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

        return View::fromRequest($request)->render($response, 'posts/comment_edit', [
            'comment' => $comment,
            'post' => $this->app->postService()->get($acl, (int) $comment['post_id'], null),
            'board' => $this->app->boardService()->get($acl, (string) $loaded['board']['board_key']),
            'values' => $values,
            'errors' => $errors,
            // 비회원이 쓴 댓글은 비밀번호로 주인을 확인한다. 관리자는 물어보지 않는다.
            'needs_password' => $comment['author_id'] === null && !$acl->canEditComment($loaded['board'], $comment),
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

    private function renderPassword(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $challenge,
        array $errors
    ): ResponseInterface {
        return View::fromRequest($request)->render($response, 'posts/comment_password', [
            'comment_id' => $challenge['comment_id'],
            'post_id' => $challenge['post_id'],
            'board' => $this->app->boardService()->get(
                $this->app->guestAcl(),
                (string) $challenge['board_key']
            ),
            'errors' => $errors,
        ]);
    }

    private function postIdOf(int $commentId): int
    {
        $challenge = $this->app->commentService()->secretChallenge($this->app->guestAcl(), $commentId);
        if ($challenge !== null) {
            return (int) $challenge['post_id'];
        }

        // 이미 권한이 생긴 직후의 리디렉션에서만 이 분기로 온다. 수정용 조회는 권한이
        // 확인된 댓글의 post_id 를 안전하게 돌려준다.
        return (int) $this->app->commentService()->getForEdit($this->app->guestAcl(), $commentId)['post_id'];
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

        return View::fromRequest($request)->render($response, 'posts/show', [
            'post' => $this->app->postService()->get($acl, $postId, null),
            'board' => $this->app->boardService()->get($acl, (string) $loaded['board']['board_key']),
            'comments' => $this->app->commentService()->listComments($acl, $postId, null),
            'can_comment' => $acl->canCommentOnPost($loaded['board'], $loaded['post']),
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

    private function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
