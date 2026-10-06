<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Aligo\AligoService;
use GnuCms\Aligo\PhoneNumber;
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
    private ?AligoService $aligo = null;

    public function __construct(Connection $db, UserRepository $users, BoardService $boards)
    {
        $this->db = $db;
        $this->users = $users;
        $this->boards = $boards;
    }

    /**
     * 예약 발송을 멈출 수 있는 곳. 차단이 쓴다 — 차단은 번호를 지우지도 않으므로,
     * 아무것도 하지 않으면 차단된 사람의 전화기가 며칠 뒤에 울린다
     * (AligoService::cancelScheduledForUser()). AccountService::setAligo() 와 같은
     * 이유로 세터이고, 끼우지 않으면 취소만 일어나지 않을 뿐 차단은 그대로 된다.
     */
    public function setAligo(AligoService $aligo): void
    {
        $this->aligo = $aligo;
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
        if ($user['status'] === 'withdrawn') {
            throw DomainError::validation(['member' => '탈퇴한 회원은 다시 활성화하거나 수정할 수 없습니다.']);
        }
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
        // PhoneNumber::normalize() 는 Validator 가 아니라 DomainError 를 직접 던진다.
        // AccountService::updateProfile() 과 같은 이유로 $v->check() 뒤에 본다.
        $phone = $this->phoneFromAdminInput($input, isset($user['phone']) ? (string) $user['phone'] : null);
        $buyerName = array_key_exists('buyer_name', $input) ? BuyerProfile::name($input['buyer_name']) : null;
        $this->users->updateForAdmin($id, $email, $displayName, $status);
        if (array_key_exists('buyer_name', $input)) $this->users->updateBuyerName($id, $buyerName);
        if ($status === 'blocked' && $user['status'] !== 'blocked') {
            // 막 차단된 사람이다. 걸려 있던 예약을 멈춘다 — 차단은 번호를 지우지
            // 않으므로 그대로 두면 며칠 뒤에 그 번호로 나간다. 상태가 바뀔 때만
            // 부른다: 이미 차단된 회원의 다른 칸(이름·메일)을 고칠 때마다 알리고를
            // 부를 이유는 없다.
            $this->aligo?->stopScheduledForUser($id, '차단된');
        }
        if ($phone['write']) {
            $this->users->updatePhone($id, $phone['phone']);
        }
        if ($password !== '') {
            // 비밀번호가 바뀌면 다른 기기의 세션은 끊긴다(session_epoch 증가).
            $this->users->updatePassword($id, password_hash($password, PASSWORD_DEFAULT));
        }
    }

    public function toggleStatus(Acl $acl, int $id): void
    {
        $acl->assertGlobalAdmin();
        $user = $this->requiredUser($id);
        if ($user['status'] === 'withdrawn') {
            throw DomainError::validation(['member' => '탈퇴한 회원의 상태는 변경할 수 없습니다.']);
        }
        if ((string) $acl->identity()->sub() === (string) $id) {
            throw DomainError::validation(['member' => '현재 로그인한 관리자 계정은 차단할 수 없습니다.']);
        }
        if ($user['status'] === 'active' && (bool) $user['is_admin'] && $this->users->countAdmins() <= 1) {
            throw DomainError::validation(['member' => '마지막 관리자는 차단할 수 없습니다.']);
        }
        $blocking = $user['status'] === 'active';
        $this->users->setStatus($id, $blocking ? 'blocked' : 'active');
        if ($blocking) {
            // updateMember() 와 같은 이유, 같은 자리(상태를 바꾼 직후). 차단을 푸는
            // 쪽은 멈출 것이 없다.
            $this->aligo?->stopScheduledForUser($id, '차단된');
        }
    }

    private function requiredUser(int $id): array
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            throw DomainError::notFound('회원을 찾을 수 없습니다.');
        }
        return $user;
    }

    /**
     * 관리자 회원 수정은 signup_phone 정책을 보지 않는다 — 그 정책은 "가입 화면이
     * 무엇을 물을지"를 정하는 것이지 "관리자가 무엇을 관리할 수 있는지"를 정하는
     * 것이 아니다. 정책이 off 여도(심지어 required 여도) 관리자는 항상 번호를
     * 넣고 지울 수 있어야 한다. 회원정보 화면도 번호를 입력할 수 있지만, 필수 정책의
     * 삭제 제한을 관리자는 적용받지 않는다.
     * 그래서 여기는 AccountService::phoneForEdit() 의 세 값 분기를 쓰지 않는다 —
     * "입력이 있으면 정규화, 없으면 null" 뿐이다. 이건 세 값 분기를 복사한 게
     * 아니라 그 분기가 아예 없는 쪽이다.
     *
     * 다만 "빈 칸을 보냈다"와 "칸 자체를 안 보냈다"는 구분한다. 빈 칸은 지우라는
     * 뜻이지만, 칸이 없는 제출(손으로 만든 POST, 나중에 생길 부분 수정 폼, 번호
     * 칸을 빼먹은 다른 테마)까지 지우기로 읽으면 관리자 저장 한 번이 통째로 번호를
     * 날린다. 안 보낸 칸은 건드리지 않는다.
     *
     * @return array{write: bool, phone: ?string}
     */
    private function phoneFromAdminInput(array $input, ?string $stored): array
    {
        if (!array_key_exists('phone', $input)) {
            return ['write' => false, 'phone' => null];
        }
        $given = is_scalar($input['phone']) ? trim((string) $input['phone']) : '';

        return [
            'write' => true,
            // 저장된 번호를 그대로 다시 보낸 경우에는 형식을 다시 따지지 않는다 —
            // 휴대폰 형식이 아닌 예전 번호가 들어 있으면, 그 번호를 고치려는
            // 관리자까지 이 화면에서 아무것도 저장할 수 없게 되기 때문이다.
            'phone' => $given === '' ? null : PhoneNumber::normalizeEdit($given, $stored),
        ];
    }
}
