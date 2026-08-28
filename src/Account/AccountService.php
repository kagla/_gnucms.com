<?php

declare(strict_types=1);

namespace ApiBoard\Account;

use ApiBoard\Auth\Identity;
use ApiBoard\Error\DomainError;
use ApiBoard\Mail\MailerInterface;
use ApiBoard\Validation\Validator;
use ApiBoard\Cms\CmsService;

final class AccountService
{
    private const PASSWORD_MIN = 8;

    private UserRepository $users;
    private TokenService $tokens;
    private MailerInterface $mailer;
    private string $appUrl;
    private CmsService $cms;
    private ConsentRepository $consents;

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

    public function register(array $input): array
    {
        $v = new Validator($input);
        $email = strtolower($v->requiredString('email', 191));
        $password = $v->requiredPassword('password');
        $confirmation = isset($input['password_confirmation']) && is_scalar($input['password_confirmation'])
            ? (string) $input['password_confirmation'] : '';

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $v->fail('email', '올바른 이메일 주소를 입력해 주세요.');
        }
        if ($password !== '' && mb_strlen($password) < self::PASSWORD_MIN) {
            $v->fail('password', self::PASSWORD_MIN . '자 이상이어야 합니다.');
        }
        if ($password !== $confirmation) {
            $v->fail('password_confirmation', '비밀번호가 일치하지 않습니다.');
        }

        $legal = null;
        if ($this->users->countAll() > 0) {
            $legal = $this->cms->legalDocuments();
            if (!$v->bool('agree_terms', false)) {
                $v->fail('agree_terms', '이용약관에 동의해야 가입할 수 있습니다.');
            }
            if (!$v->bool('agree_privacy', false)) {
                $v->fail('agree_privacy', '개인정보 수집·이용에 동의해야 가입할 수 있습니다.');
            }
        }
        $v->check();

        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            if (!(bool) $existing['email_verified']) {
                $this->sendVerification($existing);
            } else {
                $this->mailer->send($email, '[gnucms.com] 가입 시도 안내',
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
        if (!(bool) $user['is_admin'] && $legal !== null) {
            $this->consents->record($id, 'terms', $legal['terms']);
            $this->consents->record($id, 'privacy', $legal['privacy']);
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
        $user = $email === '' ? null : $this->users->findByEmail($email);

        if ($user === null || $user['status'] !== 'active' || $user['password_hash'] === null
            || !password_verify($password, (string) $user['password_hash'])) {
            throw DomainError::validation(['email' => '이메일 또는 비밀번호를 확인해 주세요.']);
        }
        if (!(bool) $user['email_verified']) {
            throw DomainError::validation(['email' => '이메일 인증을 완료해 주세요.']);
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
        $this->mailer->send((string) $user['email'], '[gnucms.com] 비밀번호 재설정',
            "아래 링크에서 비밀번호를 다시 설정해 주세요.\n\n{$url}\n\n이 링크는 1시간 동안 유효합니다.");
    }

    public function resetPassword(array $input): void
    {
        $v = new Validator($input);
        $token = $v->requiredString('token', 200);
        $password = $v->requiredPassword('password');
        $confirmation = isset($input['password_confirmation']) && is_scalar($input['password_confirmation'])
            ? (string) $input['password_confirmation'] : '';
        if ($password !== '' && mb_strlen($password) < self::PASSWORD_MIN) {
            $v->fail('password', self::PASSWORD_MIN . '자 이상이어야 합니다.');
        }
        if ($password !== $confirmation) {
            $v->fail('password_confirmation', '비밀번호가 일치하지 않습니다.');
        }
        $v->check();
        $userId = $this->tokens->consume($token, TokenService::RESET_PASSWORD);
        $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
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
        if ($password !== '' && mb_strlen($password) < self::PASSWORD_MIN) {
            $v->fail('password', self::PASSWORD_MIN . '자 이상이어야 합니다.');
        }
        if ($password !== $confirmation) {
            $v->fail('password_confirmation', '비밀번호가 일치하지 않습니다.');
        }
        $v->check();
        $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
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
        $this->mailer->send((string) $user['email'], '[gnucms.com] 이메일 인증',
            "gnucms.com 가입을 완료하려면 아래 링크를 열어 주세요.\n\n{$url}\n\n이 링크는 24시간 동안 유효합니다.");
    }

    private function displayNameFromEmail(string $email): string
    {
        $at = strpos($email, '@');
        $name = $at === false ? $email : substr($email, 0, $at);

        return mb_substr($name === '' ? '회원' : $name, 0, 100);
    }
}
