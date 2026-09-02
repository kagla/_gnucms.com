<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Account\ConsentTrace;
use GnuCms\Error\DomainError;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AuthPageTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testLoginAndRegisterPagesRender(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        $login = $this->body($this->get($app, '/login'));
        $register = $this->body($this->get($app, '/register'));

        self::assertStringContainsString('<title>로그인', $login);
        self::assertStringContainsString('name="csrf_token"', $login);
        self::assertStringContainsString('<title>회원가입', $register);
        self::assertStringContainsString('password_confirmation', $register);
        self::assertStringContainsString('enctype="multipart/form-data"', $register);
        self::assertStringContainsString('name="profile_image"', $register);
        self::assertStringNotContainsString('name="name"', $register);

        $forgot = $this->body($this->get($app, '/forgot-password'));
        $reset = $this->body($this->get($app, '/reset-password', ['token' => 'example-token']));
        self::assertStringContainsString('<title>비밀번호 찾기', $forgot);
        self::assertStringContainsString('<title>새 비밀번호 설정', $reset);
        self::assertStringContainsString('value="example-token"', $reset);
    }

    #[DataProvider('connectionProvider')]
    public function testRegistrationAndPasswordLoginsRecordIpHistory(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->get($app, '/register');
        $registered = $this->post($app, '/register', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'owner@example.com',
            'password' => 'owner-password-123', 'password_confirmation' => 'owner-password-123',
        ], ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'Register/Test']);
        self::assertSame(303, $registered->getStatusCode());
        $owner = $app->users()->findByEmail('owner@example.com');
        self::assertSame('203.0.113.10', $owner['registered_ip']);

        $this->post($app, '/logout', ['csrf_token' => $_SESSION['csrf_token']]);
        $this->get($app, '/login');
        $failed = $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'owner@example.com', 'password' => 'wrong',
        ], ['REMOTE_ADDR' => '203.0.113.11', 'HTTP_USER_AGENT' => 'Login/Failure']);
        self::assertSame(422, $failed->getStatusCode());
        $success = $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'owner@example.com',
            'password' => 'owner-password-123',
        ], ['REMOTE_ADDR' => '203.0.113.12', 'HTTP_USER_AGENT' => 'Login/Success']);
        self::assertSame(303, $success->getStatusCode());

        $events = $app->db()->select(
            'SELECT auth_method, result, client_ip FROM login_events WHERE user_id = ? ORDER BY id ASC',
            [(int) $owner['id']]
        );
        self::assertSame([
            ['auth_method' => 'password', 'result' => 'success', 'client_ip' => '203.0.113.10'],
            ['auth_method' => 'password', 'result' => 'failure', 'client_ip' => '203.0.113.11'],
            ['auth_method' => 'password', 'result' => 'success', 'client_ip' => '203.0.113.12'],
        ], $events);
    }

    #[DataProvider('connectionProvider')]
    public function testConfiguredSocialProvidersAppearOnAuthPages(array $dbConfig): void
    {
        $oauth = [];
        foreach (['google', 'naver', 'kakao', 'github'] as $provider) {
            $oauth[$provider] = ['client_id' => 'test-id', 'client_secret' => 'test-secret'];
        }
        $app = $this->makeApp($dbConfig, [
            'app' => ['url' => 'https://community.example.com'],
            'oauth' => $oauth,
        ]);

        $login = $this->body($this->get($app, '/login'));
        $register = $this->body($this->get($app, '/register'));

        self::assertStringContainsString('Google로 계속하기', $login);
        self::assertStringContainsString('네이버로 계속하기', $login);
        self::assertStringContainsString('카카오로 계속하기', $login);
        self::assertStringNotContainsString('GitHub로 계속하기', $login);
        self::assertStringContainsString('/auth/google', $login);
        self::assertLessThan(strpos($login, '네이버로 계속하기'), strpos($login, 'Google로 계속하기'));
        self::assertLessThan(strpos($login, '카카오로 계속하기'), strpos($login, '네이버로 계속하기'));
        self::assertLessThan(strpos($login, 'name="email"'), strpos($login, 'Google로 계속하기'));
        self::assertLessThan(strpos($register, 'name="email"'), strpos($register, 'Google로 계속하기'));
        self::assertStringContainsString('또는 이메일로 계속', $login);
        self::assertStringContainsString('또는 이메일로 계속', $register);
    }

    #[DataProvider('connectionProvider')]
    public function testFirstSignupLogsInAsOwnerWithoutSendingEmail(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->get($app, '/register');
        $response = $this->post($app, '/register', [
            'csrf_token' => $_SESSION['csrf_token'],
            'email' => 'owner@example.com',
            'password' => 'owner-password-123',
            'password_confirmation' => 'owner-password-123',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin', $response->getHeaderLine('Location'));
        $owner = $app->users()->findByEmail('owner@example.com');
        self::assertSame(1, (int) $owner['is_admin']);
        self::assertSame(1, (int) $owner['email_verified']);
        self::assertSame(200, $this->get($app, '/admin')->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testMemberSignupRequiresPublishedLegalContentAndAgreement(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $ownerId = $app->users()->create('owner@example.com', password_hash('owner-password-123', PASSWORD_DEFAULT), '소유자', true);
        $app->users()->verifyEmail($ownerId);

        self::assertSame(403, $this->get($app, '/register')->getStatusCode());
        $ids = [];
        foreach ([['service', '이용약관'], ['privacy', '개인정보 처리방침']] as $order => $legal) {
            $ids[$legal[0]] = $app->cms()->createPage([
                'slug' => $legal[0], 'title' => $legal[1], 'content' => $legal[1] . ' 본문',
                'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0,
                'is_consent' => 1,
            ]);
            $app->consentUses()->attach('signup', $ids[$legal[0]], true, $order);
        }

        $form = $this->body($this->get($app, '/register'));
        self::assertStringContainsString('name="agree_' . $ids['service'] . '"', $form);
        self::assertStringContainsString('name="agree_' . $ids['privacy'] . '"', $form);
        self::assertStringContainsString('data-consent-all', $form);
        self::assertStringContainsString('약관 모두 동의', $form);
        self::assertStringContainsString('필수·선택 약관에 모두 동의한 것으로 봅니다.', $form);
        self::assertSame(2, substr_count($form, 'value="1" data-consent-item'));
        self::assertStringContainsString('href="/terms/service"', $form);
        self::assertStringContainsString('href="/terms/privacy"', $form);
        self::assertSame(200, $this->get($app, '/terms/service')->getStatusCode());
        self::assertSame(200, $this->get($app, '/terms/privacy')->getStatusCode());
        // 옛 주소로 들어와도 정식 주소로 보낸다.
        self::assertSame(301, $this->get($app, '/content/terms')->getStatusCode());
        self::assertSame('/terms/service', $this->get($app, '/content/terms')->getHeaderLine('Location'));
        $response = $this->post($app, '/register', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com',
            'password' => 'member-password-123', 'password_confirmation' => 'member-password-123',
        ]);
        self::assertSame(422, $response->getStatusCode());
        // 검증 오류와 폼 칸이 모두 agree_{id} 로 맞춰져(6과제) 안내 문구가 다시 뜬다.
        self::assertStringContainsString('동의해야 가입할 수 있습니다', $this->body($response));
        self::assertNull($app->users()->findByEmail('member@example.com'));
    }

    /**
     * 선택 항목은 가입을 막지 않는다. 동의하지 않았다는 사실과 증적이 함께 남는다.
     * 화면(auth/_consents.php)도 이제 내용 id 로 칸 이름을 만들고 선택 항목에
     * '선택' 배지를 붙이므로, 폼 마크업까지 함께 확인한다.
     */
    #[DataProvider('connectionProvider')]
    public function testOptionalConsentDoesNotBlockSignupAndTraceIsRecorded(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        // 첫 사람은 실제 가입 경로로 만든다. 그래야 '첫 관리자' 자리가 채워져
        // 다음 가입자가 관리자로 올라가지 않고 동의 기록을 정상으로 남긴다.
        $app->accountService()->register([
            'email' => 'owner@example.com',
            'password' => 'owner-password-123', 'password_confirmation' => 'owner-password-123',
        ]);
        // register() 는 가입 게이트로 legalDocuments() 를 여전히 부른다(terms/privacy
        // 슬러그가 공개돼 있어야 함). privacy 는 가입 자리에 붙이지 않아 동의 항목
        // 목록에는 안 잡히지만, 게이트를 통과시키려면 공개는 돼 있어야 한다.
        $app->cms()->createPage([
            'slug' => 'privacy', 'title' => '개인정보 처리방침', 'content' => '개인정보 처리방침 본문',
            'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0,
            'sort_order' => 0, 'is_consent' => 1,
        ]);
        $ids = [];
        foreach ([['service', '이용약관', true, 10], ['marketing', '마케팅 정보 수신', false, 30]] as $doc) {
            $ids[$doc[0]] = $app->cms()->createPage([
                'slug' => $doc[0], 'title' => $doc[1], 'content' => $doc[1] . ' 본문',
                'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0,
                'sort_order' => 0, 'is_consent' => 1,
            ]);
            $app->consentUses()->attach('signup', $ids[$doc[0]], $doc[2], $doc[3]);
        }

        $form = $this->body($this->get($app, '/register'));
        self::assertStringContainsString('name="agree_' . $ids['marketing'] . '"', $form);
        self::assertStringContainsString('선택', $form);

        // 필수(terms) 를 체크하지 않으면 가입 자체가 막힌다.
        try {
            $app->accountService()->register([
                'email' => 'member@example.com',
                'password' => 'member-password-123', 'password_confirmation' => 'member-password-123',
            ]);
            self::fail('필수 동의 없이 가입되면 안 된다.');
        } catch (DomainError $e) {
            self::assertArrayHasKey('agree_' . $ids['service'], $e->details());
        }

        // 필수만 체크하고 선택(marketing)은 비운 채로 가입한다. 증적도 함께 남긴다.
        $trace = new ConsentTrace('203.0.113.7', 'PHPUnit-Agent/1.0');
        $app->accountService()->register([
            'email' => 'member@example.com',
            'password' => 'member-password-123', 'password_confirmation' => 'member-password-123',
            'agree_' . $ids['service'] => '1',
        ], $trace);

        $member = $app->users()->findByEmail('member@example.com');
        $agreed = [];
        foreach ($app->consents()->forSubject('user', (int) $member['id']) as $row) {
            $agreed[$row['consent_type']] = (int) $row['agreed'];
            self::assertSame('signup', $row['scope']);
            self::assertSame('203.0.113.7', $row['agreed_ip']);
            self::assertSame('PHPUnit-Agent/1.0', $row['agreed_ua']);
        }
        self::assertSame(['service' => 1, 'marketing' => 0], $agreed);
    }
    /** 인증이 안 끝난 사람이 맞는 비밀번호로 오면, 왜 안 되는지와 다시 보내는 길을 보여 준다. */
    #[DataProvider('connectionProvider')]
    public function testUnverifiedLoginExplainsAndOffersResend(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->users()->create('new@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '새회원', false);

        $this->get($app, '/login');
        $response = $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'new@example.com', 'password' => 'member-password-123',
        ]);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->body($response);
        self::assertStringContainsString('아직 이메일 인증이 끝나지 않았습니다', $body);
        self::assertStringContainsString('action="/verify-email/resend"', $body);
        self::assertStringContainsString('value="new@example.com"', $body);

        // 비밀번호가 틀리면 인증 얘기는 하지 않는다 — 계정 존재 여부를 흘리지 않는다.
        $wrong = $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'new@example.com', 'password' => 'nope',
        ]);
        self::assertStringNotContainsString('/verify-email/resend', $this->body($wrong));

        $resent = $this->post($app, '/verify-email/resend', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'new@example.com',
        ]);
        self::assertSame(200, $resent->getStatusCode(), $this->body($resent));
        self::assertStringContainsString('이메일을 확인해', $this->body($resent));
    }

}
