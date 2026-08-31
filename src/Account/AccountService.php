<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Auth\Identity;
use GnuCms\Error\DomainError;
use GnuCms\Mail\MailerInterface;
use GnuCms\Support\Clock;
use GnuCms\Validation\Validator;
use GnuCms\Auth\PasswordThrottle;
use GnuCms\Cms\CmsService;

final class AccountService
{
    private UserRepository $users;
    private TokenService $tokens;
    private MailerInterface $mailer;
    private string $appUrl;
    private CmsService $cms;
    private ConsentRepository $consents;

    private ?PasswordThrottle $throttle = null;

    public function setPasswordThrottle(PasswordThrottle $throttle): void
    {
        $this->throttle = $throttle;
    }

    public function __construct(UserRepository $users, TokenService $tokens, MailerInterface $mailer, string $appUrl,
        CmsService $cms, ConsentRepository $consents)
    {
        $this->users = $users;
        $this->tokens = $tokens;
        $this->mailer = $mailer;
        $this->appUrl = rtrim($appUrl, '/');
        $this->cms = $cms;
        $this->consents = $consents;
    }

    public function register(array $input, ?ConsentTrace $trace = null): array
    {
        $v = new Validator($input);
        $email = strtolower($v->requiredString('email', 191));
        $password = $v->requiredPassword('password');
        $confirmation = isset($input['password_confirmation']) && is_scalar($input['password_confirmation'])
            ? (string) $input['password_confirmation'] : '';

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $v->fail('email', '올바른 이메일 주소를 입력해 주세요.');
        }
        if ($password !== $confirmation) {
            $v->fail('password_confirmation', '비밀번호가 일치하지 않습니다.');
        }

        // 첫 사람(사이트 소유자)은 약관을 만들기 전이라 동의를 받지 않는다.
        $consents = [];
        if ($this->users->countAll() > 0) {
            // 필수 두 개가 공개돼 있는지 먼저 확인한다. 없으면 가입 자체를 받지 않는다.
            $this->cms->legalDocuments();
            $consents = $this->cms->consentDocuments('signup');
            foreach ($consents as $doc) {
                // 선택 항목은 체크를 안 해도 가입을 막지 않는다. 대신 안 했다는 사실을 남긴다.
                if ((int) $doc['required'] === 1 && !$v->bool('agree_' . $doc['id'], false)) {
                    $v->fail('agree_' . $doc['id'], $doc['title'] . '에 동의해야 가입할 수 있습니다.');
                }
            }
        }
        $v->check();

        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            if (!(bool) $existing['email_verified']) {
                $this->sendVerification($existing);
            } else {
                $this->mailer->send($email, '[' . $this->siteName() . '] 가입 시도 안내',
                    "이미 가입된 계정입니다.\n\n로그인: {$this->appUrl}/login");
            }
            return $this->publicUser($existing, false);
        }

        $id = $this->users->createRegistered(
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $this->displayNameFromEmail($email)
        );

        $user = $this->users->findById($id);
        if (!(bool) $user['is_admin']) {
            foreach ($consents as $doc) {
                $agreed = (int) $doc['required'] === 1 || $v->bool('agree_' . $doc['id'], false);
                $this->consents->record('user', $id, 'signup', $doc, $agreed, $trace);
            }
        }
        if (!(bool) $user['email_verified']) {
            $this->sendVerification($user);
        }

        return $this->publicUser($user, true);
    }

    public function authenticate(array $input): array
    {
        $email = isset($input['email']) && is_scalar($input['email'])
            ? strtolower(trim((string) $input['email'])) : '';
        $password = isset($input['password']) && is_scalar($input['password'])
            ? (string) $input['password'] : '';
        // 계정별+IP 별 대입 방어. 잠긴 동안은 맞는 비밀번호도 검사하지 않는다.
        if ($this->throttle !== null && $email !== '') {
            $this->throttle->assertNotLocked('login:' . $email, 'email');
        }
        $user = $email === '' ? null : $this->users->findByEmail($email);

        if ($user === null || $user['status'] !== 'active' || $user['password_hash'] === null
            || !password_verify($password, (string) $user['password_hash'])) {
            if ($this->throttle !== null && $email !== '') {
                $this->throttle->recordFailure('login:' . $email);
            }
            throw DomainError::validation(['email' => '이메일 또는 비밀번호를 확인해 주세요.']);
        }
        if ($this->throttle !== null && $email !== '') {
            // 비밀번호까지 맞은 사람이다(미인증 분기 포함). 이전 실패는 잊는다.
            $this->throttle->clear('login:' . $email);
        }
        if (!(bool) $user['email_verified']) {
            // 비밀번호까지 맞은 사람이다. 화면이 '다시 보내기' 를 내줄 수 있게 따로 표시한다.
            throw DomainError::validation([
                'email' => '아직 이메일 인증이 끝나지 않았습니다.',
                'unverified' => '1',
            ]);
        }

        return $this->publicUser($user);
    }

    public function verifyEmail(string $token): void
    {
        $this->users->verifyEmail($this->tokens->consume($token, TokenService::VERIFY_EMAIL));
    }

    public function resendVerification(string $email): void
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));
        if ($user !== null && !(bool) $user['email_verified']) {
            $this->sendVerification($user);
        }
    }

    public function requestPasswordReset(string $email): void
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));
        if ($user === null || !(bool) $user['email_verified'] || $user['status'] !== 'active') {
            return;
        }
        $token = $this->tokens->issue((int) $user['id'], TokenService::RESET_PASSWORD);
        $url = $this->appUrl . '/reset-password?token=' . rawurlencode($token);
        $this->mailer->send((string) $user['email'], '[' . $this->siteName() . '] 비밀번호 재설정',
            "아래 링크에서 비밀번호를 다시 설정해 주세요.\n\n{$url}\n\n이 링크는 1시간 동안 유효합니다.");
    }

    public function resetPassword(array $input): void
    {
        $v = new Validator($input);
        $token = $v->requiredString('token', 200);
        $password = $v->requiredPassword('password');
        $confirmation = isset($input['password_confirmation']) && is_scalar($input['password_confirmation'])
            ? (string) $input['password_confirmation'] : '';
        if ($password !== $confirmation) {
            $v->fail('password_confirmation', '비밀번호가 일치하지 않습니다.');
        }
        $v->check();
        $userId = $this->tokens->consume($token, TokenService::RESET_PASSWORD);
        $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
        $this->notifyPasswordChanged($userId);
    }

    /**
     * 본인의 회원정보 수정. 표시 이름은 늘 받고, 비밀번호는 새 값을 적었을 때만 바꾼다.
     * 비밀번호를 바꾸려면 현재 비밀번호가 맞아야 한다 — 자리를 비운 사이 남이 바꾸지 못하게.
     */
    public function updateProfile(int $userId, array $input): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw DomainError::unauthorized('로그인이 필요합니다.');
        }
        $v = new Validator($input);
        $displayName = $v->requiredString('display_name', 100);
        if ($displayName !== '' && UserRepository::displayNameHasBadChars($displayName)) {
            $v->fail('display_name', '한글·영문·숫자만 쓸 수 있습니다. 공백과 기호는 안 됩니다.');
        } elseif ($displayName !== '' && UserRepository::displayNameTooShort($displayName)) {
            $v->fail('display_name', UserRepository::displayNameRule());
        }
        if ($displayName !== '' && $this->users->findByDisplayName($displayName, $userId) !== null) {
            $v->fail('display_name', '이미 쓰고 있는 이름입니다. 다른 이름을 골라 주세요.');
        }
        $password = isset($input['password']) && is_scalar($input['password']) ? (string) $input['password'] : '';
        if ($password !== '') {
            $current = isset($input['current_password']) && is_scalar($input['current_password'])
                ? (string) $input['current_password'] : '';
            $confirmation = isset($input['password_confirmation']) && is_scalar($input['password_confirmation'])
                ? (string) $input['password_confirmation'] : '';
            if ($user['password_hash'] === null) {
                $v->fail('current_password', '소셜 로그인으로 가입한 계정은 비밀번호를 쓰지 않습니다.');
            } elseif (!password_verify($current, (string) $user['password_hash'])) {
                $v->fail('current_password', '현재 비밀번호가 올바르지 않습니다.');
            }
            if (mb_strlen($password) < Validator::passwordMin()) {
                $v->fail('password', Validator::passwordMin() . '자 이상이어야 합니다.');
            }
            if ($password !== $confirmation) {
                $v->fail('password_confirmation', '비밀번호가 일치하지 않습니다.');
            }
        }
        $v->check();
        $this->users->updateDisplayName($userId, $displayName);
        if ($password !== '') {
            $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
        }
    }

    public function changePassword(int $userId, array $input): void
    {
        $v = new Validator($input);
        $current = isset($input['current_password']) && is_scalar($input['current_password'])
            ? (string) $input['current_password'] : '';
        $password = $v->requiredPassword('password');
        $confirmation = isset($input['password_confirmation']) && is_scalar($input['password_confirmation'])
            ? (string) $input['password_confirmation'] : '';
        $user = $this->users->findById($userId);
        if ($user === null || $user['password_hash'] === null
            || !password_verify($current, (string) $user['password_hash'])) {
            $v->fail('current_password', '현재 비밀번호가 올바르지 않습니다.');
        }
        if ($password !== $confirmation) {
            $v->fail('password_confirmation', '비밀번호가 일치하지 않습니다.');
        }
        $v->check();
        $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
    }

    /**
     * 비밀번호가 바뀌었다고 본인에게 알린다. 남이 바꿨다면 이 메일로 알아채고 되찾는다.
     * 메일이 실패해도 비밀번호 변경은 이미 끝난 일이라 막지 않는다. 실패 여부만 돌려준다.
     */
    public function notifyPasswordChanged(int $userId): bool
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return false;
        }
        try {
            $this->mailer->send((string) $user['email'], '[' . $this->siteName() . '] 비밀번호가 변경되었습니다',
                "회원님의 비밀번호가 방금 변경되었습니다.\n\n본인이 바꾼 것이 아니라면 아래에서 즉시 비밀번호를 다시 설정하세요.\n\n"
                . "{$this->appUrl}/forgot-password\n\n변경 시각: " . Clock::now() . ' (UTC)');
            return true;
        } catch (\Throwable $e) {
            error_log('[' . GNUCMS_ID . '] 비밀번호 변경 알림 메일 실패: ' . $e->getMessage());
            return false;
        }
    }

    public function identityForSession(int $id, int $epoch): Identity
    {
        $user = $this->users->findById($id);
        if ($user === null || $user['status'] !== 'active' || (int) $user['session_epoch'] !== $epoch) {
            return Identity::guest();
        }

        return Identity::user((string) $user['id'], (string) $user['display_name'], (bool) $user['is_admin']);
    }

    private function publicUser(array $user, bool $newlyCreated = false): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
            'is_admin' => (bool) $user['is_admin'],
            'email_verified' => (bool) $user['email_verified'],
            'session_epoch' => (int) $user['session_epoch'],
            'newly_created' => $newlyCreated,
        ];
    }

    private function sendVerification(array $user): void
    {
        $token = $this->tokens->issue((int) $user['id'], TokenService::VERIFY_EMAIL);
        $url = $this->appUrl . '/verify-email?token=' . rawurlencode($token);
        $siteName = $this->siteName();
        $this->mailer->send((string) $user['email'], '[' . $siteName . '] 이메일 인증',
            "{$siteName} 가입을 완료하려면 아래 링크를 열어 주세요.\n\n{$url}\n\n이 링크는 24시간 동안 유효합니다.");
    }

    /** 메일에 쓰는 이름은 관리자가 설정한 홈페이지 제목(site_name)을 따른다. */
    private function siteName(): string
    {
        return (string) $this->cms->settings()['site_name'];
    }

    private function displayNameFromEmail(string $email): string
    {
        $at = strpos($email, '@');
        $name = $at === false ? $email : substr($email, 0, $at);

        return mb_substr($name === '' ? '회원' : $name, 0, 100);
    }
}
