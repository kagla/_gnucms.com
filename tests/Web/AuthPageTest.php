<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

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
        self::assertStringNotContainsString('name="name"', $register);

        $forgot = $this->body($this->get($app, '/forgot-password'));
        $reset = $this->body($this->get($app, '/reset-password', ['token' => 'example-token']));
        self::assertStringContainsString('<title>비밀번호 찾기', $forgot);
        self::assertStringContainsString('<title>새 비밀번호 설정', $reset);
        self::assertStringContainsString('value="example-token"', $reset);
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

        self::assertStringContainsString('Google로 계속하기', $login);
        self::assertStringContainsString('네이버로 계속하기', $login);
        self::assertStringContainsString('카카오로 계속하기', $login);
        self::assertStringContainsString('GitHub로 계속하기', $login);
        self::assertStringContainsString('/auth/google', $login);
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
        foreach ([['terms', '이용약관'], ['privacy', '개인정보 처리방침']] as $order => $legal) {
            $id = $app->cms()->createPage([
                'slug' => $legal[0], 'title' => $legal[1], 'content' => $legal[1] . ' 본문',
                'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0,
                // 약관이라는 표시. 옛 화면(가입 폼 템플릿)은 아직 옛 칸으로 이름과 필수
                // 여부를 읽으므로 함께 채워 둔다.
                'is_consent' => 1, 'consent_key' => $legal[0], 'consent_order' => $order,
                'consent_required' => 1,
            ]);
            $app->consentUses()->attach('signup', $id, true, $order);
        }

        $form = $this->body($this->get($app, '/register'));
        self::assertStringContainsString('name="agree_terms"', $form);
        self::assertStringContainsString('name="agree_privacy"', $form);
        self::assertStringContainsString('href="/content/terms"', $form);
        self::assertStringContainsString('href="/content/privacy"', $form);
        self::assertSame(200, $this->get($app, '/content/terms')->getStatusCode());
        self::assertSame(200, $this->get($app, '/content/privacy')->getStatusCode());
        $response = $this->post($app, '/register', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com',
            'password' => 'member-password-123', 'password_confirmation' => 'member-password-123',
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('이용약관에 동의', $this->body($response));
    }

    /** 마케팅 수신처럼 안 해도 가입되는 항목. 동의하지 않았다는 사실도 기록에 남는다. */
    #[DataProvider('connectionProvider')]
    public function testOptionalConsentDoesNotBlockSignupButIsRecorded(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        // 첫 사람은 실제 가입 경로로 만든다. 그래야 '첫 관리자' 자리가 채워져
        // 다음 가입자가 관리자로 올라가지 않고 동의 기록을 정상으로 남긴다.
        $app->accountService()->register([
            'email' => 'owner@example.com',
            'password' => 'owner-password-123', 'password_confirmation' => 'owner-password-123',
        ]);
        foreach ([['terms', '이용약관', 1], ['privacy', '개인정보 처리방침', 1], ['marketing', '마케팅 정보 수신', 0]] as $order => $doc) {
            $id = $app->cms()->createPage([
                'slug' => $doc[0], 'title' => $doc[1], 'content' => $doc[1] . ' 본문',
                'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0,
                // 약관이라는 표시. 옛 화면(가입 폼 템플릿)은 아직 옛 칸으로 이름과 필수
                // 여부를 읽으므로 함께 채워 둔다.
                'is_consent' => 1, 'consent_key' => $doc[0], 'consent_order' => $order,
                'consent_required' => $doc[2],
            ]);
            $app->consentUses()->attach('signup', $id, (bool) $doc[2], $order);
        }

        $form = $this->body($this->get($app, '/register'));
        self::assertStringContainsString('name="agree_marketing"', $form);
        self::assertStringContainsString('선택', $form);

        // 선택 항목을 비운 채로도 가입이 된다.
        $response = $this->post($app, '/register', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com',
            'password' => 'member-password-123', 'password_confirmation' => 'member-password-123',
            'agree_terms' => '1', 'agree_privacy' => '1',
        ]);
        self::assertSame(200, $response->getStatusCode(), $this->body($response));
        self::assertStringContainsString('이메일을 확인해', $this->body($response));

        $member = $app->users()->findByEmail('member@example.com');
        $agreed = [];
        foreach ($app->consents()->forUser((int) $member['id']) as $row) {
            $agreed[$row['consent_type']] = (int) $row['agreed'];
        }
        self::assertSame(['terms' => 1, 'privacy' => 1, 'marketing' => 0], $agreed);
    }
}
