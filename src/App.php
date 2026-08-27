<?php

declare(strict_types=1);

namespace ApiBoard;

use ApiBoard\Auth\Acl;
use ApiBoard\Auth\Identity;
use ApiBoard\Db\Connection;
use ApiBoard\Repository\BoardRepository;
use ApiBoard\Repository\CommentRepository;
use ApiBoard\Repository\PostRepository;
use ApiBoard\Service\AttachmentService;
use ApiBoard\Service\BoardService;
use ApiBoard\Service\CommentService;
use ApiBoard\Service\PostService;

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

    /**
     * 1단계에는 로그인이 없다. 2단계에서 SessionGuard 가 이 자리를 대신한다.
     */
    public function guestAcl(): Acl
    {
        return new Acl(Identity::guest());
    }
}
