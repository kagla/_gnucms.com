<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TurnstileWebTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    private function config(): array
    {
        return [
            'enabled' => true,
            'site_key' => 'test-site-key',
            'secret_key' => 'test-secret-key',
            'hostname' => 'example.test',
            // 테스트 토큰 자체를 기대 action으로 써서 각 폼의 action 전달을 함께 검증한다.
            'transport' => static fn (string $url, array $fields): array => [
                'success' => true,
                'hostname' => 'example.test',
                'action' => (string) ($fields['response'] ?? ''),
            ],
        ];
    }

    #[DataProvider('connectionProvider')]
    public function testGuestPostRequiresTurnstileButAcceptsValidToken(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['turnstile' => $this->config()]);
        $app->cms()->saveSettings(['guest_write_enabled' => '1']);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'guest',
        ]);

        $form = $this->body($this->get($app, '/boards/free/new'));
        self::assertStringContainsString('data-sitekey="test-site-key"', $form);
        self::assertStringContainsString('data-action="post_create"', $form);

        $body = [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '손님 글', 'content' => '본문',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ];
        $missing = $this->post($app, '/boards/free/new', $body, ['REMOTE_ADDR' => '203.0.113.9']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertStringContainsString('자동 등록 방지 확인을 완료해 주세요.', $this->body($missing));

        $body['cf-turnstile-response'] = 'post_create';
        self::assertSame(303, $this->post(
            $app, '/boards/free/new', $body, ['REMOTE_ADDR' => '203.0.113.9']
        )->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testLoginAddsTurnstileOnlyAfterThreeFailures(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['turnstile' => $this->config()]);
        $id = $app->users()->create('user@example.com', password_hash('correct-pass-123', PASSWORD_DEFAULT), '사용자');
        $app->users()->verifyEmail($id);

        $initial = $this->body($this->get($app, '/login'));
        self::assertStringNotContainsString('data-action="login"', $initial);

        $third = null;
        for ($i = 0; $i < 3; $i++) {
            $third = $this->post($app, '/login', [
                'csrf_token' => $_SESSION['csrf_token'], 'email' => 'user@example.com',
                'password' => 'wrong-' . $i,
            ], ['REMOTE_ADDR' => '203.0.113.9']);
        }
        self::assertNotNull($third);
        self::assertStringContainsString('data-action="login"', $this->body($third));

        $withoutCaptcha = $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'user@example.com',
            'password' => 'correct-pass-123',
        ], ['REMOTE_ADDR' => '203.0.113.9']);
        self::assertSame(422, $withoutCaptcha->getStatusCode());
        self::assertStringContainsString('자동 등록 방지 확인을 완료해 주세요.', $this->body($withoutCaptcha));

        $withCaptcha = $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'user@example.com',
            'password' => 'correct-pass-123', 'cf-turnstile-response' => 'login',
        ], ['REMOTE_ADDR' => '203.0.113.9']);
        self::assertSame(303, $withCaptcha->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCommentRequiresTurnstile(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['turnstile' => $this->config()]);
        $app->cms()->saveSettings(['guest_write_enabled' => '1']);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'guest', 'perm_comment' => 'guest',
        ]);
        $post = $app->postService()->create($this->adminAcl(), 'free', ['title' => '글', 'content' => '본문']);

        $page = $this->body($this->get($app, '/posts/' . $post['id']));
        self::assertStringContainsString('data-action="comment_create"', $page);
        $body = [
            'csrf_token' => $_SESSION['csrf_token'], 'content' => '댓글',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ];
        self::assertSame(422, $this->post(
            $app, '/posts/' . $post['id'] . '/comments', $body, ['REMOTE_ADDR' => '203.0.113.9']
        )->getStatusCode());

        $body['cf-turnstile-response'] = 'comment_create';
        self::assertSame(303, $this->post(
            $app, '/posts/' . $post['id'] . '/comments', $body, ['REMOTE_ADDR' => '203.0.113.9']
        )->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testRegistrationAndPasswordResetFormsIncludeTurnstile(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig, ['turnstile' => $this->config()]);
        $app->cms()->saveSettings(['registration_enabled' => '1']);

        self::assertStringContainsString(
            'data-action="register"',
            $this->body($this->get($app, '/register'))
        );
        self::assertStringContainsString(
            'data-action="password_reset"',
            $this->body($this->get($app, '/forgot-password'))
        );

        $forgot = $this->post($app, '/forgot-password', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'nobody@example.com',
        ], ['REMOTE_ADDR' => '203.0.113.9']);
        self::assertSame(422, $forgot->getStatusCode());
        self::assertStringContainsString('자동 등록 방지 확인을 완료해 주세요.', $this->body($forgot));

        $register = $this->post($app, '/register', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'new@example.com',
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ], ['REMOTE_ADDR' => '203.0.113.9']);
        self::assertSame(422, $register->getStatusCode());
        self::assertStringContainsString('자동 등록 방지 확인을 완료해 주세요.', $this->body($register));
    }
}
