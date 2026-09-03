<?php

declare(strict_types=1);

namespace GnuCms\Service;

use GnuCms\Account\UserRepository;
use GnuCms\Auth\Acl;
use GnuCms\Cms\ContentImageService;
use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Comment\TreeBuilder;
use GnuCms\Error\DomainError;
use GnuCms\Repository\CommentRepository;
use GnuCms\Repository\PostRepository;
use GnuCms\Validation\Validator;
use GnuCms\Support\IpAddress;
use GnuCms\Spam\WriteRateLimiter;

final class CommentService
{
    public const SECRET_PLACEHOLDER = '비밀 댓글입니다.';

    /** @var PostService */
    private $postService;

    /** @var PostRepository */
    private $postRepo;

    /** @var CommentRepository */
    private $comments;

    /** @var HtmlSanitizer */
    private $sanitizer;

    /** @var ContentImageService */
    private $images;

    /** @var NotificationService|null */
    private $notifications;

    private ?UserRepository $users = null;

    private ?BoardService $boards = null;

    private ?WriteRateLimiter $writeRateLimiter = null;

    public function __construct(
        PostService $postService,
        PostRepository $postRepo,
        CommentRepository $comments,
        HtmlSanitizer $sanitizer,
        ContentImageService $images,
        ?NotificationService $notifications = null
    ) {
        $this->postService = $postService;
        $this->postRepo = $postRepo;
        $this->comments = $comments;
        $this->sanitizer = $sanitizer;
        $this->images = $images;
        $this->notifications = $notifications;
    }

    /** 댓글 본문도 편집기 HTML 이다. 저장과 출력 두 곳에서 정화한다. */
    private function cleanContent(string $raw): string
    {
        return $this->sanitizer->clean($raw);
    }

    /** 댓글 최소 글자수(태그·공백 제외). 0 = 제한 없음. App 이 사이트 설정에서 넣는다. */
    private int $contentMinChars = 0;

    public function setContentMinChars(int $min): void
    {
        $this->contentMinChars = max(0, $min);
    }

    public function setUserRepository(UserRepository $users): void
    {
        $this->users = $users;
    }

    public function setBoardService(BoardService $boards): void
    {
        $this->boards = $boards;
    }

    public function setWriteRateLimiter(WriteRateLimiter $limiter): void
    {
        $this->writeRateLimiter = $limiter;
    }

    /** 편집기가 감싼 태그와 공백으로 길이를 속일 수 없게 글자만 센다. */
    private function assertContentLongEnough(Validator $v, string $content): void
    {
        if ($this->contentMinChars <= 0) {
            return;
        }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if (mb_strlen($text) < $this->contentMinChars) {
            $v->fail('content', '댓글은 ' . $this->contentMinChars . '자 이상 적어 주세요.');
        }
    }

    /**
     * 편집기는 빈 입력에도 <p><br></p> 같은 껍데기를 남겨 "필수" 검사를 통과한다.
     * 태그·공백을 빼고도 글자가 없으면 빈 내용으로 본다. 사진만 있는 내용은 허용한다.
     */
    private function assertContentNotEmpty(Validator $v, string $content): void
    {
        if (stripos($content, '<img') !== false) {
            return;
        }
        $text = trim(preg_replace('/\s+/u', '', html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($text === '') {
            $v->fail('content', '내용을 입력해 주세요.');
        }
    }

    /** 편집기가 올린 이미지를 묶는 폴더 이름. */
    private function editorImageKey(Validator $v, array $input): ?string
    {
        if (!array_key_exists('image_key', $input)) {
            return null;
        }
        $key = strtolower((string) $v->optionalString('image_key', 32));
        if ($key === '') {
            return null;
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
            $v->fail('image_key', '이미지 저장 정보를 확인할 수 없습니다.');

            return null;
        }

        return $key;
    }

    public function listComments(Acl $acl, int $postId, ?string $password): array
    {
        $loaded = $this->postService->loadForRead($acl, $postId, $password);

        // 비회원 소유권 지문을 확인하려면 해시가 필요하다. TreeBuilder 가 화면용 필드만
        // 골라 내므로 guest_password 자체는 템플릿으로 전달되지 않는다.
        $rows = $this->comments->findByPostWithSecret($postId);
        $rows = $this->maskSecrets($rows, $acl, $loaded['post'], $loaded['board']);
        foreach ($rows as $index => $row) {
            $rows[$index]['can_edit'] = $acl->canEditComment($loaded['board'], $row);
            $rows[$index]['needs_edit_password'] = $row['author_id'] === null
                && ($row['guest_password'] ?? null) !== null
                && !$rows[$index]['can_edit'];
            $rows[$index]['author_ip_masked'] = $row['author_id'] === null
                ? IpAddress::mask($row['author_ip'] ?? null) : null;
            unset($rows[$index]['author_ip']);
        }

        return TreeBuilder::build($rows);
    }

    public function create(Acl $acl, int $postId, array $input, ?string $clientIp = null): array
    {
        $loaded = $this->postService->loadForRead($acl, $postId, $input['post_password'] ?? null);
        $post = $loaded['post'];
        $board = $loaded['board'];

        $acl->assertCanCommentOnPost($board, $post);

        if ($post['deleted_at'] !== null) {
            throw DomainError::forbidden('삭제된 글에는 댓글을 쓸 수 없습니다.');
        }

        $v = new Validator($input);
        $data = [
            'board_id' => (int) $post['board_id'],
            'post_id'  => $postId,
            'content'  => $this->cleanContent($v->requiredString('content')),
        ];
        $this->assertContentNotEmpty($v, (string) $data['content']);
        $this->assertContentLongEnough($v, (string) $data['content']);
        $data['image_key'] = $this->editorImageKey($v, $input);

        $parentId = $v->int('parent_id', 0, 0, PHP_INT_MAX);
        if ($parentId > 0) {
            $parent = $this->comments->find($parentId);
            if ($parent === null || (int) $parent['post_id'] !== $postId) {
                $v->fail('parent_id', '이 글의 댓글이 아닙니다.');
            }
            $data['parent_id'] = $parentId;
        } else {
            $data['parent_id'] = null;
        }

        $identity = $acl->identity();
        if ($identity->isGuest()) {
            $data['author_id'] = null;
            $data['author_name'] = $v->requiredString('author_name', 20);
            $data['author_ip'] = IpAddress::normalize($clientIp);
            $password = $v->requiredPassword('password');
            $data['guest_password'] = $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);
        } else {
            $data['author_id'] = $identity->sub();
            $data['author_name'] = (string) $identity->displayName();
            $data['author_ip'] = null;
            $data['guest_password'] = null;
        }

        $secret = $v->bool('is_secret', false);
        if ($identity->isGuest() && $secret) {
            $v->fail('is_secret', '비회원은 댓글과 답글을 비밀로 작성할 수 없습니다.');
        }
        $data['is_secret'] = $secret ? 1 : 0;

        $v->check();

        if ($this->writeRateLimiter !== null) {
            $this->writeRateLimiter->consume('comment', $acl, $clientIp);
        }

        $id = $this->comments->create($data);
        $this->postRepo->adjustCommentCount($postId, 1);
        if ($data['image_key'] !== null) {
            $this->images->sync((string) $data['image_key'], (string) $data['content']);
        }
        if ($this->notifications !== null) {
            $this->notifications->notifyComment($postId, $id);
        }

        return $this->present($this->comments->find($id));
    }

    public function guestOwnershipGrant(int $id): ?string
    {
        $comment = $this->comments->findWithSecret($id);
        if ($comment === null || $comment['author_id'] !== null || ($comment['guest_password'] ?? null) === null) {
            return null;
        }

        return Acl::commentSecretGrantFor($comment);
    }

    /** @return array{comment_id:int,post_id:int,grant:string} */
    public function verifyGuestOwnership(Acl $acl, int $id, string $password): array
    {
        $comment = $this->comments->findWithSecret($id);
        if ($comment === null || $comment['deleted_at'] !== null) {
            throw DomainError::notFound('댓글을 찾을 수 없습니다.');
        }
        if ($comment['author_id'] !== null || ($comment['guest_password'] ?? null) === null) {
            throw DomainError::forbidden('회원 댓글은 작성한 계정으로 로그인해야 수정할 수 있습니다.');
        }
        $board = $this->boardOf($comment);
        $acl->assertCanModify($board, $comment, $password);

        return [
            'comment_id' => (int) $comment['id'],
            'post_id' => (int) $comment['post_id'],
            'grant' => Acl::commentSecretGrantFor($comment),
        ];
    }

    /** @return array{comment_id:int,post_id:int,board_key:string}|null */
    public function secretChallenge(Acl $acl, int $id): ?array
    {
        $comment = $this->comments->findWithSecret($id);
        if ($comment === null || $comment['deleted_at'] !== null || !(bool) $comment['is_secret']) {
            throw DomainError::notFound('댓글을 찾을 수 없습니다.');
        }
        $loaded = $this->postService->loadForRead($acl, (int) $comment['post_id'], null);
        $parent = $comment['parent_id'] === null ? null : $this->comments->findWithSecret((int) $comment['parent_id']);
        if ($acl->canViewSecretComment($loaded['board'], $loaded['post'], $comment, $parent)) {
            return null;
        }
        if (($comment['guest_password'] ?? null) === null && ($loaded['post']['guest_password'] ?? null) === null
            && ($parent['guest_password'] ?? null) === null) {
            throw DomainError::forbidden('댓글 작성자와 원글 작성자만 볼 수 있는 댓글입니다.');
        }

        return [
            'comment_id' => (int) $comment['id'],
            'post_id' => (int) $comment['post_id'],
            'board_key' => (string) $loaded['board']['board_key'],
        ];
    }

    /** @return array{kind:string,grant:string,grant_comment_id:int,comment_id:int,post_id:int} */
    public function unlockSecret(Acl $acl, int $id, string $password): array
    {
        $comment = $this->comments->findWithSecret($id);
        if ($comment === null || $comment['deleted_at'] !== null || !(bool) $comment['is_secret']) {
            throw DomainError::notFound('댓글을 찾을 수 없습니다.');
        }
        $loaded = $this->postService->loadForRead($acl, (int) $comment['post_id'], null);
        $parent = $comment['parent_id'] === null ? null : $this->comments->findWithSecret((int) $comment['parent_id']);
        $kind = $acl->verifySecretComment($loaded['board'], $loaded['post'], $comment, $password, $parent);
        $grantResource = $kind === 'parent' && $parent !== null ? $parent : $comment;
        $grant = $kind === 'post'
            ? Acl::secretGrantFor($loaded['post'])
            : Acl::commentSecretGrantFor($grantResource);

        return [
            'kind' => $kind,
            'grant' => $grant,
            'grant_comment_id' => (int) $grantResource['id'],
            'comment_id' => (int) $comment['id'],
            'post_id' => (int) $comment['post_id'],
        ];
    }

    public function update(Acl $acl, int $id, array $input): array
    {
        $comment = $this->comments->findWithSecret($id);
        if ($comment === null || $comment['deleted_at'] !== null) {
            throw DomainError::notFound('댓글을 찾을 수 없습니다.');
        }
        $board = $this->boardOf($comment);

        $v = new Validator($input);
        $password = $v->optionalPassword('password');
        $acl->assertCanModify($board, $comment, $password);

        $data = ['content' => $this->cleanContent($v->requiredString('content'))];
        if (array_key_exists('is_secret', $input)) {
            $secret = $v->bool('is_secret', false);
            if ($comment['author_id'] === null && $secret) {
                $v->fail('is_secret', '비회원은 댓글과 답글을 비밀로 작성할 수 없습니다.');
            }
            $data['is_secret'] = $secret ? 1 : 0;
        }
        $imageKey = $this->editorImageKey($v, $input);
        if ($imageKey !== null) {
            $data['image_key'] = $imageKey;
        }
        $v->check();

        $this->comments->update($id, $data);
        // 고치면서 뺀 사진은 더 둘 이유가 없다.
        if ($imageKey !== null) {
            $this->images->sync($imageKey, (string) $data['content']);
        }

        return $this->present($this->comments->find($id));
    }

    public function delete(Acl $acl, int $id, ?string $password): void
    {
        $comment = $this->comments->findWithSecret($id);
        if ($comment === null || $comment['deleted_at'] !== null) {
            throw DomainError::notFound('댓글을 찾을 수 없습니다.');
        }
        $board = $this->boardOf($comment);

        $acl->assertCanModify($board, $comment, $password);

        $this->comments->softDelete($id);
        $this->postRepo->adjustCommentCount((int) $comment['post_id'], -1);
    }

    /** 전체 댓글 또는 한 회원이 남긴 댓글 목록. 글 제목을 함께 붙인다. */
    public function listByAuthor(Acl $acl, array $query): array
    {
        $v = new Validator($query);
        $page = $v->int('page', 1, 1, 100000);
        $author = $v->int('author', 0, 0, PHP_INT_MAX);
        $v->check();
        $perPage = 20;
        $all = !array_key_exists('author', $query);

        $user = !$all && $author > 0 && $this->users !== null ? $this->users->findById($author) : null;
        // 차단된 회원은 없는 회원과 같게 다룬다. 다른 곳도 모두 status === 'active' 를 요구한다.
        if ($user !== null && $user['status'] !== 'active') {
            $user = null;
        }
        $empty = [
            'data' => [], 'page' => $page, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 0,
            'author' => null, 'author_name' => null, 'is_all' => $all,
        ];
        if (!$all && $user === null) {
            return $empty;
        }

        // boards 도 users 처럼 나중에 주입되는 선택 의존이다. 없으면 읽을 수 있는
        // 게시판이 없는 것으로 보고 빈 결과를 낸다 — 직접 만든 서비스가 죽지 않게.
        if ($this->boards === null) {
            return $empty;
        }

        $boardIds = [];
        foreach ($this->boards->listBoards($acl) as $board) {
            $boardIds[] = (int) $board['id'];
        }

        $result = $all
            ? $this->comments->paginateAll($boardIds, $page, $perPage)
            : $this->comments->paginateByAuthor($author, $boardIds, $page, $perPage);

        // 페이지당 최대 20건이라 한 건씩 낱개로 읽는다.
        $titles = [];
        $postIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['post_id'], $result['rows'])));
        foreach ($postIds as $postId) {
            $post = $this->postRepo->findWithSecret($postId);
            if ($post !== null) {
                $titles[$postId] = (string) $post['title'];
            }
        }

        $rows = [];
        foreach ($result['rows'] as $row) {
            $secret = (bool) $row['is_secret'];
            $rows[] = [
                'id'         => (int) $row['id'],
                'post_id'    => (int) $row['post_id'],
                'post_title' => $titles[(int) $row['post_id']] ?? '(지워진 글)',
                'author_name' => (string) $row['author_name'],
                // 비밀 댓글의 본문은 목록에 흘리지 않는다.
                'excerpt'    => $secret ? '비밀 댓글' : $this->plainExcerpt((string) $row['content'], 80),
                'is_secret'  => $secret,
                'created_at' => $row['created_at'],
            ];
        }

        return [
            'data' => $rows,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $result['total'],
            'total_pages' => $result['total'] === 0 ? 0 : (int) ceil($result['total'] / $perPage),
            'author' => $all ? null : $author,
            'author_name' => $all ? null : (string) $user['display_name'],
            'is_all' => $all,
        ];
    }

    /**
     * 목록에 보일 한 줄. 태그를 걷고 길면 자른다.
     *
     * 사진만 있는 댓글은 태그를 걷으면 글자가 하나도 남지 않는다. 그 자리를 비워 두면
     * 무엇이 있었는지 알 수 없는 빈 줄이 되므로 무엇인지 적어 준다.
     */
    private function plainExcerpt(string $html, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($text === '') {
            return stripos($html, '<img') !== false ? '사진' : '내용 없음';
        }

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }

    private function boardOf(array $comment): array
    {
        $board = $this->postService->boardById((int) $comment['board_id']);
        if ($board === null) {
            throw DomainError::notFound('게시판을 찾을 수 없습니다.');
        }

        return $board;
    }

    /**
     * 비밀 댓글의 내용을 트리 조립 전에 가린다. 트리를 만든 뒤 순회하는 것보다
     * 단순하고, 구조(누가 누구에게 달았는지)는 그대로 남는다.
     */
    /**
     * 수정 화면에 쓸 댓글 하나를 내준다.
     *
     * 글을 읽을 수 있어야 하고, 비밀 댓글이면 목록과 같은 기준으로 가려진다.
     * 가려진 댓글은 내용을 보여 줄 수 없으므로 아예 없는 것으로 다룬다.
     */
    public function getForEdit(Acl $acl, int $id): array
    {
        $comment = $this->comments->findWithSecret($id);
        if ($comment === null || $comment['deleted_at'] !== null) {
            throw DomainError::notFound('댓글을 찾을 수 없습니다.');
        }

        $loaded = $this->postService->loadForRead($acl, (int) $comment['post_id'], null);
        if (!$acl->canEditComment($loaded['board'], $comment)) {
            throw DomainError::notFound('댓글을 찾을 수 없습니다.');
        }

        // 편집기가 올린 사진을 이어서 관리하려면 수정 화면도 같은 키를 알아야 한다.
        return $this->present($comment) + ['image_key' => $comment['image_key']];
    }

    private function maskSecrets(array $rows, Acl $acl, array $post, array $board): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        foreach ($rows as $index => $row) {
            if ((int) $row['is_secret'] !== 1) {
                continue;
            }
            $parent = $row['parent_id'] === null ? null : ($byId[(int) $row['parent_id']] ?? null);
            if ($acl->canViewSecretComment($board, $post, $row, $parent)) {
                continue;
            }
            $rows[$index]['content'] = self::SECRET_PLACEHOLDER;
            $rows[$index]['secret_masked'] = true;
            $rows[$index]['secret_unlockable'] = ($row['guest_password'] ?? null) !== null
                || ($post['guest_password'] ?? null) !== null
                || ($parent['guest_password'] ?? null) !== null;
        }

        return $rows;
    }

    private function present(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'post_id'     => (int) $row['post_id'],
            'parent_id'   => $row['parent_id'],
            'depth'       => (int) $row['depth'],
            'content'     => $row['content'],
            'author_id'   => $row['author_id'],
            'author_name' => $row['author_name'],
            'author_avatar_file' => $row['author_avatar_file'] ?? null,
            'author_ip_masked' => $row['author_id'] === null ? IpAddress::mask($row['author_ip'] ?? null) : null,
            'is_secret'   => (bool) $row['is_secret'],
            'deleted'     => $row['deleted_at'] !== null,
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ];
    }
}
