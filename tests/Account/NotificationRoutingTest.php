<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\AccountService;
use GnuCms\Aligo\AligoService;
use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Mail\MailerInterface;
use GnuCms\Mail\SecretCipher;
use GnuCms\Notify\ChannelInterface;
use GnuCms\Notify\Events;
use GnuCms\Notify\Notifier;
use GnuCms\Notify\NotifySettings;
use GnuCms\Notify\Recipient;
use GnuCms\Notify\SettingsRepository;
use GnuCms\Oauth\SocialProfile;
use GnuCms\Tests\Support\CollectingMailer;
use GnuCms\Tests\Support\FakeAligoTransport;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 계정 알림 여섯 통이 전부 Notifier 를 지나는지, 그리고 지날 때 카탈로그가 선언한 값을
 * 빠짐없이 들고 가는지 본다.
 *
 * **문구는 여기서 보지 않는다.** 제목·본문이 옮기기 전과 한 글자도 다르지 않은지는
 * MailBodiesTest 와 기존 AccountServiceTest(진짜 메일러로 제목·본문을 그대로 단언한다)가
 * 지킨다. 여기서 보는 것은 그 앞단이다 — 어느 이벤트로, 누구에게, 어떤 값을 들려
 * 보내는가.
 *
 * **채널 자리에 스파이를 끼운다.** Notifier 는 final 이라 흉내 낼 수 없고, 흉내 냈다면
 * 정작 "설정이 켠 채널로 나가는가"를 건너뛰게 된다. 대신 진짜 Notifier 에 진짜
 * NotifySettings 를 주고 메일 채널 자리에만 스파이를 끼운다 — 기본 설정이 이 알림들의
 * 메일을 켜 두므로(NotifySettings::DEFAULTS) 스파이가 그대로 다 받는다.
 */
final class NotificationRoutingTest extends WebTestCase
{
    /** 스파이 채널이 받은 발송. 각 항목은 ['event' => string, 'to' => Recipient, 'vars' => array]. */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = [];
    }

    #[DataProvider('connectionProvider')]
    public function testPasswordResetGoesThroughTheNotifier(array $config): void
    {
        $app = $this->boot($config);
        $this->saveSiteSettings($app, ['signup_phone' => 'optional']);
        $app->accountService()->register($this->owner(['phone' => '010-1234-5678']));
        $this->calls = [];

        $app->accountService()->requestPasswordReset('owner@example.com');

        $call = $this->lastCall();
        self::assertSame('password_reset', $call['event']);
        self::assertSame('owner@example.com', $call['to']->email);
        // 문자·알림톡으로 켤 수 있는 알림이다(Events 의 phone=true). 번호가 수신자까지
        // 닿지 않으면 그 두 채널은 켜 두어도 영영 건너뛴다.
        self::assertSame('01012345678', $call['to']->phone);
        self::assertSame('owner', $call['vars']['이름']);
        self::assertSame('1시간', $call['vars']['유효시간']);
        self::assertStringContainsString('/reset-password?token=', $call['vars']['링크']);
    }

    /** 인증 메일을 받지 않는 사람(이미 인증된 채로 만들어지는 첫 관리자)은 가입 그 자리에서 환영받는다. */
    #[DataProvider('connectionProvider')]
    public function testTheOwnerWhoNeedsNoVerificationIsWelcomedAtRegistration(array $config): void
    {
        $app = $this->boot($config);
        $this->enable($app, 'welcome');

        $app->accountService()->register($this->owner());

        self::assertSame(['welcome'], array_column($this->calls, 'event'));
        $call = $this->lastCall();
        self::assertSame('owner@example.com', $call['to']->email);
        self::assertSame('owner', $call['vars']['이름']);
    }

    /** 인증을 거치는 사람은 토큰을 실제로 쓴 그 순간에 환영받는다. */
    #[DataProvider('connectionProvider')]
    public function testAMemberIsWelcomedWhenTheVerificationTokenIsUsed(array $config): void
    {
        $app = $this->boot($config);
        $this->enable($app, 'welcome');
        $id = $this->unverifiedMember($app);

        $app->accountService()->resendVerification('member@example.com');
        $verify = $this->lastCall();
        self::assertSame('email_verify', $verify['event']);
        self::assertSame('24시간', $verify['vars']['유효시간']);
        self::assertSame('회원이름', $verify['vars']['이름']);

        $app->accountService()->verifyEmail($this->tokenFrom($verify['vars']['링크']));

        $welcome = $this->lastCall();
        self::assertSame('welcome', $welcome['event']);
        self::assertSame((string) $id, $welcome['to']->userId);
        self::assertSame('회원이름', $welcome['vars']['이름']);
    }

    /** 회원을 만드는 다른 길(설치·소셜 연결)은 환영 알림을 내지 않는다 — 토큰을 쓴 사람만 받는다. */
    #[DataProvider('connectionProvider')]
    public function testMarkingAnEmailVerifiedByItselfSendsNothing(array $config): void
    {
        $app = $this->boot($config);
        $this->enable($app, 'welcome');
        $id = $this->unverifiedMember($app);

        $app->users()->verifyEmail($id);

        self::assertSame([], $this->calls);
    }

    #[DataProvider('connectionProvider')]
    public function testPasswordChangedCarriesTheTimeWithItsZone(array $config): void
    {
        $app = $this->boot($config);
        $id = $this->unverifiedMember($app);
        $app->users()->verifyEmail($id);

        self::assertSame(AccountService::NOTICE_SENT,
            $app->accountService()->notifyPasswordChanged($id));

        $call = $this->lastCall();
        self::assertSame('password_changed', $call['event']);
        self::assertSame('member@example.com', $call['to']->email);
        self::assertSame('회원이름', $call['vars']['이름']);
        // 문자·알림톡으로도 나갈 수 있는 값이라 시간대 표기를 값이 들고 간다.
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \(UTC\)$/', $call['vars']['일시']);
        self::assertStringEndsWith('/forgot-password', $call['vars']['링크']);
    }

    #[DataProvider('connectionProvider')]
    public function testASecondSignupWithTheSameEmailNotifiesTheAccount(array $config): void
    {
        $app = $this->boot($config);
        $app->accountService()->register($this->owner());
        $agreements = $this->publishLegalPages($app);
        $this->calls = [];

        $app->accountService()->register($this->owner($agreements));

        $call = $this->lastCall();
        self::assertSame('signup_attempt', $call['event']);
        self::assertSame('owner@example.com', $call['to']->email);
        self::assertStringEndsWith('/login', $call['vars']['링크']);
    }

    #[DataProvider('connectionProvider')]
    public function testSocialEmailConfirmationGoesThroughTheNotifier(array $config): void
    {
        $app = $this->boot($config);

        $app->socialAuthService()->sendPendingEmail(
            new SocialProfile('kakao', '42', 'social@example.com', false, '카카오회원'),
            'social@example.com',
            'pending-token'
        );

        $call = $this->lastCall();
        self::assertSame('social_email_verify', $call['event']);
        self::assertSame('social@example.com', $call['to']->email);
        self::assertSame('30분', $call['vars']['유효시간']);
        self::assertStringContainsString('/auth/complete?token=pending-token', $call['vars']['링크']);
    }

    /**
     * 카탈로그가 선언한 변수는 본문에 쓰이지 않더라도 반드시 실려 와야 한다. 그 이름들이
     * 관리자가 제 문자·알림톡 본문을 쓸 때 고르는 어휘이기 때문이다 — 값이 없으면 관리자가
     * 화면에서 고를 수는 있는데 발송 때만 조용히 비는 변수가 생긴다.
     */
    #[DataProvider('connectionProvider')]
    public function testEveryNotificationSuppliesEveryVariableItDeclares(array $config): void
    {
        $app = $this->boot($config);
        foreach (array_keys(Events::ALL) as $event) {
            $this->enable($app, $event);
        }
        $this->saveSiteSettings($app, ['signup_phone' => 'optional']);

        $owner = $app->accountService()->register($this->owner(['phone' => '010-1234-5678']));
        $agreements = $this->publishLegalPages($app);
        $app->accountService()->register($this->owner($agreements));
        $app->accountService()->requestPasswordReset('owner@example.com');
        $app->accountService()->notifyPasswordChanged((int) $owner['id']);
        $this->unverifiedMember($app);
        $app->accountService()->resendVerification('member@example.com');
        $app->accountService()->verifyEmail($this->tokenFrom($this->lastCall()['vars']['링크']));
        $app->socialAuthService()->sendPendingEmail(
            new SocialProfile('kakao', '42', 'social@example.com', false, '카카오회원'),
            'social@example.com',
            'pending-token'
        );

        $seen = array_values(array_unique(array_column($this->calls, 'event')));
        sort($seen);
        self::assertSame(['email_verify', 'password_changed', 'password_reset', 'signup_attempt',
            'social_email_verify', 'welcome'], $seen);
        foreach ($this->calls as $call) {
            foreach (Events::variables($call['event']) as $name) {
                self::assertArrayHasKey($name, $call['vars'], $call['event'] . ' 가 ' . $name . ' 을 안 실었다');
                self::assertIsString($call['vars'][$name], $call['event'] . ' 의 ' . $name . ' 이 문자열이 아니다');
                self::assertNotSame('', $call['vars'][$name], $call['event'] . ' 의 ' . $name . ' 이 비었다');
            }
        }
    }

    /**
     * App 이 두 서비스에 발송기를 실제로 끼웠는지 본다. 끼우지 않으면 서비스는 제
     * 메일러로 곧장 보내 버리고, 관리자가 꺼 둔 알림이 그대로 나간다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheAppRoutesBothServicesThroughTheNotifier(array $config): void
    {
        $app = $this->makeApp($config);
        $mailer = new CollectingMailer();
        $app->setMailer($mailer);
        $app->notifySettings()->save('password_reset', $this->channels([]));
        $app->notifySettings()->save('social_email_verify', $this->channels([]));
        $id = $this->unverifiedMember($app);
        $app->users()->verifyEmail($id);

        $app->accountService()->requestPasswordReset('member@example.com');
        $app->socialAuthService()->sendPendingEmail(
            new SocialProfile('kakao', '42', 'social@example.com', false, '카카오회원'),
            'social@example.com',
            'pending-token'
        );

        self::assertSame(['member@example.com', 'social@example.com'], array_column($mailer->messages, 'to'));
    }




    /**
     * 한 채널만 닿으면 된다. 닿지 않는 채널이 섞여 있다고 "못 보낸다"고 하면 안 된다.
     *
     * 본은 {mail, sms} 에 알리고 미연결이다. 이 조합은 **필터가 지우지 못한다** —
     * password_reset 은 전화 채널을 쓸 수 있는 알림이라 sms 가 그대로 남고, 남은 채로
     * "지금은 쓸 수 없는 채널"이 된다. 앞서 쓰던 {mail, inbox} 는 계획 4 의 필터가
     * inbox 를 지워 {mail} 로 만들어 버려, 닿지 않는 채널이 섞인 상태 자체가 사라졌다.
     *
     * 이 시험이 깨지려면: canReach() 가 "하나라도 닿으면"에서 "전부 닿아야"로 바뀌면 된다.
     * 그 상태는 이 설정에서 실제로 도달 가능하다(알리고를 연결하지 않은 사이트가 문자를
     * 켜 두는 것은 흔한 중간 상태다).
     */
    #[DataProvider('connectionProvider')]
    public function testTheScreensStillSayItCanSendWhenOneChannelReaches(array $config): void
    {
        $app = $this->makeApp($config);
        $id = $this->unverifiedMember($app);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $app->notifySettings()->save('password_reset', ['mail' => '1', 'alimtalk' => '0',
            'sms' => '1', 'inbox' => '0', 'sms_body' => '#{이름}님 #{링크} 에서 다시 설정해 주세요']);
        self::assertSame(['mail', 'sms'], $app->notifySettings()->channelsFor('password_reset'),
            '필터가 지우지 않는 조합이어야 이 시험에 뜻이 있다');

        self::assertStringContainsString('재설정 링크를 보냈어요', $this->body($this->post($app,
            '/forgot-password', ['csrf_token' => $_SESSION['csrf_token'], 'email' => 'nobody@example.com'])));
    }

    /**
     * 발송이 통째로 실패해도 두 화면의 답은 달라지지 않는다.
     *
     * 실패하면 Notifier 가 503 을 올리는데, 올릴 것이 있는 쪽은 **계정이 있는 주소뿐**이다 —
     * 없는 주소는 보낼 것이 없어 조용히 200 을 받는다. SMTP 가 죽어 있는 동안 그 차이가
     * 계정 목록이 된다. 이 분기 이전부터 있던 모양이고, 이 화면들을 고쳐 놓고 그대로 둘
     * 자리가 아니다.
     */
    #[DataProvider('connectionProvider')]
    public function testNeitherScreenChangesItsAnswerWhenTheSendItselfFails(array $config): void
    {
        $app = $this->makeApp($config);
        $app->setMailer($this->throwingMailer());
        $id = $this->unverifiedMember($app);
        $this->get($app, '/login');

        $known = $this->post($app, '/verify-email/resend',
            ['csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com']);
        $unknown = $this->post($app, '/verify-email/resend',
            ['csrf_token' => $_SESSION['csrf_token'], 'email' => 'nobody@example.com']);
        self::assertSame(200, $known->getStatusCode(), $this->body($known));
        self::assertSame($this->body($unknown), $this->body($known));

        $app->users()->verifyEmail($id);
        $knownReset = $this->post($app, '/forgot-password',
            ['csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com']);
        $unknownReset = $this->post($app, '/forgot-password',
            ['csrf_token' => $_SESSION['csrf_token'], 'email' => 'nobody@example.com']);
        self::assertSame(200, $knownReset->getStatusCode(), $this->body($knownReset));
        self::assertSame($this->body($unknownReset), $this->body($knownReset));
    }

    /** 삼킨 실패는 조용히 사라지지 않는다 — 무엇이 터졌는지 운영자 로그에 남는다. */
    #[DataProvider('connectionProvider')]
    public function testTheOperatorIsToldWhatBrokeWhenTheSendFailed(array $config): void
    {
        $app = $this->makeApp($config);
        $app->setMailer($this->throwingMailer());
        $id = $this->unverifiedMember($app);
        $app->users()->verifyEmail($id);

        $logged = $this->captureErrorLog(function () use ($app): void {
            $app->accountService()->requestPasswordReset('member@example.com');
        });

        self::assertStringContainsString('발송이 실패했습니다', $logged);
        self::assertStringNotContainsString('member@example.com', $logged, '주소는 로그에 적지 않는다');
    }

    /**
     * 가입은 조용하지 않다. 발송 실패를 삼키면 인증 링크 없는 회원 행이 남고 화면은
     * "보냈어요"라고 말한다 — 네 라운드에 걸쳐 막은 바로 그 자리다. 다시 보내기 화면과
     * 달리 가입 화면은 세 갈래가 모두 같은 오류를 내므로, 여기서 예외가 올라가도 계정의
     * 존재는 드러나지 않는다.
     *
     * 이 시험이 깨지려면: register() 가 부르는 sendVerification() 이 조용한 쪽으로 바뀌면 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testASignupDoesNotClaimSuccessWhenTheVerificationSendFails(array $config): void
    {
        $app = $this->makeApp($config);
        $app->accountService()->register($this->owner());
        $agreements = $this->publishLegalPages($app);
        $app->setMailer($this->throwingMailer());

        $this->expectException(DomainError::class);
        $app->accountService()->register($agreements + [
            'email' => 'member@example.com', 'password' => 'member-password-123',
            'password_confirmation' => 'member-password-123',
        ]);
    }

    /** SMTP 가 죽었을 때의 메일러. SmtpMailer 가 실제로 던지는 것과 같은 모양이다. */
    private function throwingMailer(): MailerInterface
    {
        return new class () implements MailerInterface {
            public function send(string $to, string $subject, string $body): void
            {
                throw DomainError::internal('SMTP 메일을 보내지 못했습니다. 계정과 앱 비밀번호를 확인해 주세요.');
            }
        };
    }

    /**
     * 재설정 화면이 묻는 것은 "주소만 가진 사람에게 닿는가"가 아니라 "누구에게든 닿는가"다.
     * 문자만 켜 둔 사이트는 번호 없는 사람에게는 못 가지만 번호를 가진 회원에게는 간다 —
     * 주소만 가진 본으로 물었다면 "못 보낸다"고 잘못 답했을 설정이다. 진짜 회원으로 물으면
     * 그 답이 계정의 존재를 말하므로, 가장 잘 닿는 사람의 본으로 묻는다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheResetScreenAsksWhetherAnyoneAtAllCanBeReached(array $config): void
    {
        $app = $this->makeApp($config);
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $app->aligo()->settings->setEnabled('sms', true);
        $app->notifySettings()->save('password_reset', ['mail' => '0', 'alimtalk' => '0',
            'sms' => '1', 'inbox' => '0', 'sms_body' => '#{이름}님 #{링크} 에서 다시 설정해 주세요']);
        $this->get($app, '/login');

        // 없는 주소로 묻는다 — 발송이 일어나지 않게 해서 화면의 답만 본다.
        self::assertStringContainsString('재설정 링크를 보냈어요', $this->body($this->post($app,
            '/forgot-password',
            ['csrf_token' => $_SESSION['csrf_token'], 'email' => 'nobody@example.com'])));
    }



    /** 첫 사람은 인증을 기다리지 않는다. 여기서 막으면 설정을 고칠 관리자가 생기지 못한다. */
    #[DataProvider('connectionProvider')]
    public function testTheFirstOwnerCanStillRegisterWithNoVerificationChannel(array $config): void
    {
        $app = $this->boot($config);
        $app->notifySettings()->save('email_verify', $this->channels([]));

        $owner = $app->accountService()->register($this->owner());

        self::assertTrue($owner['email_verified']);
        self::assertTrue($owner['is_admin']);
    }



    /**
     * 두 화면이 말하는 것은 "이 사이트가 보낼 수 있는가"뿐이라, 가입된 주소와 아닌 주소의
     * 화면이 한 글자도 다르지 않아야 한다. 달라지는 순간 그 화면이 계정 목록이 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testNeitherScreenTellsWhetherTheAccountExists(array $config): void
    {
        $app = $this->makeApp($config);
        $id = $this->unverifiedMember($app);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');

        foreach ([['password_reset', '/forgot-password'], ['email_verify', '/verify-email/resend']] as [$event, $path]) {
            // 'inbox' 가 여기 있는 것이 중요하다: 그 상태에서 화면이 말하는 답이
            // 바뀌었으므로(닿지 않는다), 더 정확해진 답이 계정 존재를 흘리기 시작하지
            // 않았는지 다시 확인해야 한다. 'sms' 가 섞인 둘은 재설정 쪽에서 필터가 지우지
            // 못하는 상태다 — 화면의 답이 '못 보낸다'(문자만, 알리고 미연결)와
            // '보낸다'(메일이 함께 켜져 있음)로 갈리는, 서로 다른 두 자리를 함께 본다.
            foreach ([['mail'], [], ['inbox'], ['mail', 'inbox'], ['sms'], ['mail', 'sms']] as $on) {
                $this->forceChannels($app, $event, $on);
                // 재설정 요청에는 횟수 제한이 있고(AccountService::requestPasswordReset),
                // 그 셈은 주소가 아니라 **누른 횟수**에 달렸다 — 여기서 여섯 번을 잇따라
                // 누르면 나중 것부터 잠긴 화면이 되어, 주소와 무관한 차이로 두 응답이
                // 갈린다. 비교하려는 것은 주소이므로 짝마다 셈을 지우고 같은 자리에서
                // 출발시킨다. 잠긴 화면끼리도 같은지는 ForgotPasswordThrottleTest 가 본다.
                $app->db()->delete('password_attempts', '1 = 1');
                // 여기 심는 설정 가운데 몇은 엔진이 도로 끄는 조합이라(알림함은 이 두
                // 알림을 받지 않는다) 운영자 로그 한 줄이 나간다 — 일부러 만든 상태의
                // 정상적인 진단이므로, 스위트 출력에 흘리지 않고 받아만 둔다.
                $known = '';
                $unknown = '';
                $this->captureErrorLog(function () use ($app, $path, &$known, &$unknown): void {
                    $known = $this->body($this->post($app, $path,
                        ['csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com']));
                    $unknown = $this->body($this->post($app, $path,
                        ['csrf_token' => $_SESSION['csrf_token'], 'email' => 'nobody@example.com']));
                });
                self::assertSame($known, $unknown, $path . ' 화면이 계정 존재를 흘린다');
            }
        }
    }


    /** 알리고를 바꾸면 발송기가 새로 만들어진다. 그 발송기를 쥔 두 서비스도 함께 새로 만들어야 한다. */
    #[DataProvider('connectionProvider')]
    public function testReplacingAligoRebuildsTheServicesThatHoldTheNotifier(array $config): void
    {
        $app = $this->makeApp($config);
        $account = $app->accountService();
        $social = $app->socialAuthService();

        $app->setAligo(new AligoService($app->db(), new FakeAligoTransport(), new SecretCipher('x')));

        self::assertNotSame($account, $app->accountService());
        self::assertNotSame($social, $app->socialAuthService());
    }


    /** 켜 둔 채널이 터진 것은 설정이 아니라 사고다. 화면도 다르게 말해야 한다. */
    #[DataProvider('connectionProvider')]
    public function testPasswordChangedSaysFailedWhenTheOnlyChannelThrows(array $config): void
    {
        $app = $this->makeApp($config);
        (new \ReflectionProperty(App::class, 'notifier'))->setValue($app,
            new Notifier($app->notifySettings(), [$this->throwingChannel()], static function (): void {
            }));
        $id = $this->unverifiedMember($app);
        $app->users()->verifyEmail($id);

        self::assertSame(AccountService::NOTICE_FAILED,
            $app->accountService()->notifyPasswordChanged($id));
    }

    /**
     * **작업 행이 생겼다는 것은 나갔다는 뜻이 아니다.** 알리고가 받지 못한 발송을
     * Dispatch 는 'failed' 로 적고 작업 번호를 그대로 돌려준다 — 그것을 "나갔다"로 세면
     * 같은 요청이 실패를 기록하면서 화면에는 초록 띠를 그린다. 실제로 그랬다.
     *
     * 문자 하나만 켜 두고 알리고를 죽여 둔 채 관리자가 비밀번호를 바꾼다. 진짜 HTTP
     * 요청으로 몰고, 화면이 무엇을 말하는지와 DB 에 무엇이 남았는지를 함께 본다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheScreenDoesNotClaimASendAligoNeverTook(array $config): void
    {
        $app = $this->makeApp($config);
        $adminId = $app->users()->create('admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($adminId);
        $memberId = $this->unverifiedMember($app);
        $app->users()->verifyEmail($memberId);
        $app->users()->updatePhone($memberId, '01012345678');
        $transport = $this->smsOnly($app, 'password_changed');
        $this->get($app, '/login');
        session_start();
        $_SESSION['user_id'] = $adminId;
        $_SESSION['session_epoch'] = 0;
        session_write_close();
        $transport->queueFailure();

        $response = $this->changeMemberPassword($app, $memberId, 'new-password-456');

        self::assertSame([['failed', 0, 1]], array_map(
            static fn (array $job): array => [(string) $job['status'], (int) $job['success'], (int) $job['failure']],
            $app->db()->select('SELECT * FROM ' . $app->db()->table('message_jobs'))),
            '알리고는 이 발송을 받지 않았다 — 그것이 이 시험의 전제다');
        self::assertStringContainsString('notice=sent', $response->getHeaderLine('Location'));
        $body = $this->body($this->get($app, '/admin/members', ['saved' => '1', 'notice' => 'sent']));
        self::assertStringContainsString('비밀번호 변경 알림을 보냈습니다', $body);
    }

    /**
     * 같은 결함의 두 번째 얼굴: 재설정 링크가 아무 데도 가지 못했다는 사실을 운영자에게
     * 알리는 줄이 함께 사라졌다. 그 줄은 $sent === false 일 때만 적히는데, 작업 행 하나로
     * true 가 나갔기 때문이다. 이 사람은 지금 비밀번호를 되찾을 수 없다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheOperatorIsToldWhenTheResetLinkOnlyProducedAFailedJob(array $config): void
    {
        $app = $this->makeApp($config);
        $id = $this->unverifiedMember($app);
        $app->users()->verifyEmail($id);
        $app->users()->updatePhone($id, '01012345678');
        $transport = $this->smsOnly($app, 'password_reset');
        $transport->queueFailure();

        $logged = $this->captureErrorLog(function () use ($app): void {
            $app->accountService()->requestPasswordReset('member@example.com');
        });

        self::assertStringContainsString('sms', $logged);
        self::assertStringNotContainsString('비밀번호를 되찾을 수 없습니다', $logged);
        self::assertSame(['failed'], array_map(
            static fn (array $job): string => (string) $job['status'],
            $app->db()->select('SELECT * FROM ' . $app->db()->table('message_jobs'))));
    }

    /**
     * **비밀번호 재설정 토큰은 발송 표에도 남지 않는다.**
     *
     * 코어는 이 토큰을 되돌릴 수 있는 형태로 어디에도 저장하지 않는다 —
     * user_tokens 에는 sha256 만 있다. 그 결정을 발송 층이 조용히 되돌렸었다: 치환이
     * 끝난 수신자 본문이 message_recipients.body 에 적히면서, 살아 있는 토큰이 평문으로
     * 영구 표에 들어가 백업마다 따라다녔다.
     *
     * 나간 문자에서 토큰을 꺼내 그 해시가 user_tokens 에 있는지 먼저 확인한다 —
     * 이것이 "진짜 살아 있는 토큰"이라는 전제이고, 이 단언이 없으면 이 시험은 아무
     * 문자열이나 못 찾았다고 말하는 빈 시험이 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheResetTokenNeverReachesTheMessageTables(array $config): void
    {
        $app = $this->makeApp($config);
        $id = $this->unverifiedMember($app);
        $app->users()->verifyEmail($id);
        $app->users()->updatePhone($id, '01012345678');
        $transport = $this->smsOnly($app, 'password_reset', '#{이름}님 #{링크} 에서 재설정하세요');
        $transport->queue(200, (string) json_encode(
            ['result_code' => 1, 'msg_id' => 'M1', 'success_cnt' => 1, 'error_cnt' => 0]));

        $app->accountService()->requestPasswordReset('member@example.com');

        // 문자 API 는 EUC-KR 로 실어 보낸다. 실제로 나간 본문에는 링크가 그대로 있다.
        $sent = (string) mb_convert_encoding(
            (string) $transport->requests[0]['fields']['msg_1'], 'UTF-8', 'EUC-KR');
        self::assertSame(1, preg_match('/token=([^\s&]+)/', $sent, $matches),
            '보낸 문자에 재설정 링크가 없으면 이 시험은 아무것도 지키지 못한다');
        $token = rawurldecode($matches[1]);
        self::assertNotNull($app->db()->selectOne('SELECT id FROM '
            . $app->db()->table('user_tokens') . ' WHERE token_hash = ?', [hash('sha256', $token)]),
            '이 토큰으로 지금 비밀번호를 바꿀 수 있다 — 그래서 저장되면 안 된다');

        foreach (['message_jobs', 'message_recipients'] as $table) {
            foreach ($app->db()->select('SELECT * FROM ' . $app->db()->table($table)) as $row) {
                self::assertStringNotContainsString($token, implode(' ', array_map(
                    static fn ($value): string => is_scalar($value) ? (string) $value : '', $row)),
                    $table . ' 에 평문 토큰이 남았습니다');
            }
        }
        // 운영자가 무엇이 나갔는지는 여전히 알 수 있다: 작업 본문은 치환 전 원문이고,
        // 수신자 본문은 값 하나만 가려진 같은 문장이다.
        self::assertSame('#{이름}님 #{링크} 에서 재설정하세요',
            $app->db()->select('SELECT * FROM ' . $app->db()->table('message_jobs'))[0]['body']);
        self::assertSame('회원이름님 *** 에서 재설정하세요',
            $app->db()->select('SELECT * FROM ' . $app->db()->table('message_recipients'))[0]['body']);
    }

    /**
     * 문자 하나만 켜 둔 앱. 알리고 계정은 저장돼 있고 문자 발송도 허용돼 있다 —
     * 남은 변수는 알리고가 이 발송을 받느냐 하나뿐이고, 그것을 전송기가 정한다.
     */
    private function smsOnly(App $app, string $event, string $body = '#{이름}님 안내입니다'): FakeAligoTransport
    {
        $transport = new FakeAligoTransport();
        // setAligo() 는 알림 설정과 발송기를 함께 끊으므로 채널 설정보다 먼저 와야 한다.
        $app->setAligo(new AligoService($app->db(), $transport, new SecretCipher('s')));
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $app->aligo()->settings->setEnabled('sms', true);
        $app->notifySettings()->save($event, $this->channels(['sms']) + ['sms_body' => $body]);

        return $transport;
    }

    /**
     * 소셜로 **처음 가입한** 사람도 가입 완료 안내를 받는다. createSocial() 이 그 자리에서
     * 인증까지 끝내므로 이 사람은 verifyEmail() 도 register() 도 지나지 않는다.
     */
    #[DataProvider('connectionProvider')]
    public function testANewSocialMemberIsWelcomed(array $config): void
    {
        $app = $this->boot($config);
        $this->enable($app, 'welcome');
        // 소셜 가입은 관리자가 한 명이라도 있어야 받는다.
        $app->accountService()->register($this->owner());
        $this->calls = [];

        $app->socialAuthService()->resolve(
            new SocialProfile('kakao', '42', 'social@example.com', true, '소셜회원'));

        $call = $this->lastCall();
        self::assertSame('welcome', $call['event']);
        self::assertSame('social@example.com', $call['to']->email);
        self::assertSame('소셜회원', $call['vars']['이름']);
    }

    /** 이미 있는 회원이 소셜 계정을 하나 더 붙이는 것은 가입이 아니다. */
    #[DataProvider('connectionProvider')]
    public function testAnExistingMemberLinkingASocialAccountIsNotWelcomed(array $config): void
    {
        $app = $this->boot($config);
        $this->enable($app, 'welcome');
        $app->accountService()->register($this->owner());
        $id = $app->users()->create('social@example.com',
            password_hash('member-password-123', PASSWORD_DEFAULT), '기존회원', false);
        $app->users()->verifyEmail($id);
        $this->calls = [];

        $app->socialAuthService()->resolve(
            new SocialProfile('kakao', '42', 'social@example.com', true, '소셜회원'));

        self::assertSame([], $this->calls);
    }

    /**
     * 화면이 세 결과를 각각 다르게 말하는지 본다. 경고가 없다는 것만으로 "갔다"를
     * 읽게 두면, 채널을 전부 꺼 둔 사이트에서 관리자는 알림이 나간 줄 안다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheAdminScreenSaysWhatActuallyHappenedToTheNotice(array $config): void
    {
        $app = $this->makeApp($config);
        $adminId = $app->users()->create('admin@example.com',
            password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($adminId);
        $memberId = $this->unverifiedMember($app);
        $app->users()->verifyEmail($memberId);
        $this->get($app, '/login');
        session_start();
        $_SESSION['user_id'] = $adminId;
        $_SESSION['session_epoch'] = 0;
        session_write_close();

        // 기본 설정은 이 알림의 메일을 켜 둔다 → 실제로 나갔다.
        $sent = $this->changeMemberPassword($app, $memberId, 'new-password-456');
        self::assertStringContainsString('notice=sent', $sent->getHeaderLine('Location'));
        self::assertStringContainsString('비밀번호 변경 알림을 보냈습니다',
            $this->body($this->get($app, '/admin/members', ['saved' => '1', 'notice' => 'sent'])));

        // 선택 채널을 전부 꺼도 코어 메일은 유지된다.
        $app->notifySettings()->save('password_changed', $this->channels([]));
        $off = $this->changeMemberPassword($app, $memberId, 'other-password-789');
        self::assertStringContainsString('notice=sent', $off->getHeaderLine('Location'));
        $offBody = $this->body($this->get($app, '/admin/members', ['saved' => '1', 'notice' => 'sent']));
        self::assertStringContainsString('비밀번호 변경 알림을 보냈습니다', $offBody);

        // 시도했는데 실패한 것은 또 다른 사실이다.
        $failedBody = $this->body($this->get($app, '/admin/members', ['saved' => '1', 'notice' => 'failed']));
        self::assertStringContainsString('보내지 못했습니다', $failedBody);

        // **셋은 서로를 배제한다.** 있어야 할 문구가 있는지만 보면, 세 상태에서 전부
        // 초록 띠를 함께 그리는 화면도 이 시험을 통과한다 — 그리고 그 초록 띠가 바로
        // 이 분기가 되풀이해 만들어 온 결함이다.
        self::assertStringNotContainsString('비밀번호 변경 알림을 보냈습니다', $failedBody);
    }

    /** 본인 화면도 같은 세 가지를 말한다 — 문구만 회원이 읽을 말로 다르다. */
    #[DataProvider('connectionProvider')]
    public function testTheMemberScreenSaysWhatActuallyHappenedToTheNotice(array $config): void
    {
        $app = $this->makeApp($config);
        $id = $app->users()->create('member@example.com',
            password_hash('old-password-123', PASSWORD_DEFAULT), '회원이름', false);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', ['csrf_token' => $_SESSION['csrf_token'],
            'email' => 'member@example.com', 'password' => 'old-password-123']);

        $sent = $this->changeOwnPassword($app, 'old-password-123', 'new-password-456');
        self::assertStringContainsString('notice=sent', $sent->getHeaderLine('Location'));
        self::assertStringContainsString('비밀번호 변경 알림을 보냈습니다',
            $this->body($this->get($app, '/account', ['saved' => '1', 'notice' => 'sent'])));

        $app->notifySettings()->save('password_changed', $this->channels([]));
        $off = $this->changeOwnPassword($app, 'new-password-456', 'other-password-789');
        self::assertStringContainsString('notice=sent', $off->getHeaderLine('Location'));
        $offBody = $this->body($this->get($app, '/account', ['saved' => '1', 'notice' => 'sent']));
        self::assertStringContainsString('비밀번호 변경 알림을 보냈습니다', $offBody);

        $failedBody = $this->body($this->get($app, '/account', ['saved' => '1', 'notice' => 'failed']));
        self::assertStringContainsString('보내지 못했습니다', $failedBody);

        // 관리자 화면과 같은 이유의 같은 울타리다(그 시험의 주석 참고).
        self::assertStringNotContainsString('비밀번호 변경 알림을 보냈습니다', $failedBody);
    }

    private function changeOwnPassword(App $app, string $current, string $password)
    {
        return $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '회원이름',
            'current_password' => $current, 'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    /** 소셜 신원으로 찾은 회원 행도 번호를 들고 온다 — UserRepository 의 두 finder 와 같은 이유다. */
    #[DataProvider('connectionProvider')]
    public function testTheSocialIdentityLookupCarriesThePhone(array $config): void
    {
        $app = $this->makeApp($config);
        $id = $this->unverifiedMember($app);
        $app->users()->updatePhone($id, '01012345678');
        $app->identities()->attach($id, 'kakao', '42');

        self::assertSame('01012345678', $app->identities()->findUser('kakao', '42')['phone']);
    }

    private function changeMemberPassword(App $app, int $memberId, string $password)
    {
        return $this->post($app, '/admin/members/' . $memberId . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com',
            'display_name' => '회원이름', 'status' => 'active',
            'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    /** 보낼 수 있다고 답해 놓고 터지는 채널. "설정이 꺼졌다"와 구별되는지 보기 위한 것이다. */
    private function throwingChannel(): ChannelInterface
    {
        return new class () implements ChannelInterface {
            public function key(): string
            {
                return 'mail';
            }

            public function available(string $event, Recipient $to): bool
            {
                return true;
            }

            public function send(string $event, Recipient $to, array $vars): void
            {
                throw new \RuntimeException('보내다 터졌다');
            }
        };
    }

    /**
     * 메일 채널 자리에 스파이를 끼운 앱. 기본 설정이 이 알림들의 메일을 켜 두므로
     * 그 자리로 다 들어온다.
     *
     * 스파이를 서비스가 아니라 **App 의 발송기 자리**에 끼우는 데에는 이유가 있다.
     * 서비스에 직접 끼우면 그 서비스를 지나는 알림만 보이고, 다른 데서 나가는 알림은
     * 보이지 않는다 — "환영 알림이 회원 저장소가 아니라 이 자리에서만 나간다"처럼
     * 무엇이 나가지 **않는가**를 보는 시험이 그대로 공허해진다. App 자리에 끼우면
     * 이 앱에서 나가는 알림은 어느 서비스에서 출발했든 전부 스파이에게 온다.
     */
    private function boot(array $config): App
    {
        $app = $this->makeApp($config);
        (new \ReflectionProperty(App::class, 'notifier'))
            ->setValue($app, new Notifier($app->notifySettings(), [$this->spyChannel()]));
        $this->calls = [];

        return $app;
    }

    private function spyChannel(): ChannelInterface
    {
        $record = function (string $event, Recipient $to, array $vars): void {
            $this->calls[] = ['event' => $event, 'to' => $to, 'vars' => $vars];
        };

        return new class ($record) implements ChannelInterface {
            public function __construct(private \Closure $record)
            {
            }

            public function key(): string
            {
                return 'mail';
            }

            public function available(string $event, Recipient $to): bool
            {
                return true;
            }

            public function send(string $event, Recipient $to, array $vars): void
            {
                ($this->record)($event, $to, $vars);
            }
        };
    }

    /**
     * save() 가 거절하는 조합을 저장소에 직접 심는다 — 이 검증이 생기기 전에 저장된 행,
     * 또는 DB 를 손으로 고친 행이 정확히 이 모양이다. 그런 행이 남아 있어도 알림이
     * 엉뚱한 곳으로 가지 않는다는 것을 보는 시험들이 이 통로로 온다.
     */
    private function forceChannels(App $app, string $event, array $on): void
    {
        $raw = [$event . '.configured' => '1'];
        foreach (NotifySettings::CHANNELS as $channel) {
            $raw[$event . '.' . $channel] = in_array($channel, $on, true) ? '1' : '0';
        }
        (new SettingsRepository($app->db()))->save($raw);
    }

    /** 이 이벤트의 메일만 켠다. 기본값이 꺼 둔 알림(welcome)을 시험에서 켜는 통로다. */
    private function enable(App $app, string $event): void
    {
        $app->notifySettings()->save($event, $this->channels(['mail']));
    }

    private function channels(array $on): array
    {
        $input = ['mail' => '0', 'alimtalk' => '0', 'sms' => '0', 'inbox' => '0'];
        foreach ($on as $channel) {
            $input[$channel] = '1';
        }

        return $input;
    }

    private function owner(array $extra = []): array
    {
        return $extra + [
            'email' => 'owner@example.com',
            'password' => 'safe-password-123',
            'password_confirmation' => 'safe-password-123',
        ];
    }

    /** 인증 전 회원 하나. 가입 화면을 거치지 않으므로 약관도 첫 관리자도 필요 없다. */
    private function unverifiedMember(App $app): int
    {
        return $app->users()->create(
            'member@example.com',
            password_hash('member-password-123', PASSWORD_DEFAULT),
            '회원이름',
            false
        );
    }

    /** 두 번째 가입부터는 약관이 공개돼 있어야 한다. 동의 칸 입력을 돌려준다. */
    private function publishLegalPages(App $app): array
    {
        $agreements = [];
        foreach ([['service', '이용약관'], ['privacy', '개인정보 처리방침']] as $order => $legal) {
            $id = $app->cms()->createPage([
                'slug' => $legal[0], 'title' => $legal[1], 'content' => $legal[1] . ' 본문',
                'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0,
                'is_consent' => 1,
            ]);
            $app->consentUses()->attach('signup', $id, true, $order);
            $agreements['agree_' . $id] = '1';
        }

        return $agreements;
    }

    private function lastCall(): array
    {
        self::assertNotSame([], $this->calls, '알림이 한 통도 채널에 닿지 않았다');

        return $this->calls[array_key_last($this->calls)];
    }

    private function tokenFrom(string $url): string
    {
        self::assertSame(1, preg_match('/[?&]token=([^\s&]+)/', $url, $matches));

        return rawurldecode($matches[1]);
    }
}
