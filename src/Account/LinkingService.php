<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Cms\CmsService;
use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Notify\Notifier;
use GnuCms\Notify\Recipient;
use GnuCms\Notify\UnwiredNotifier;
use GnuCms\Oauth\SocialProfile;

final class LinkingService
{
    private Connection $db;
    private UserRepository $users;
    private IdentityRepository $identities;
    private CmsService $cms;
    private ConsentRepository $consents;
    private ?AvatarService $avatars;
    private ?Notifier $notifier = null;

    /** AccountService::setNotifier() 와 같은 이유의 같은 배선이다. */
    public function setNotifier(Notifier $notifier): void
    {
        $this->notifier = $notifier;
    }

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
        // 이 메서드는 두 가지 일을 한다: 없던 회원을 만드는 일과, 이미 있는 회원에게
        // 공급자를 하나 더 붙이는 일. 가입 완료 안내는 앞쪽에만 해당한다.
        $created = false;
        $user = $this->db->transaction(function () use ($profile, $email, $trace, &$created): array {
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
                $created = true;
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
        if ($created) {
            $this->sendWelcome($user);
        }
        return $this->publicUser($user);
    }

    /**
     * 소셜로 **처음 가입한** 사람에게 보내는 가입 완료 안내.
     *
     * createSocial() 은 그 자리에서 인증까지 끝내므로 이 사람은
     * AccountService::verifyEmail() 도 register() 도 지나지 않는다. 그 자리들에만 알림을
     * 두면 관리자가 welcome 을 켰을 때 비밀번호 가입자에게만 가고 소셜 가입자에게는
     * 가지 않는데, 화면 어디에도 그 사실이 드러나지 않는다.
     *
     * 보내는 자리는 트랜잭션이 닫힌 **뒤**다 — 알림 한 통이 열린 트랜잭션 안에서 바깥
     * 서비스(SMTP·알리고)의 응답을 기다리게 두지 않는다. 실패를 삼키는 것도
     * AccountService::sendWelcome() 과 같은 이유다: 여기 닿았다는 것은 회원이 이미
     * 만들어졌다는 뜻이고, 안내 한 통 때문에 로그인 자체가 실패하면 안 된다.
     */
    private function sendWelcome(array $user): void
    {
        try {
            $to = Recipient::forUser($user);
            $vars = [
                '사이트명' => (string) $this->cms->settings()['site_name'],
                '이름' => (string) $user['display_name'],
            ];
            // 이 서비스는 메일러를 쥐고 있지 않다. 발송기가 없으면 보낼 길이 없고,
            // UnwiredNotifier 가 그 사실을 운영자 로그에 한 줄 남긴다.
            $this->notifier !== null
                ? $this->notifier->notify('welcome', $to, $vars)
                : (new UnwiredNotifier(null, self::class))->notify('welcome', $to, $vars);
        } catch (\Throwable $e) {
            error_log('[' . GNUCMS_ID . '] 가입 완료 안내 실패: ' . $e->getMessage());
        }
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
