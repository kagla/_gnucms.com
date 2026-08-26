<?php

declare(strict_types=1);

namespace StandardBoard;

use StandardBoard\Auth\Acl;
use StandardBoard\Auth\TokenIssuer;
use StandardBoard\Auth\TokenVerifier;
use StandardBoard\Db\Connection;
use StandardBoard\Http\Request;
use StandardBoard\Http\Router;
use StandardBoard\Repository\BoardRepository;
use StandardBoard\Repository\CommentRepository;
use StandardBoard\Repository\PostRepository;
use StandardBoard\Service\AttachmentService;
use StandardBoard\Service\AuthService;
use StandardBoard\Service\BoardService;
use StandardBoard\Service\CommentService;
use StandardBoard\Service\PostService;

/**
 * 설정으로부터 객체 그래프를 조립한다. 컨테이너 라이브러리를 쓰지 않는 이유는
 * 객체 수가 열 개 남짓이고 런타임 의존성을 0 으로 유지해야 하기 때문이다.
 */
final class App
{
    /** @var array */
    private $config;

    /** @var Connection|null */
    private $db = null;

    /** @var BoardRepository|null */
    private $boards = null;

    /** @var PostRepository|null */
    private $posts = null;

    /** @var CommentRepository|null */
    private $comments = null;

    /** @var AuthService|null */
    private $auth = null;

    /** @var BoardService|null */
    private $boardService = null;

    /** @var PostService|null */
    private $postService = null;

    /** @var CommentService|null */
    private $commentService = null;

    /** @var AttachmentService|null */
    private $attachmentService = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** 점 표기 경로로 설정을 읽는다. 예: config('auth.secret') */
    public function config(string $path, $default = null)
    {
        $node = $this->config;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public function db(): Connection
    {
        if ($this->db === null) {
            $this->db = Connection::create((array) $this->config('db', []));
        }

        return $this->db;
    }

    public function boards(): BoardRepository
    {
        if ($this->boards === null) {
            $this->boards = new BoardRepository($this->db());
        }

        return $this->boards;
    }

    public function posts(): PostRepository
    {
        if ($this->posts === null) {
            $this->posts = new PostRepository($this->db());
        }

        return $this->posts;
    }

    public function comments(): CommentRepository
    {
        if ($this->comments === null) {
            $this->comments = new CommentRepository($this->db());
        }

        return $this->comments;
    }

    public function tokenIssuer(): TokenIssuer
    {
        return new TokenIssuer(
            (string) $this->config('auth.secret', ''),
            (int) $this->config('auth.ttl', 3600)
        );
    }

    public function auth(): AuthService
    {
        if ($this->auth === null) {
            $bootstrap = $this->config('bootstrap_admin');
            $this->auth = new AuthService(is_array($bootstrap) ? $bootstrap : null, $this->tokenIssuer());
        }

        return $this->auth;
    }

    public function boardService(): BoardService
    {
        if ($this->boardService === null) {
            $this->boardService = new BoardService($this->db(), $this->boards(), $this->posts(), $this->comments());
        }

        return $this->boardService;
    }

    public function postService(): PostService
    {
        if ($this->postService === null) {
            $this->postService = new PostService($this->boardService(), $this->posts());
            // attachments() 가 다시 postService() 를 부르므로 여기서 호출하면 무한 재귀가 된다.
            // 첨부가 필요한 시점에 attachments() 가 setAttachmentService() 로 연결한다.
        }

        return $this->postService;
    }

    public function attachments(): AttachmentService
    {
        if ($this->attachmentService === null) {
            $this->attachmentService = new AttachmentService(
                $this->boardService(),
                $this->postService(),
                $this->posts(),
                (array) $this->config('uploads', []),
                (string) $this->config('auth.secret', '')
            );
            $this->postService()->setAttachmentService($this->attachmentService);
        }

        return $this->attachmentService;
    }

    public function commentService(): CommentService
    {
        if ($this->commentService === null) {
            $this->commentService = new CommentService($this->postService(), $this->posts(), $this->comments());
        }

        return $this->commentService;
    }

    public function aclFor(Request $request): Acl
    {
        $verifier = new TokenVerifier(
            (string) $this->config('auth.secret', ''),
            (int) $this->config('auth.leeway', 60)
        );

        return new Acl($verifier->verify($request->bearerToken()));
    }

    public function router(): Router
    {
        $router = new Router();
        Routes::register($router, $this);

        return $router;
    }
}
