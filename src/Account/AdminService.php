<?php

declare(strict_types=1);

namespace ApiBoard\Account;

use ApiBoard\Auth\Acl;
use ApiBoard\Db\Connection;
use ApiBoard\Error\DomainError;
use ApiBoard\Service\BoardService;
use ApiBoard\Validation\Validator;

final class AdminService
{
    private Connection $db;
    private UserRepository $users;
    private BoardService $boards;

    public function __construct(Connection $db, UserRepository $users, BoardService $boards)
    {
        $this->db = $db;
        $this->users = $users;
        $this->boards = $boards;
    }

    public function dashboard(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        $post = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->q('posts') . ' WHERE deleted_at IS NULL'
        );
        $boards = $this->boards->listBoards($acl);
        return [
            'boards' => $boards,
            'board_count' => count($boards),
            'post_count' => (int) ($post['c'] ?? 0),
            'user_count' => $this->users->countAll(),
        ];
    }

    public function board(Acl $acl, string $key): array
    {
        $acl->assertGlobalAdmin();
        return $this->boards->get($acl, $key);
    }

    public function boards(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        return $this->boards->listBoards($acl);
    }

    public function createBoard(Acl $acl, array $input): array
    {
        return $this->boards->create($acl, $input);
    }

    public function updateBoard(Acl $acl, string $key, array $input): array
    {
        return $this->boards->update($acl, $key, $input);
    }

    public function deleteBoard(Acl $acl, string $key): void
    {
        $this->boards->delete($acl, $key);
    }

    public function members(Acl $acl, string $query): array
    {
        $acl->assertGlobalAdmin();
        return $this->users->listForAdmin(trim($query));
    }

    public function member(Acl $acl, int $id): array
    {
        $acl->assertGlobalAdmin();
        return $this->requiredUser($id);
    }

    public function updateMember(Acl $acl, int $id, array $input): void
    {
        $acl->assertGlobalAdmin();
        $user = $this->requiredUser($id);
        $v = new Validator($input);
        $email = strtolower($v->requiredString('email', 191));
        $displayName = $v->requiredString('display_name', 100);
        $status = $v->inList('status', ['active', 'blocked'], 'active');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $v->fail('email', '올바른 이메일 주소를 입력해 주세요.');
        }
        $sameEmail = $email === '' ? null : $this->users->findByEmail($email);
        if ($sameEmail !== null && (int) $sameEmail['id'] !== $id) {
            $v->fail('email', '이미 사용 중인 이메일입니다.');
        }
        if ($status === 'blocked' && (string) $acl->identity()->sub() === (string) $id) {
            $v->fail('status', '현재 로그인한 관리자 계정은 차단할 수 없습니다.');
        } elseif ($status === 'blocked' && $user['status'] === 'active' && (bool) $user['is_admin']
            && $this->users->countAdmins() <= 1) {
            $v->fail('status', '마지막 관리자는 차단할 수 없습니다.');
        }
        $v->check();
        $this->users->updateForAdmin($id, $email, $displayName, $status);
    }

    public function toggleStatus(Acl $acl, int $id): void
    {
        $acl->assertGlobalAdmin();
        $user = $this->requiredUser($id);
        if ((string) $acl->identity()->sub() === (string) $id) {
            throw DomainError::validation(['member' => '현재 로그인한 관리자 계정은 차단할 수 없습니다.']);
        }
        if ($user['status'] === 'active' && (bool) $user['is_admin'] && $this->users->countAdmins() <= 1) {
            throw DomainError::validation(['member' => '마지막 관리자는 차단할 수 없습니다.']);
        }
        $this->users->setStatus($id, $user['status'] === 'active' ? 'blocked' : 'active');
    }

    private function requiredUser(int $id): array
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            throw DomainError::notFound('회원을 찾을 수 없습니다.');
        }
        return $user;
    }
}
