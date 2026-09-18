<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Cms\CmsService;
use GnuCms\Error\DomainError;
use GnuCms\Mail\MailerInterface;
use GnuCms\Notify\Notifier;
use GnuCms\Notify\Recipient;
use GnuCms\Notify\UnwiredNotifier;
use GnuCms\Oauth\ProviderRegistry;
use GnuCms\Oauth\SocialProfile;

final class SocialAuthService
{
    private ProviderRegistry $providers;
    private LinkingService $linking;
    private MailerInterface $mailer;
    private string $appUrl;
    private ?CmsService $cms;
    private ?Notifier $notifier = null;

    /** AccountService::setNotifier() 와 같은 이유의 같은 배선이다. */
    public function setNotifier(Notifier $notifier): void
    {
        $this->notifier = $notifier;
    }

    public function __construct(ProviderRegistry $providers, LinkingService $linking, MailerInterface $mailer,
        string $appUrl, ?CmsService $cms = null)
    {
        $this->providers = $providers;
        $this->linking = $linking;
        $this->mailer = $mailer;
        $this->appUrl = rtrim($appUrl, '/');
        $this->cms = $cms;
    }

    public function profile(string $provider, string $code, string $state = ''): SocialProfile
    {
        if ($code === '') {
            throw DomainError::validation(['code' => '소셜 로그인이 취소되었거나 인증 코드가 없습니다.']);
        }
        $profile = $this->providers->get($provider)->fetchProfile($code, $state);
        if ($profile->provider !== $provider) {
            throw DomainError::internal('소셜 로그인 공급자 정보가 일치하지 않습니다.');
        }
        return $profile;
    }

    public function resolve(SocialProfile $profile, ?ConsentTrace $trace = null): ?array
    {
        return $this->linking->resolve($profile, $trace);
    }

    public function sendPendingEmail(SocialProfile $profile, string $email, string $token): string
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
            throw DomainError::validation(['email' => '올바른 이메일 주소를 입력해 주세요.']);
        }
        $url = $this->appUrl . '/auth/complete?token=' . rawurlencode($token);
        // 아직 회원 행이 없다. 받는 사람은 방금 적어 낸 이 주소 하나로만 확인된다 —
        // 이 알림이 전화 채널을 쓸 수 없는(Events 의 phone=false) 이유이기도 하다.
        $sent = $this->notify('social_email_verify', Recipient::forEmail($email, $profile->name), [
            '사이트명' => $this->siteName(),
            '링크' => $url,
            '유효시간' => '30분',
        ]);
        if (!$sent) {
            // 한 통도 안 나갔으면 이 사람은 링크를 받을 수 없고, 소셜 로그인을 끝낼 수
            // 없다. 여기서 조용히 돌아가면 부르는 쪽은 "메일함을 확인해 주세요" 화면을
            // 그리고, 그 사람은 오지 않을 메일을 기다린다. 대신 거절해서 주소를 적던
            // 화면에 그대로 머무르게 한다 — 여기엔 아직 계정이 없어 숨길 것도 없다.
            throw DomainError::validation(['email' =>
                '지금은 확인 메일을 보낼 수 없습니다. 사이트 관리자에게 문의해 주세요.']);
        }

        return $email;
    }

    /** AccountService::notify() 와 같은 이유의 같은 코드다 — 그쪽 주석이 이 둘을 설명한다. */
    private function notify(string $event, Recipient $to, array $vars): bool
    {
        return $this->notifier !== null
            ? $this->notifier->notify($event, $to, $vars)
            : (new UnwiredNotifier($this->mailer, self::class))->notify($event, $to, $vars);
    }

    /** 메일에 쓰는 이름은 관리자가 설정한 홈페이지 제목(site_name)을 따른다. */
    private function siteName(): string
    {
        return $this->cms === null ? GNUCMS : (string) $this->cms->settings()['site_name'];
    }

    public function complete(SocialProfile $profile, string $email, ?ConsentTrace $trace = null): array
    {
        return $this->linking->completeVerifiedEmail($profile, $email, $trace);
    }
}
