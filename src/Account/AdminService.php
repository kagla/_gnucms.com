<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Auth\Acl;
use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Service\BoardService;
use GnuCms\Validation\Validator;

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
            'SELECT COUNT(*) AS c FROM ' . $this->db->table('posts') . ' WHERE deleted_at IS NULL'
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
        if ($displayName !== '' && UserRepository::displayNameHasBadChars($displayName)) {
            $v->fail('display_name', '한글·영문·숫자만 쓸 수 있습니다. 공백과 기호는 안 됩니다.');
        } elseif ($displayName !== '' && UserRepository::displayNameTooShort($displayName)) {
            $v->fail('display_name', UserRepository::displayNameRule());
        }
        if ($displayName !== '' && $this->users->findByDisplayName($displayName, $id) !== null) {
            $v->fail('display_name', '이미 쓰고 있는 이름입니다. 다른 이름을 골라 주세요.');
        }
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
        // 새 비밀번호는 비워 두면 그대로다. 적었으면 두 칸이 같고 길이가 맞아야 한다.
        $password = isset($input['password']) && is_scalar($input['password']) ? (string) $input['password'] : '';
        $confirmation = isset($input['password_confirmation']) && is_scalar($input['password_confirmation'])
            ? (string) $input['password_confirmation'] : '';
        if ($password !== '') {
            if (mb_strlen($password) < Validator::passwordMin()) {
                $v->fail('password', Validator::passwordMin() . '자 이상이어야 합니다.');
            }
            if ($password !== $confirmation) {
                $v->fail('password_confirmation', '비밀번호가 일치하지 않습니다.');
            }
        }
        $v->check();
        $this->users->updateForAdmin($id, $email, $displayName, $status);
        if ($password !== '') {
            // 비밀번호가 바뀌면 다른 기기의 세션은 끊긴다(session_epoch 증가).
            $this->users->updatePassword($id, password_hash($password, PASSWORD_DEFAULT));
        }
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
