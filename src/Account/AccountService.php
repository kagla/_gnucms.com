<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Aligo\AligoService;
use GnuCms\Aligo\PhoneNumber;
use GnuCms\Auth\Identity;
use GnuCms\Error\DomainError;
use GnuCms\Mail\MailerInterface;
use GnuCms\Notify\Notifier;
use GnuCms\Notify\Recipient;
use GnuCms\Notify\UnwiredNotifier;
use GnuCms\Support\Clock;
use GnuCms\Validation\Validator;
use GnuCms\Auth\PasswordThrottle;
use GnuCms\Cms\CmsService;

final class AccountService
{
    /** notifyPasswordChanged() 의 세 결과. 화면이 셋을 각각 다르게 말한다. */
    public const NOTICE_SENT = 'sent';
    /** 켠 채널이 없거나, 켠 채널이 전부 이 회원에게는 쓸 수 없었다. 사고가 아니라 설정이다. */
    public const NOTICE_OFF = 'off';
    public const NOTICE_FAILED = 'failed';

    /**
     * 화면이 보여 줄 수 있는 결과인지 확인한다. 이 값은 주소창(?notice=)을 거쳐 돌아오므로
     * 아무 문자열이나 들어올 수 있고, 화면은 이 셋 말고는 아무것도 말해서는 안 된다.
     */
    public static function noticeOrNull(mixed $value): ?string
    {
        return in_array($value, [self::NOTICE_SENT, self::NOTICE_OFF, self::NOTICE_FAILED], true)
            ? (string) $value : null;
    }

    private UserRepository $users;
    private TokenService $tokens;
    private MailerInterface $mailer;
    private string $appUrl;
    private CmsService $cms;
    private ConsentRepository $consents;

    private ?PasswordThrottle $throttle = null;
    private ?Notifier $notifier = null;
    private ?AligoService $aligo = null;

    public function setPasswordThrottle(PasswordThrottle $throttle): void
    {
        $this->throttle = $throttle;
    }

    /**
     * 알림 발송기. 생성자가 아니라 세터로 받는다 — 이 클래스는 App 말고도 여러 곳에서
     * 직접 조립되고(시험이 특히 그렇다), 그 자리마다 발송기 한 벌(설정 저장소 + 채널 넷
     * + 알리고 사본)을 만들게 하면 계정 조립이 알림 전체 배선을 끌고 들어온다.
     * setPasswordThrottle() 과 같은 이유의 같은 방식이다.
     */
    public function setNotifier(Notifier $notifier): void
    {
        $this->notifier = $notifier;
    }

    /**
     * 예약 발송을 멈출 수 있는 곳. 탈퇴가 쓴다 — 번호를 지우는 것만으로는 이미 걸린
     * 예약이 멈추지 않기 때문이다(AligoService::cancelScheduledForUser()).
     * setNotifier() 와 같은 이유로 세터다: 이 클래스는 App 말고도 여러 곳에서 조립되고,
     * 끼우지 않으면 취소만 일어나지 않을 뿐 탈퇴는 그대로 된다.
     */
    public function setAligo(AligoService $aligo): void
    {
        $this->aligo = $aligo;
    }

    /**
     * 인증 링크가 이 주소를 적어 낸 사람에게 닿을 수 있는가.
     *
     * **그 주소에 계정이 있는지는 보지 않는다.** 보는 순간 이 답이 "그 주소가 가입돼
     * 있는가"를 말하게 되고, 그것을 말하는 화면은 계정 목록이다. 대신 묻는 것은 "지금 켜
     * 둔 채널 가운데, **주소 하나로만 확인되는 사람**(번호도 회원 번호도 없는 사람)에게
     * 닿을 수 있는 것이 있는가"뿐이다. 갓 가입하는 사람이 정확히 그 모양이므로 이 답은
     * 가입 화면에서는 정확하고, 다시 보내기 화면에서는 모자란 쪽으로만 틀린다(번호가 있는
     * 회원이라면 더 닿을 수도 있다). 어느 쪽이든 어떤 주소를 넣어도 답이 같다.
     *
     * 가입 화면이 번호를 받는 사이트면 그 번호도 함께 준다 — 그 사람에게 실제로 생길
     * 모양이 그것이기 때문이다. 이 값 역시 본인이 방금 적어 낸 것이라 아무것도 흘리지 않는다.
     */
    public function canSendVerificationLink(string $email, ?string $phone = null): bool
    {
        return $this->canReach('email_verify',
            Recipient::forUser(['email' => $email, 'phone' => $phone]));
    }

    /**
     * 이 설정으로 비밀번호 재설정 링크를 **누구에게든** 보낼 수 있는가.
     *
     * 여기서는 갓 가입하는 사람의 모양을 쓸 수 없다. 재설정은 전화 채널로도 나갈 수 있고,
     * 번호를 가진 회원이라면 문자로 닿기 때문이다 — 주소만 있는 본으로 물으면 "못 보낸다"고
     * 잘못 답한다. 그렇다고 진짜 그 회원으로 물으면 그 답이 계정의 존재를 말한다. 그래서
     * **가장 잘 닿는 사람의 본**(주소·번호·회원 번호를 모두 갖춘 사람)으로 묻는다. 그러면
     * 이 질문은 수신자가 아니라 설정의 성질이 되고, 답은 누구에게나 같다.
     *
     * 남는 빈틈은 하나다: 문자만 켜 둔 사이트에서 번호가 없는 회원. 그 사람에게는 못 가는데
     * 화면은 보낼 수 있다고 말한다. 그 사람은 계정을 잃은 것이 아니라 불편할 뿐이고(가입은
     * 이미 끝났다), 그 사실은 requestPasswordReset() 이 운영자 로그에 남긴다. 화면이 그것을
     * 말하려면 수신자별로 답해야 하고, 그 순간 이 화면이 계정 목록이 된다.
     */
    public function canSendResetLink(): bool
    {
        return $this->canReach('password_reset', self::anyoneShape());
    }

    /**
     * 부르는 쪽이 건넨 본에 이 알림이 닿을 수 있는가. 공개 메서드 둘이 각자의 본을 들고
     * 이 자리로 온다 — 어떤 본으로 묻느냐가 화면이 무엇을 말해도 되는지를 정하므로,
     * 그 선택은 질문마다 이름을 붙여 위에 적어 둔다.
     */
    private function canReach(string $event, Recipient $to): bool
    {
        return $this->notifier !== null
            ? $this->notifier->canReach($event, $to)
            : $this->unwired()->canReach($event, $to);
    }

    /**
     * "누구에게든 닿을 수 있는가"를 물을 때 쓰는 본. 주소도 번호도 회원 번호도 갖춘,
     * 가장 잘 닿는 사람의 모양이다. 채널은 이 칸들이 **있는지 없는지**만 보므로 값 자체에는
     * 아무 뜻이 없다 — 진짜 회원을 찾지 않는다는 것이 이 본의 전부다.
     */
    private static function anyoneShape(): Recipient
    {
        return new Recipient('someone@example.invalid', '01000000000', '0', '');
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
        $existingUsers = $this->users->countAll();
        if ($existingUsers > 0) {
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

        // 번호를 받을지는 사이트 설정이 정한다. 본인확인은 하지 않고 형식만 본다.
        // Validator 가 모은 오류를 먼저 한꺼번에 던진 뒤에 본다 — Aligo\Settings::save()
        // 가 발신번호를 다루는 방식과 같다. 앞에 둘 경우, 이메일·비밀번호가 함께
        // 잘못됐을 때 번호 오류만 보이고 나머지는 다음 제출까지 묻힌다.
        $phone = $this->phoneFromInput($input);

        // 인증 링크가 이 사람에게 닿을 수 없으면 가입을 받지 않는다. "채널이 켜져
        // 있는가"로는 모자라다 — 알림함 하나만 켜 두면 켜져 있기는 한데 인증 링크는
        // 영영 아무 데도 가지 않는다(알림함은 comment_new 밖의 알림을 받지 않는다).
        // 그래서 켜져 있는지가 아니라 **갓 생길 이 사람에게 닿는지**를 묻는다.
        //
        // 받으면 어떻게 되는지가 이 판단의 전부다: 회원 행은 만들어지는데 인증 링크는
        // 아무 데도 안 가고, 이 코드베이스는 인증 전에는 로그인을 막으므로, 그 사람은
        // 자기 계정 밖에 갇힌 채 "메일함을 확인하세요"라는 화면만 본다. 문구만 바꿔서는
        // 그 자리를 벗어날 수 없다 — 그래서 문 앞에서 돌려보낸다. 남는 찌꺼기(미인증
        // 회원 행·동의 기록)도 없고, 관리자가 채널을 다시 켜면 그대로 가입할 수 있다.
        //
        // 첫 사람은 빼놓는다. 그 사람은 인증 없이 만들어지므로(createRegistered) 링크를
        // 기다리지 않고, 여기서 막으면 알림 설정을 고칠 관리자 자체가 생기지 못한다.
        if ($existingUsers > 0 && !$this->canSendVerificationLink($email, $phone)) {
            throw DomainError::serviceUnavailable(
                '지금은 회원가입을 받을 수 없습니다. 인증 링크를 보낼 수 없어 가입을 끝낼 수 없습니다.'
                . ' 사이트 관리자에게 문의해 주세요.');
        }

        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            if (!(bool) $existing['email_verified']) {
                $this->sendVerification($existing);
            } else {
                // 나갔는지는 화면에 옮기지 않는다 — 이 분기의 화면은 진짜 가입과 한
                // 글자도 달라서는 안 된다(달라지면 "이 주소가 가입돼 있는가"를 묻는
                // 도구가 된다). 이 알림이 못 나가도 그 사람은 이미 로그인할 수 있다.
                $this->notify('signup_attempt', Recipient::forUser($existing), [
                    '사이트명' => $this->siteName(),
                    '링크' => $this->appUrl . '/login',
                ]);
            }
            return $this->publicUser($existing, false);
        }

        $id = $this->users->createRegistered(
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $this->displayNameFromEmail($email),
            $trace?->ip,
            $phone
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
        } else {
            // 이미 인증된 채로 만들어진 사람(첫 관리자)은 인증 토큰을 쓸 일이 없다 —
            // 그 사람에게는 가입이 끝나는 순간이 여기뿐이라 환영 알림도 여기서 보낸다.
            // 인증을 거치는 사람은 verifyEmail() 이 보낸다. 가입 여부를 가르는 조건은
            // 바로 위 줄이 인증 메일을 보낼지 가르는 조건과 같은 것 하나뿐이다.
            $this->sendWelcome($user);
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
        // 이메일 형태가 아닌 값(공격자가 아무 문자열이나 넣은 것)은 기록하지 않는다 —
        // 그런 값마다 영구 행을 하나씩 만들면 표가 무한히 늘어난다. assertNotLocked·
        // recordFailure·clear 세 곳 모두 같은 조건으로 가른다.
        $useThrottle = $this->throttle !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        if ($useThrottle) {
            $this->throttle->assertNotLocked('login:' . $email, 'email');
        }
        $user = $email === '' ? null : $this->users->findByEmail($email);

        if ($user === null || $user['status'] !== 'active' || $user['password_hash'] === null
            || !password_verify($password, (string) $user['password_hash'])) {
            if ($useThrottle) {
                $message = $this->throttle->recordFailureMessage(
                    'login:' . $email,
                    '이메일 또는 비밀번호를 확인해 주세요.'
                );
            }
            throw DomainError::validation(['email' => $message ?? '이메일 또는 비밀번호를 확인해 주세요.']);
        }
        if ($useThrottle) {
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

    /**
     * 사람이 인증 링크를 실제로 눌러 토큰을 쓴 자리. 환영 알림이 여기 있는 이유는
     * 이 자리뿐이기 때문이다 — UserRepository::verifyEmail() 에 두면 설치(Installer),
     * 첫 관리자 생성(createRegistered() 의 열린 트랜잭션 안), 소셜 계정 연결
     * (LinkingService)에서도 같이 나간다.
     */
    public function verifyEmail(string $token): void
    {
        $userId = $this->tokens->consume($token, TokenService::VERIFY_EMAIL);
        $this->users->verifyEmail($userId);
        $user = $this->users->findById($userId);
        if ($user !== null) {
            $this->sendWelcome($user);
        }
    }

    public function resendVerification(string $email): void
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));
        if ($user !== null && !(bool) $user['email_verified']) {
            // 조용히. 이 화면은 없는 주소에도 같은 답을 내야 한다(notifyQuietly 주석).
            $this->sendVerification($user, true);
        }
    }

    /**
     * 비밀번호를 잊은 사람에게 재설정 링크를 보낸다.
     *
     * **로그인이 필요 없는 자리인데 돈이 든다.** 이 알림이 문자·알림톡으로도 나가게 된
     * 뒤로, 주소 하나만 아는 사람이 누르는 만큼 남의 전화기가 울리고 사이트 주인의 잔여
     * 건수가 줄어든다(예전에는 메일 한 통이라 아무도 세지 않았다). 그래서 **주소를
     * 찾아보기 전에** 횟수를 센다 — 세는 자리가 조회 뒤에 있으면 "있는 주소만 세는"
     * 셈이 되어, 잠겼는지 여부가 곧 계정이 있는지를 말하게 된다. 없는 주소로 눌러도
     * 똑같이 세고 똑같이 잠긴다.
     *
     * 열쇠는 둘이다: 주소마다(IP 를 섞지 않는다 — 번호를 울리는 쪽을 세야 하므로),
     * 그리고 IP 마다(주소 목록을 들고 온 사람을 세야 하므로). 둘 다 PasswordThrottle 의
     * 것이고, 한도와 문구는 그 클래스에 적혀 있다.
     */
    public function requestPasswordReset(string $email): void
    {
        $email = strtolower(trim($email));
        $this->countResetRequest($email);
        $user = $this->users->findByEmail($email);
        if ($user === null || !(bool) $user['email_verified'] || $user['status'] !== 'active') {
            return;
        }
        $token = $this->tokens->issue((int) $user['id'], TokenService::RESET_PASSWORD);
        $url = $this->appUrl . '/reset-password?token=' . rawurlencode($token);
        $sent = $this->notifyQuietly('password_reset', Recipient::forUser($user), [
            '사이트명' => $this->siteName(),
            '이름' => (string) $user['display_name'],
            '링크' => $url,
            '유효시간' => '1시간',
        ]);
        if (!$sent) {
            // 화면에는 이 사실을 옮기지 않는다. 이 메서드는 없는 계정에도 조용히 돌아가야
            // 하므로(그래야 화면이 계정 목록이 되지 않는다), "이 사람에게는 못 보냈다"를
            // 화면에 말하는 순간 계정이 있다는 뜻이 된다. 화면은 대신 canSendResetLink() 로
            // 누구에게나 같은 사실만 말한다. 운영자에게는 여기서 알린다.
            error_log('[' . GNUCMS_ID . '] 비밀번호 재설정 링크가 아무 데도 나가지 않았습니다'
                . ' — 회원 #' . (int) $user['id'] . ' 는 지금 비밀번호를 되찾을 수 없습니다.');
        }
    }

    /**
     * 재설정 요청 한 번을 두 열쇠에 센다. 잠겨 있으면 422 로 막고(그 문구는 폼의 이메일
     * 칸 밑에 붙는다), 아니면 세고 지나간다. 막힌 요청은 세지 않는다 — 계속 누르는
     * 사람 때문에 창이 끝없이 뒤로 밀리면, 정작 그 사람이 지나간 뒤 진짜 주인이 눌러도
     * 계속 잠겨 있다. 로그인 쪽이 assertNotLocked() 를 먼저 부르는 것과 같은 차례다.
     */
    private function countResetRequest(string $email): void
    {
        // 이메일 형태가 아닌 값(공격자가 아무 문자열이나 넣은 것)은 세지 않는다 —
        // authenticate() 와 **같은 조건, 같은 이유**다: 주소 쪽 열쇠는 sha256(입력값)
        // 이라 그런 값마다 password_attempts 에 영구 행이 하나씩 생기는데, 그 표에는
        // 정리 루틴이 없다(clear() 는 성공한 자기 행만 지운다). 세지 않아도 잃는 것이
        // 없다: 이메일 형태가 아닌 값은 어떤 회원과도 맞지 않아 문자 한 통도 나가지
        // 않으므로, 이 길에서 한도가 지키려는 비용 자체가 생기지 않는다.
        if ($this->throttle === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $keys = [PasswordThrottle::resetKeyFor($email), PasswordThrottle::RESET_IP_KEY];
        foreach ($keys as $key) {
            $this->throttle->assertNotLocked($key, 'email');
        }
        foreach ($keys as $key) {
            $this->throttle->recordFailure($key);
        }
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
            $throttleKey = 'current-password:' . $userId;
            $current = isset($input['current_password']) && is_scalar($input['current_password'])
                ? (string) $input['current_password'] : '';
            $confirmation = isset($input['password_confirmation']) && is_scalar($input['password_confirmation'])
                ? (string) $input['password_confirmation'] : '';
            if ($this->throttle !== null && $current !== '') {
                $this->throttle->assertNotLocked($throttleKey, 'current_password');
            }
            if ($user['password_hash'] === null) {
                $v->fail('current_password', '소셜 로그인으로 가입한 계정은 비밀번호를 쓰지 않습니다.');
            } elseif (!password_verify($current, (string) $user['password_hash'])) {
                if ($this->throttle !== null && $current !== '') {
                    $v->fail('current_password', $this->throttle->recordFailureMessage(
                        $throttleKey,
                        '현재 비밀번호가 올바르지 않습니다.'
                    ));
                } else {
                    $v->fail('current_password', $current === ''
                        ? '현재 비밀번호를 입력해 주세요.' : '현재 비밀번호가 올바르지 않습니다.');
                }
            } elseif ($this->throttle !== null) {
                $this->throttle->clear($throttleKey);
            }
            if (mb_strlen($password) < Validator::passwordMin()) {
                $v->fail('password', Validator::passwordMin() . '자 이상이어야 합니다.');
            }
            if ($password !== $confirmation) {
                $v->fail('password_confirmation', '비밀번호가 일치하지 않습니다.');
            }
        }
        $v->check();
        // PhoneNumber::normalize() 는 Validator 가 아니라 DomainError 를 직접 던진다.
        // register() 와 같은 이유로 $v->check() 뒤에 본다 — 표시 이름·비밀번호 오류가
        // 함께 있을 때 번호 오류만 보이고 나머지가 다음 제출까지 묻히지 않게 한다.
        $phone = $this->phoneForEdit($input, isset($user['phone']) ? (string) $user['phone'] : null);
        $this->users->updateDisplayName($userId, $displayName);
        if ($phone['write']) {
            $this->users->updatePhone($userId, $phone['phone']);
        }
        if ($password !== '') {
            $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
        }
    }

    public function withdraw(int $userId, array $input, ?string $clientIp, bool $socialReauthenticated): void
    {
        $user = $this->users->findById($userId);
        if ($user === null || $user['status'] !== 'active') {
            throw DomainError::unauthorized('로그인이 필요합니다.');
        }
        $v = new Validator($input);
        if (!$v->bool('confirm_withdrawal', false)) {
            $v->fail('confirm_withdrawal', '탈퇴 안내를 확인해 주세요.');
        }
        if ((bool) $user['is_admin'] && $this->users->countAdmins() <= 1) {
            $v->fail('withdrawal', '마지막 관리자는 탈퇴할 수 없습니다. 다른 관리자를 먼저 지정해 주세요.');
        }
        if ($user['password_hash'] !== null) {
            $current = isset($input['withdraw_current_password']) && is_scalar($input['withdraw_current_password'])
                ? (string) $input['withdraw_current_password'] : '';
            if ($current === '' || !password_verify($current, (string) $user['password_hash'])) {
                $v->fail('withdraw_current_password', '현재 비밀번호가 올바르지 않습니다.');
            }
        } elseif (!$socialReauthenticated) {
            $v->fail('withdrawal', '연결된 소셜 계정으로 다시 인증해 주세요.');
        }
        $v->check();
        $this->users->withdraw($userId, $clientIp);
        // 탈퇴를 **먼저** 끝낸다. 취소는 알리고 왕복이라 느리거나 터질 수 있는데, 그
        // 때문에 탈퇴 자체가 막히면 이 화면은 "나갈 수 없는 사이트"가 된다.
        $this->aligo?->stopScheduledForUser($userId, '탈퇴한');
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
        $throttleKey = 'current-password:' . $userId;
        if ($this->throttle !== null && $current !== '') {
            $this->throttle->assertNotLocked($throttleKey, 'current_password');
        }
        if ($user === null || $user['password_hash'] === null
            || !password_verify($current, (string) $user['password_hash'])) {
            if ($this->throttle !== null && $current !== '') {
                $v->fail('current_password', $this->throttle->recordFailureMessage(
                    $throttleKey,
                    '현재 비밀번호가 올바르지 않습니다.'
                ));
            } else {
                $v->fail('current_password', $current === ''
                    ? '현재 비밀번호를 입력해 주세요.' : '현재 비밀번호가 올바르지 않습니다.');
            }
        } elseif ($this->throttle !== null) {
            $this->throttle->clear($throttleKey);
        }
        if ($password !== $confirmation) {
            $v->fail('password_confirmation', '비밀번호가 일치하지 않습니다.');
        }
        $v->check();
        $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
    }

    /**
     * 비밀번호가 바뀌었다고 본인에게 알린다. 남이 바꿨다면 이 알림으로 알아채고 되찾는다.
     * 발송이 실패해도 비밀번호 변경은 이미 끝난 일이라 막지 않는다 — 대신 **무슨 일이
     * 일어났는지**를 돌려준다.
     *
     * 예전에는 bool 이었고, 그 true 는 "보냈다"가 아니라 "예외 없이 돌아왔다"는 뜻이었다.
     * 관리자가 이 알림의 채널을 전부 꺼 두면 한 통도 나가지 않는데도 true 가 나갔고,
     * 화면은 아무 경고도 띄우지 않아 보낸 것처럼 읽혔다. 세 결과는 서로 다른 사실이고
     * 화면이 셋을 다르게 말해야 하므로, 셋을 구별해 돌려준다.
     *
     * @return self::NOTICE_* 셋 중 하나
     */
    public function notifyPasswordChanged(int $userId): string
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return self::NOTICE_FAILED;
        }
        try {
            return $this->notify('password_changed', Recipient::forUser($user), [
                '사이트명' => $this->siteName(),
                '이름' => (string) $user['display_name'],
                // 시간대 표기를 본문이 아니라 값이 들고 간다 — 같은 값이 문자·알림톡으로
                // 나갈 때 시각만 덩그러니 남으면 어느 시간대인지 알 수 없다.
                '일시' => Clock::now() . ' (UTC)',
                '링크' => $this->appUrl . '/forgot-password',
            ]) ? self::NOTICE_SENT : self::NOTICE_OFF;
        } catch (\Throwable $e) {
            error_log('[' . GNUCMS_ID . '] 비밀번호 변경 알림 실패: ' . $e->getMessage());
            return self::NOTICE_FAILED;
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

    /**
     * @param bool $quiet 발송 실패를 예외로 올리지 않는다. 다시 보내기 화면만 참을 준다 —
     *   그 화면은 없는 주소에도 같은 답을 내야 하기 때문이다(notifyQuietly 주석).
     *   가입은 거짓이다: 거기서 실패를 삼키면 인증 링크 없는 회원 행이 남고, 화면은
     *   "보냈어요"라고 말한다. 가입 화면은 세 갈래가 모두 같은 오류를 내므로 그 자리에서는
     *   예외가 계정의 존재를 흘리지 않는다.
     */
    private function sendVerification(array $user, bool $quiet = false): void
    {
        $token = $this->tokens->issue((int) $user['id'], TokenService::VERIFY_EMAIL);
        $url = $this->appUrl . '/verify-email?token=' . rawurlencode($token);
        $to = Recipient::forUser($user);
        $vars = [
            '사이트명' => $this->siteName(),
            '이름' => (string) $user['display_name'],
            '링크' => $url,
            '유효시간' => '24시간',
        ];
        $sent = $quiet
            ? $this->notifyQuietly('email_verify', $to, $vars)
            : $this->notify('email_verify', $to, $vars);
        if (!$sent) {
            // 이 회원은 인증을 끝낼 길이 없다 — 화면은 canSendVerificationLink() 로 누구에게나
            // 같은 사실만 말하지만,
            // 고칠 수 있는 사람은 사이트 주인뿐이라 로그에도 남긴다. Notifier 는 켠 채널이
            // 아예 없는 경우에는 아무 줄도 적지 않으므로(그쪽은 사고가 아니라 설정이다)
            // 이 자리에서 적어야 한다. 주소는 적지 않는다 — 회원 번호면 충분하다.
            error_log('[' . GNUCMS_ID . '] 이메일 인증 링크가 아무 데도 나가지 않았습니다'
                . ' — 회원 #' . (int) $user['id'] . ' 는 지금 가입을 끝낼 수 없습니다.');
        }
    }

    /**
     * 가입이 끝났다고 알린다. 코어에 없던 알림이라 기본 설정은 채널을 하나도 켜 두지
     * 않는다(NotifySettings::DEFAULTS) — 관리자가 켜기 전에는 아무 데도 나가지 않는다.
     *
     * 실패를 삼키는 것은 notifyPasswordChanged() 와 같은 이유다: 여기 닿았다는 것은
     * 회원 행도 인증 표시도 이미 커밋됐다는 뜻이고, 새로 들인 알림 한 통이 실패했다고
     * 이미 끝난 가입·인증을 실패로 보여 주면 안 된다.
     */
    private function sendWelcome(array $user): void
    {
        try {
            // 나갔는지 묻지 않는다. 기본값이 "채널 없음"이라 대개 안 나가는 것이 정상이고,
            // 못 나가도 그 사람이 못 하게 되는 일이 없다 — 화면에 옮길 것이 없다.
            $this->notify('welcome', Recipient::forUser($user), [
                '사이트명' => $this->siteName(),
                '이름' => (string) $user['display_name'],
            ]);
        } catch (\Throwable $e) {
            error_log('[' . GNUCMS_ID . '] 가입 완료 안내 실패: ' . $e->getMessage());
        }
    }

    /**
     * 없는 계정에도 **똑같이** 돌아가야 하는 두 자리(다시 보내기·비밀번호 재설정)가 쓰는
     * 발송. 결과가 false 든 예외든 부르는 쪽에는 "안 나갔다" 하나로만 돌아온다.
     *
     * **왜 예외까지 삼키는가.** 전부 실패하면 Notifier 는 503 을 올린다(발송기가 배선되지
     * 않은 조립이면 메일러의 예외가 그대로 올라온다). 그 예외가 화면까지 가면 오류 페이지가
     * 뜨는데 — **계정이 있는 주소에서만** 뜬다. 없는 주소는 보낼 것이 없어 조용히 200 을
     * 받기 때문이다. 그 차이가 곧 "이 주소가 가입돼 있는가"를 묻는 도구다. SMTP 가 죽어
     * 있는 동안 누구나 계정 목록을 뽑을 수 있다는 뜻이고, 이 분기가 네 라운드에 걸쳐
     * 막아 온 것이 정확히 그 모양이다.
     *
     * **관계없는 사고까지 삼키지는 않는다.** try 안에 있는 것은 발송 호출 한 줄뿐이다 —
     * 토큰 발급도 회원 조회도 바깥이라 그쪽 사고는 그대로 올라간다. 삼킨 것은 조용히
     * 사라지지도 않는다: 무엇이 터졌는지 여기서 로그에 적고, 부르는 쪽이 "아무 데도 나가지
     * 않았습니다" 한 줄을 덧붙인다. 수신자와 $vars 는 적지 않는다(Notifier 와 같은 규칙).
     */
    private function notifyQuietly(string $event, Recipient $to, array $vars): bool
    {
        try {
            return $this->notify($event, $to, $vars);
        } catch (\Throwable $e) {
            error_log('[' . GNUCMS_ID . '] 알림 ' . $event . ' 발송이 실패했습니다 — '
                . get_class($e) . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 알림 하나를 내보낸다. 제목·본문은 여기서 만들지 않는다 — 어느 길로 가든 문구는
     * Notify\MailBodies 한 곳에서 나오므로 메일 글자가 갈라질 수 없다. 발송기를 받지
     * 못한 조립에서 무슨 일이 벌어지는지는 UnwiredNotifier 의 주석이 설명한다.
     *
     * @return bool 한 군데라도 실제로 나갔는가.
     */
    private function notify(string $event, Recipient $to, array $vars): bool
    {
        return $this->notifier !== null
            ? $this->notifier->notify($event, $to, $vars)
            : $this->unwired()->notify($event, $to, $vars);
    }

    private function unwired(): UnwiredNotifier
    {
        return new UnwiredNotifier($this->mailer, self::class);
    }

    /** 메일에 쓰는 이름은 관리자가 설정한 홈페이지 제목(site_name)을 따른다. */
    private function siteName(): string
    {
        return (string) $this->cms->settings()['site_name'];
    }

    /**
     * 회원정보 수정 화면 전용. 관리자 회원 수정은 정책을 아예 보지 않으므로 이 메서드를
     * 쓰지 않는다(AdminService::phoneFromAdminInput() 의 설명 참고).
     *
     * 가입용 phoneFromInput() 과 세 값은 같지만 off 와 required 의 뜻이 다르다.
     *
     * off — 가입은 아직 아무 것도 저장돼 있지 않으니 null 을 돌려줘도 안전하지만,
     * 수정 화면에서 그 null 을 그대로 썼다가는 관리자가 설정을 끄는 순간 모든 회원의
     * 저장된 번호가 다음 프로필 저장마다 조용히 지워진다. 그래서 "쓸지 여부" 자체를
     * false 로 돌려 칸을 아예 건드리지 않는다 — 이미 있는 번호는 화면에서 고칠 수
     * 없을 뿐, 지워지지 않고 알림톡·문자 발송에 계속 쓰인다.
     *
     * required — 가입 화면에서는 빈 값을 거절하는 것이 곧 정책이지만, 수정 화면에서
     * 그렇게 하면 번호가 없는 회원(정책을 켜기 전에 가입한 회원, 번호를 받지 않는
     * 소셜 가입)이 이름·프로필 이미지는 물론 비밀번호까지 바꿀 수 없게 된다. 라디오
     * 하나로 기존 회원 전체가 회원정보 수정에서 잠기는 셈이다. signup_phone 은
     * "가입 화면이 무엇을 물을지"를 정하는 설정이지 "이 사이트가 번호를 다루는가"가
     * 아니므로, 수정 화면에서 required 는 "저장된 번호를 지울 수는 없다"는 뜻으로만
     * 받는다: 저장된 번호가 있는데 빈 값을 보내면 거절하고, 저장된 번호가 아예 없으면
     * 칸을 건드리지 않고 넘어간다.
     *
     * @return array{write: bool, phone: ?string}
     */
    private function phoneForEdit(array $input, ?string $stored): array
    {
        $policy = $this->signupPhonePolicy();
        if ($policy === 'off') {
            return ['write' => false, 'phone' => null];
        }
        // "빈 칸을 보냈다"(지우라는 뜻)와 "칸 자체를 안 보냈다"는 다르다. 이 화면에는
        // 실제로 칸이 빠지는 경로가 있다: 정책이 off 인 동안 번호 칸은 disabled 로
        // 그려지고(저장된 번호를 보여 주되 고칠 수는 없게), 브라우저는 disabled 인
        // 칸을 POST 에 싣지 않는다. 그 화면을 열어 둔 회원이 있는 사이 관리자가
        // 정책을 선택으로 바꾸면, 이름만 고친 저장 한 번이 번호를 조용히 지운다.
        // 안 보낸 칸은 건드리지 않는다 — AdminService::phoneFromAdminInput() 과 같다.
        if (!array_key_exists('phone', $input)) {
            return ['write' => false, 'phone' => null];
        }
        $stored = $stored === null || trim($stored) === '' ? null : trim($stored);
        $given = self::submittedPhone($input);
        if ($given === '') {
            if ($policy === 'required') {
                if ($stored !== null) {
                    throw DomainError::validation([
                        'phone' => '필수 항목이라 저장된 번호를 지울 수 없습니다. 바꾸려면 새 번호를 입력해 주세요.',
                    ]);
                }

                return ['write' => false, 'phone' => null];
            }

            return ['write' => true, 'phone' => null];
        }

        return ['write' => true, 'phone' => PhoneNumber::normalizeEdit($given, $stored)];
    }

    /** 가입 화면 전용. 설정이 off 면 입력을 무시하고, required 면 빈 값을 거절한다. */
    private function phoneFromInput(array $input): ?string
    {
        $policy = $this->signupPhonePolicy();
        if ($policy === 'off') {
            return null;
        }
        $given = self::submittedPhone($input);
        if ($given === '') {
            if ($policy === 'required') {
                throw DomainError::validation(['phone' => '휴대폰번호를 입력해 주세요.']);
            }

            return null;
        }

        return PhoneNumber::normalize($given);
    }

    /**
     * 제출된 번호 칸. requiredString() 등 Validator 의 다른 헬퍼와 같은 이유로 스칼라만
     * 받는다 — 배열이 오면(예: phone[]=x) (string) 캐스팅이 경고를 낸다.
     */
    private static function submittedPhone(array $input): string
    {
        return isset($input['phone']) && is_scalar($input['phone']) ? trim((string) $input['phone']) : '';
    }

    /** signup_phone 설정값. CmsService 가 이미 세 값으로 정규화하지만, 한 번 더 확인한다. */
    private function signupPhonePolicy(): string
    {
        $policy = (string) ($this->cms->settings()['signup_phone'] ?? 'off');

        return in_array($policy, ['off', 'optional', 'required'], true) ? $policy : 'off';
    }

    private function displayNameFromEmail(string $email): string
    {
        $at = strpos($email, '@');
        $name = $at === false ? $email : substr($email, 0, $at);

        return mb_substr($name === '' ? '회원' : $name, 0, 100);
    }
}
