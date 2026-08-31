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
        return $this->allows($board, (string) $board['perm_write']);
    }

    public function canComment(array $board): bool
    {
        return $this->allows($board, (string) $board['perm_comment']);
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

    public function assertCanModify(array $board, array $resource, ?string $password): void
    {
        if ($this->canModify($board, $resource, $password)) {
            return;
        }
        // 비회원 글·댓글은 비밀번호가 곧 소유 증명이다. 로그인하라는 안내는 엉뚱하므로
        // 비밀번호 칸에 붙는 검증 오류로 알려 준다. 회원 글은 기존대로 401/403 이다.
        if (($resource['author_id'] ?? null) === null && ($resource['guest_password'] ?? null) !== null) {
            throw DomainError::validation(['password' => $password === null || $password === ''
                ? '비밀번호를 입력해 주세요.'
                : '비밀번호가 올바르지 않습니다.']);
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
