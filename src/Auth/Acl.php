<?php

declare(strict_types=1);

namespace GnuCms\Auth;

use GnuCms\Error\DomainError;

/**
 * 권한 판정의 단일 출처. 판정 순서는 다음과 같고 위에서부터 단락 평가한다.
 *
 *   1. 전역 관리자        -> 전부 허용
 *   2. 게시판 관리자      -> 그 게시판의 글/댓글에 한해 관리 권한
 *   3. 본인 (author_id)   -> 자기 글/댓글
 *   4. 비회원 본인 (비번) -> 3번과 같은 권한, 소유 증명 수단만 다르다
 *   5. 그 외              -> 게시판의 perm_* 설정
 */
final class Acl
{
    /** @var Identity */
    private $identity;

    /** 사이트 전체 비회원 글쓰기 스위치. 직접 만든 ACL 은 기존 호환을 위해 허용한다. */
    private bool $guestWriteEnabled = true;

    /** @var array<string,string> 현재 세션에서 비밀번호 확인을 마친 비밀글 지문 */
    private array $secretGrants = [];

    /** @var array<string,string> 현재 세션에서 소유를 확인한 비회원 댓글 지문 */
    private array $commentSecretGrants = [];

    /** @var array<string,string> 수정 모달에서 비밀번호를 확인한 비회원 댓글 지문 */
    private array $commentEditGrants = [];

    public function __construct(Identity $identity)
    {
        $this->identity = $identity;
    }

    public function identity(): Identity
    {
        return $this->identity;
    }

    public function isGlobalAdmin(): bool
    {
        return $this->identity->isAdmin();
    }

    public function setGuestWriteEnabled(bool $enabled): void
    {
        $this->guestWriteEnabled = $enabled;
    }

    /** @param array<string|int,mixed> $grants */
    public function setSecretGrants(array $grants): void
    {
        $this->secretGrants = [];
        foreach ($grants as $postId => $fingerprint) {
            if (ctype_digit((string) $postId) && is_string($fingerprint) && $fingerprint !== '') {
                $this->secretGrants[(string) $postId] = $fingerprint;
            }
        }
    }

    public static function secretGrantFor(array $post): string
    {
        return hash('sha256', (string) ($post['id'] ?? '') . '|' . (string) ($post['guest_password'] ?? ''));
    }

    /** @param array<string|int,mixed> $grants */
    public function setCommentSecretGrants(array $grants): void
    {
        $this->commentSecretGrants = [];
        foreach ($grants as $commentId => $fingerprint) {
            if (ctype_digit((string) $commentId) && is_string($fingerprint) && $fingerprint !== '') {
                $this->commentSecretGrants[(string) $commentId] = $fingerprint;
            }
        }
    }

    public static function commentSecretGrantFor(array $comment): string
    {
        return hash('sha256', 'comment|' . (string) ($comment['id'] ?? '') . '|'
            . (string) ($comment['guest_password'] ?? ''));
    }

    /** @param array<string|int,mixed> $grants */
    public function setCommentEditGrants(array $grants): void
    {
        $this->commentEditGrants = [];
        foreach ($grants as $commentId => $fingerprint) {
            if (ctype_digit((string) $commentId) && is_string($fingerprint) && $fingerprint !== '') {
                $this->commentEditGrants[(string) $commentId] = $fingerprint;
            }
        }
    }

    public function hasGuestCommentOwnership(array $comment): bool
    {
        if (($comment['author_id'] ?? null) !== null || ($comment['guest_password'] ?? null) === null) {
            return false;
        }
        $grant = $this->commentSecretGrants[(string) ($comment['id'] ?? '')] ?? null;

        return is_string($grant) && hash_equals(self::commentSecretGrantFor($comment), $grant);
    }

    public function canEditComment(array $board, array $comment): bool
    {
        if ($this->isAdminFor($board)) {
            return true;
        }
        if (($comment['author_id'] ?? null) !== null) {
            $sub = $this->identity->sub();

            return $sub !== null && hash_equals((string) $comment['author_id'], $sub);
        }

        $grant = $this->commentEditGrants[(string) ($comment['id'] ?? '')] ?? null;

        return ($comment['guest_password'] ?? null) !== null && is_string($grant)
            && hash_equals(self::commentSecretGrantFor($comment), $grant);
    }

    public function canViewSecretComment(array $board, array $post, array $comment, ?array $parent = null): bool
    {
        if (!(bool) ($comment['is_secret'] ?? false) || $this->isAdminFor($board)) {
            return true;
        }

        $sub = $this->identity->sub();
        if ($sub !== null) {
            if (($comment['author_id'] ?? null) !== null
                && hash_equals((string) $comment['author_id'], $sub)) {
                return true;
            }
            if (($post['author_id'] ?? null) !== null && hash_equals((string) $post['author_id'], $sub)) {
                return true;
            }
            if ($parent !== null && ($parent['author_id'] ?? null) !== null
                && hash_equals((string) $parent['author_id'], $sub)) {
                return true;
            }
        }

        $postGrant = $this->secretGrants[(string) ($post['id'] ?? '')] ?? null;
        if (($post['author_id'] ?? null) === null && is_string($postGrant)
            && hash_equals(self::secretGrantFor($post), $postGrant)) {
            return true;
        }

        if ($parent !== null && $this->hasGuestCommentOwnership($parent)) {
            return true;
        }

        return $this->hasGuestCommentOwnership($comment);
    }

    /**
     * 비회원 비밀 댓글은 댓글 작성 비밀번호와 비회원 원글 비밀번호 중 하나로 연다.
     * 반환값은 어느 소유권을 세션에 기억해야 하는지 나타낸다.
     */
    public function verifySecretComment(
        array $board,
        array $post,
        array $comment,
        string $password,
        ?array $parent = null
    ): string
    {
        if ($this->canViewSecretComment($board, $post, $comment, $parent)) {
            return 'already';
        }

        $key = 'secret-comment:' . ($comment['id'] ?? 0);
        if ($this->throttle !== null && $password !== '') {
            $this->throttle->assertNotLocked($key);
        }

        $commentHash = $comment['guest_password'] ?? null;
        if (is_string($commentHash) && $commentHash !== '' && password_verify($password, $commentHash)) {
            if ($this->throttle !== null) {
                $this->throttle->clear($key);
            }
            return 'comment';
        }

        $postHash = $post['guest_password'] ?? null;
        if (is_string($postHash) && $postHash !== '' && password_verify($password, $postHash)) {
            if ($this->throttle !== null) {
                $this->throttle->clear($key);
            }
            return 'post';
        }

        $parentHash = $parent['guest_password'] ?? null;
        if (is_string($parentHash) && $parentHash !== '' && password_verify($password, $parentHash)) {
            if ($this->throttle !== null) {
                $this->throttle->clear($key);
            }
            return 'parent';
        }

        if ($password === '') {
            throw DomainError::validation(['password' => '비밀번호를 입력해 주세요.']);
        }
        $message = $this->throttle === null
            ? '비밀번호가 올바르지 않습니다.'
            : $this->throttle->recordFailureMessage($key, '비밀번호가 올바르지 않습니다.');
        throw DomainError::validation(['password' => $message]);
    }

    public function isBoardManager(array $board): bool
    {
        $sub = $this->identity->sub();
        if ($sub === null) {
            return false;
        }

        $managers = isset($board['managers']) && is_array($board['managers']) ? $board['managers'] : [];

        return in_array($sub, $managers, true);
    }

    public function isAdminFor(array $board): bool
    {
        return $this->isGlobalAdmin() || $this->isBoardManager($board);
    }

    public function canRead(array $board): bool
    {
        return $this->allows($board, (string) $board['perm_read']);
    }

    public function canWrite(array $board): bool
    {
        if ($this->identity->isGuest() && !$this->guestWriteEnabled) {
            return false;
        }

        return $this->allows($board, (string) $board['perm_write']);
    }

    public function canComment(array $board): bool
    {
        return $this->allows($board, (string) $board['perm_comment']);
    }

    public function canCommentOnPost(array $board, array $post): bool
    {
        if (!$this->canComment($board)) {
            return false;
        }
        if (!(bool) ($post['is_secret'] ?? false)) {
            return true;
        }
        if ($this->isAdminFor($board)) {
            return true;
        }

        $authorId = $post['author_id'] ?? null;
        if ($authorId !== null) {
            $sub = $this->identity->sub();

            return $sub !== null && hash_equals((string) $authorId, $sub);
        }

        $grant = $this->secretGrants[(string) ($post['id'] ?? '')] ?? null;

        return is_string($grant) && hash_equals(self::secretGrantFor($post), $grant);
    }

    /**
     * @param array $resource guest_password 를 포함한 글/댓글 행
     */
    public function owns(array $resource, ?string $password): bool
    {
        $authorId = $resource['author_id'] ?? null;

        if ($authorId !== null) {
            $sub = $this->identity->sub();

            return $sub !== null && hash_equals((string) $authorId, $sub);
        }

        $hash = $resource['guest_password'] ?? null;
        if ($hash === null || $password === null || $password === '') {
            return false;
        }

        return password_verify($password, (string) $hash);
    }

    public function canModify(array $board, array $resource, ?string $password): bool
    {
        return $this->isAdminFor($board) || $this->owns($resource, $password);
    }

    public function assertGlobalAdmin(): void
    {
        $this->deny($this->isGlobalAdmin(), '전역 관리자만 할 수 있습니다.');
    }

    public function assertAdminFor(array $board): void
    {
        $this->deny($this->isAdminFor($board), '이 게시판의 관리자만 할 수 있습니다.');
    }

    public function assertCanRead(array $board): void
    {
        $this->deny($this->canRead($board), '이 게시판을 읽을 권한이 없습니다.');
    }

    public function assertCanWrite(array $board): void
    {
        $this->deny($this->canWrite($board), '이 게시판에 글을 쓸 권한이 없습니다.');
    }

    public function assertCanComment(array $board): void
    {
        $this->deny($this->canComment($board), '이 게시판에 댓글을 쓸 권한이 없습니다.');
    }

    public function assertCanCommentOnPost(array $board, array $post): void
    {
        $this->deny(
            $this->canCommentOnPost($board, $post),
            (bool) ($post['is_secret'] ?? false)
                ? '비밀글에는 글 작성자와 관리자만 댓글을 쓸 수 있습니다.'
                : '이 게시판에 댓글을 쓸 권한이 없습니다.'
        );
    }

    /** 비밀번호 대입 방어. App::guestAcl() 이 주입한다. 없으면(단위 테스트 등) 검사만 없다. */
    private ?PasswordThrottle $throttle = null;

    public function setPasswordThrottle(PasswordThrottle $throttle): void
    {
        $this->throttle = $throttle;
    }

    /**
     * 비밀글 열람 판정. canModify 와 같지만 비밀번호 대입을 잠근다.
     * 잠긴 동안은 맞는 비밀번호도 검사하지 않는다.
     */
    public function verifySecret(array $board, array $post, ?string $password): bool
    {
        $grant = $this->secretGrants[(string) ($post['id'] ?? '')] ?? null;
        if (is_string($grant) && hash_equals(self::secretGrantFor($post), $grant)) {
            return true;
        }
        $useThrottle = $this->throttle !== null && $password !== null && $password !== '';
        $key = 'secret:' . ($post['id'] ?? 0);
        if ($useThrottle) {
            $this->throttle->assertNotLocked($key);
        }

        $allowed = $this->canModify($board, $post, $password);
        if ($useThrottle) {
            // 관리자 단락 평가로 통과했을 뿐 비밀번호를 검증한 게 아니면(아무 문자열이나
            // 넘어와도 canModify 가 true 다) 대입 기록을 지우지 않는다. 실제로 비밀번호로
            // 소유를 증명했을 때만 지운다. 그 외 진짜로 틀린 경우만 기록한다.
            if ($this->owns($post, $password)) {
                $this->throttle->clear($key);
            } elseif (!$allowed) {
                $message = $this->throttle->recordFailureMessage($key, '비밀번호가 올바르지 않습니다.');
            }
        }

        if (!$allowed && isset($message)) {
            throw DomainError::validation(['password' => $message]);
        }

        return $allowed;
    }

    public function assertCanModify(array $board, array $resource, ?string $password): void
    {
        $guestOwned = ($resource['author_id'] ?? null) === null && ($resource['guest_password'] ?? null) !== null;
        if ($guestOwned && isset($resource['post_id']) && $this->canEditComment($board, $resource)
            && ($password === null || $password === '')) {
            return;
        }
        // 댓글 행에는 post_id 가 있다. 글과 댓글이 같은 잠금 열쇠를 나누지 않게 가른다.
        $key = 'modify:' . (isset($resource['post_id']) ? 'comment' : 'post') . ':' . ($resource['id'] ?? 0);
        $useThrottle = $guestOwned && $this->throttle !== null && $password !== null && $password !== '';
        if ($useThrottle) {
            // 잠긴 동안은 맞는 비밀번호도 검사하지 않는다. 대입이 성공 시점을 알 수 없게.
            $this->throttle->assertNotLocked($key);
        }

        if ($this->canModify($board, $resource, $password)) {
            // 관리자 단락 평가로 통과했을 뿐 비밀번호를 검증한 게 아니면(아무 문자열이나
            // 넘어와도 canModify 가 true 다) 대입 기록을 지우지 않는다. 실제로 비밀번호로
            // 소유를 증명했을 때만 지운다.
            if ($useThrottle && $this->owns($resource, $password)) {
                $this->throttle->clear($key);
            }

            return;
        }
        if ($useThrottle) {
            $message = $this->throttle->recordFailureMessage($key, '비밀번호가 올바르지 않습니다.');
        }
        // 비회원 글·댓글은 비밀번호가 곧 소유 증명이다. 로그인하라는 안내는 엉뚱하므로
        // 비밀번호 칸에 붙는 검증 오류로 알려 준다. 회원 글은 기존대로 401/403 이다.
        if (($resource['author_id'] ?? null) === null && ($resource['guest_password'] ?? null) !== null) {
            throw DomainError::validation(['password' => $password === null || $password === ''
                ? '비밀번호를 입력해 주세요.'
                : ($message ?? '비밀번호가 올바르지 않습니다.')]);
        }
        $this->deny(false, '수정하거나 삭제할 권한이 없습니다.');
    }

    private function allows(array $board, string $level): bool
    {
        switch ($level) {
            case 'guest':
                return true;
            case 'member':
                return !$this->identity->isGuest() || $this->isGlobalAdmin();
            case 'admin':
                return $this->isAdminFor($board);
        }

        // 알 수 없는 값은 가장 안전한 쪽으로 해석한다.
        return false;
    }

    /**
     * 게스트에게는 401 을, 신원이 확인된 사용자에게는 403 을 준다.
     * 401 은 "로그인하면 될 수도 있다", 403 은 "로그인해도 안 된다" 는 뜻이다.
     */
    private function deny(bool $allowed, string $message): void
    {
        if ($allowed) {
            return;
        }

        throw $this->identity->isGuest()
            ? DomainError::unauthorized('로그인이 필요합니다.')
            : DomainError::forbidden($message);
    }
}
