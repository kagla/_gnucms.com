<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Cms\CmsService;
use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Oauth\SocialProfile;

final class LinkingService
{
    private Connection $db;
    private UserRepository $users;
    private IdentityRepository $identities;
    private CmsService $cms;
    private ConsentRepository $consents;

    public function __construct(
        Connection $db,
        UserRepository $users,
        IdentityRepository $identities,
        CmsService $cms,
        ConsentRepository $consents
    ) {
        $this->db = $db;
        $this->users = $users;
        $this->identities = $identities;
        $this->cms = $cms;
        $this->consents = $consents;
    }

    public function resolve(SocialProfile $profile, ?ConsentTrace $trace = null): ?array
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

        return $this->connect($profile, $profile->email, $trace);
    }

    public function completeVerifiedEmail(SocialProfile $profile, string $email, ?ConsentTrace $trace = null): array
    {
        $this->assertProfile($profile);
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
            throw DomainError::validation(['email' => '올바른 이메일 주소를 입력해 주세요.']);
        }
        $linked = $this->identities->findUser($profile->provider, $profile->uid);
        return $linked === null ? $this->connect($profile, $email, $trace) : $this->assertActive($linked);
    }

    private function connect(SocialProfile $profile, string $email, ?ConsentTrace $trace): array
    {
        return $this->db->transaction(function () use ($profile, $email, $trace): array {
            $user = $this->users->findByEmail($email);
            if ($user === null) {
                $id = $this->users->createSocial($email, $profile->name);
                $user = $this->users->findById($id);
                // 소셜로 처음 가입하는 사람. 여기서 동의를 남기지 않으면 기록이 아예 없다.
                $this->recordConsents($user, $trace);
            } elseif (!(bool) $user['email_verified']) {
                $this->users->verifyEmail((int) $user['id']);
                $user = $this->users->findById((int) $user['id']);
            }
            $this->assertActive($user);
            $this->identities->attach((int) $user['id'], $profile->provider, $profile->uid);

            return $this->publicUser($user);
        });
    }

    /**
     * 소셜 가입은 폼이 없어 체크박스를 받을 수 없다. 로그인 화면의 소셜 단추 옆에
     * "계속하면 동의로 봅니다" 를 적고, 필수만 동의로 본다. 물어본 적 없는 선택
     * 항목을 동의로 볼 수는 없으니 안 함으로 남긴다.
     */
    private function recordConsents(array $user, ?ConsentTrace $trace): void
    {
        if ((bool) $user['is_admin']) {
            return;
        }
        foreach ($this->cms->consentDocuments('signup') as $doc) {
            $agreed = (int) $doc['required'] === 1;
            $this->consents->record('user', (int) $user['id'], 'signup', $doc, $agreed, $trace);
        }
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
