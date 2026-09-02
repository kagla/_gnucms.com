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
    private ?AvatarService $avatars;

    public function __construct(
        Connection $db,
        UserRepository $users,
        IdentityRepository $identities,
        CmsService $cms,
        ConsentRepository $consents,
        ?AvatarService $avatars = null
    ) {
        $this->db = $db;
        $this->users = $users;
        $this->identities = $identities;
        $this->cms = $cms;
        $this->consents = $consents;
        $this->avatars = $avatars;
    }

    public function resolve(SocialProfile $profile, ?ConsentTrace $trace = null): ?array
    {
        $this->assertProfile($profile);
        $linked = $this->identities->findUser($profile->provider, $profile->uid);
        if ($linked !== null) {
            $linked = $this->importSocialAvatar($linked, $profile);
            if (!UserRepository::isSocialPlaceholderEmail((string) $linked['email'])) {
                return $this->assertActive($linked);
            }
            if ($this->usableVerifiedEmail($profile)) {
                return $this->replacePlaceholderEmail($linked, (string) $profile->email);
            }
            if ($profile->email !== null) {
                return null;
            }
            throw $this->missingEmailError($profile);
        }
        if ($this->usableVerifiedEmail($profile)) {
            return $this->connect($profile, (string) $profile->email, $trace);
        }
        if ($profile->email === null) {
            throw $this->missingEmailError($profile);
        }
        // 주소는 왔지만 공급자가 검증하지 않았다. 사이트 확인 메일을 거친다.
        return null;
    }

    public function completeVerifiedEmail(SocialProfile $profile, string $email, ?ConsentTrace $trace = null): array
    {
        $this->assertProfile($profile);
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
            throw DomainError::validation(['email' => '올바른 이메일 주소를 입력해 주세요.']);
        }
        $linked = $this->identities->findUser($profile->provider, $profile->uid);
        if ($linked === null) {
            return $this->connect($profile, $email, $trace);
        }
        $linked = $this->importSocialAvatar($linked, $profile);
        return UserRepository::isSocialPlaceholderEmail((string) $linked['email'])
            ? $this->replacePlaceholderEmail($linked, $email)
            : $this->assertActive($linked);
    }

    private function usableVerifiedEmail(SocialProfile $profile): bool
    {
        return $profile->email !== null
            && $profile->emailVerified
            && filter_var($profile->email, FILTER_VALIDATE_EMAIL) !== false
            && mb_strlen($profile->email) <= 191;
    }

    private function connect(SocialProfile $profile, string $email, ?ConsentTrace $trace): array
    {
        $user = $this->db->transaction(function () use ($profile, $email, $trace): array {
            $user = $this->users->findByEmail($email);
            if ($user === null) {
                if (!$this->cms->settings()['social_registration_enabled']) {
                    throw DomainError::forbidden('현재 신규 소셜 회원가입을 받지 않습니다.');
                }
                if ($this->users->countAdmins() === 0) {
                    throw DomainError::forbidden('관리자 설치를 먼저 완료해 주세요.');
                }
                $id = $this->users->createSocial($email, $profile->name, $trace?->ip);
                $user = $this->users->findById($id);
                // 소셜로 처음 가입하는 사람. 여기서 동의를 남기지 않으면 기록이 아예 없다.
                $this->recordConsents($user, $trace);
            } elseif (!(bool) $user['email_verified']) {
                $this->users->verifyEmail((int) $user['id']);
                $user = $this->users->findById((int) $user['id']);
            }
            $this->assertActive($user);
            $this->identities->attach((int) $user['id'], $profile->provider, $profile->uid);
            return $user;
        });
        $user = $this->importSocialAvatar($user, $profile);
        return $this->publicUser($user);
    }

    private function replacePlaceholderEmail(array $linked, string $email): array
    {
        $this->assertActive($linked);
        $email = strtolower(trim($email));
        $owner = $this->users->findByEmail($email);
        if ($owner !== null && (int) $owner['id'] !== (int) $linked['id']) {
            throw DomainError::validation([
                'email' => '이미 다른 회원이 사용 중인 이메일입니다. 관리자에게 계정 연결을 문의해 주세요.',
            ]);
        }
        $this->users->replaceSocialPlaceholderEmail((int) $linked['id'], $email);
        $updated = $this->users->findById((int) $linked['id']);
        if ($updated === null) {
            throw DomainError::internal('소셜 회원 이메일을 갱신하지 못했습니다.');
        }
        return $this->publicUser($updated);
    }

    private function missingEmailError(SocialProfile $profile): DomainError
    {
        $labels = ['google' => 'Google', 'naver' => '네이버', 'kakao' => '카카오'];
        $label = $labels[$profile->provider] ?? '소셜 계정';
        return DomainError::validation([
            'email' => $label . '에서 이메일 주소를 제공받지 못했습니다. 이메일 제공에 동의한 뒤 다시 시도해 주세요.',
        ]);
    }

    private function importSocialAvatar(array $user, SocialProfile $profile): array
    {
        if ($this->avatars === null || !empty($user['avatar_file']) || $profile->imageUrl === null) return $user;
        $file = null;
        try {
            $file = $this->avatars->storeSocial($profile->provider, $profile->imageUrl);
            if ($file === null) return $user;
            $this->users->updateAvatar((int) $user['id'], $file, 'social');
            return $this->users->findById((int) $user['id']) ?? $user;
        } catch (\Throwable $e) {
            // 사진은 선택 정보다. 외부 이미지나 저장소 장애가 인증 자체를 막으면 안 된다.
            $this->avatars->delete($file);
            return $user;
        }
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
            'avatar_file' => $user['avatar_file'] ?? null,
        ];
    }
}
