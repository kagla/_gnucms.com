<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Oauth\SocialProfile;

final class LinkingService
{
    private Connection $db;
    private UserRepository $users;
    private IdentityRepository $identities;

    public function __construct(Connection $db, UserRepository $users, IdentityRepository $identities)
    {
        $this->db = $db;
        $this->users = $users;
        $this->identities = $identities;
    }

    public function resolve(SocialProfile $profile): ?array
    {
        $this->assertProfile($profile);
        $linked = $this->identities->findUser($profile->provider, $profile->uid);
        if ($linked !== null) {
            return $this->assertActive($linked);
        }
        if ($profile->email === null || !$profile->emailVerified
            || filter_var($profile->email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($profile->email) > 191) {
            return null;
        }

        return $this->connect($profile, $profile->email);
    }

    public function completeVerifiedEmail(SocialProfile $profile, string $email): array
    {
        $this->assertProfile($profile);
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
            throw DomainError::validation(['email' => '올바른 이메일 주소를 입력해 주세요.']);
        }
        $linked = $this->identities->findUser($profile->provider, $profile->uid);
        return $linked === null ? $this->connect($profile, $email) : $this->assertActive($linked);
    }

    private function connect(SocialProfile $profile, string $email): array
    {
        return $this->db->transaction(function () use ($profile, $email): array {
            $user = $this->users->findByEmail($email);
            if ($user === null) {
                $id = $this->users->createSocial($email, $profile->name);
                $user = $this->users->findById($id);
            } elseif (!(bool) $user['email_verified']) {
                $this->users->verifyEmail((int) $user['id']);
                $user = $this->users->findById((int) $user['id']);
            }
            $this->assertActive($user);
            $this->identities->attach((int) $user['id'], $profile->provider, $profile->uid);

            return $this->publicUser($user);
        });
    }

    private function assertProfile(SocialProfile $profile): void
    {
        if ($profile->provider === '' || $profile->uid === '' || mb_strlen($profile->uid) > 191) {
            throw DomainError::internal('소셜 로그인 프로필이 올바르지 않습니다.');
        }
    }

    private function assertActive(array $user): array
    {
        if (($user['status'] ?? '') !== 'active') {
            throw DomainError::forbidden('사용할 수 없는 계정입니다.');
        }
        return $this->publicUser($user);
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'display_name' => (string) $user['display_name'],
            'is_admin' => (bool) $user['is_admin'],
            'email_verified' => (bool) $user['email_verified'],
            'session_epoch' => (int) $user['session_epoch'],
        ];
    }
}
