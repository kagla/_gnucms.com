<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Error\DomainError;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** 비밀번호를 받는 세 경로(로그인·비회원 수정·비밀글)가 대입 시도를 잠그는지. */
final class PasswordThrottleWebTest extends WebTestCase
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

    #[DataProvider('connectionProvider')]
    public function testLoginLocksAfterFiveWrongPasswords(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('victim@example.com', password_hash('correct-pass-123', PASSWORD_DEFAULT), '피해자');
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');

        for ($i = 0; $i < 5; $i++) {
            $response = $this->post($app, '/login', [
                'csrf_token' => $_SESSION['csrf_token'], 'email' => 'victim@example.com', 'password' => 'wrong-' . $i,
            ]);
            self::assertSame(422, $response->getStatusCode());
        }

        // 잠긴 뒤에는 맞는 비밀번호도 통과하지 못한다. 대입이 성공 시점을 알 수 없게.
        $locked = $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'victim@example.com', 'password' => 'correct-pass-123',
        ]);
        self::assertSame(422, $locked->getStatusCode());
        self::assertStringContainsString('5회 잘못 입력했습니다', $this->body($locked));
    }

    /** 대소문자만 다른 이메일은 authenticate() 가 소문자로 정규화한 뒤 스로틀 키를 만들므로 같은 잠금을 공유한다. */
    #[DataProvider('connectionProvider')]
    public function testLoginLockKeyIgnoresEmailCase(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('victim@example.com', password_hash('correct-pass-123', PASSWORD_DEFAULT), '피해자');
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');

        for ($i = 0; $i < 5; $i++) {
            $response = $this->post($app, '/login', [
                'csrf_token' => $_SESSION['csrf_token'], 'email' => 'Victim@Example.com', 'password' => 'wrong-' . $i,
            ]);
            self::assertSame(422, $response->getStatusCode());
        }

        $locked = $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'victim@example.com', 'password' => 'correct-pass-123',
        ]);
        self::assertSame(422, $locked->getStatusCode());
        self::assertStringContainsString('5회 잘못 입력했습니다', $this->body($locked));
    }

    #[DataProvider('connectionProvider')]
    public function testSuccessfulLoginClearsEarlierFailures(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('user@example.com', password_hash('correct-pass-123', PASSWORD_DEFAULT), '사용자');
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');

        for ($i = 0; $i < 4; $i++) {
            $this->post($app, '/login', [
                'csrf_token' => $_SESSION['csrf_token'], 'email' => 'user@example.com', 'password' => 'wrong-' . $i,
            ]);
        }
        $ok = $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'user@example.com', 'password' => 'correct-pass-123',
        ]);
        self::assertSame(303, $ok->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testGuestPostPasswordLocksAfterFiveWrongTries(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['guest_write_enabled' => '1']);
        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유', 'perm_write' => 'guest']);
        $this->get($app, '/boards/free/new');
        $created = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '손님 글', 'content' => '본문',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ]);
        $editUrl = $created->getHeaderLine('Location') . '/edit';

        for ($i = 0; $i < 5; $i++) {
            $response = $this->post($app, $editUrl, [
                'csrf_token' => $_SESSION['csrf_token'], 'title' => '고침', 'content' => '본문', 'password' => 'wrong-' . $i,
            ]);
            self::assertSame(422, $response->getStatusCode());
        }

        $locked = $this->post($app, $editUrl, [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '고침', 'content' => '본문', 'password' => 'guest-pass-123',
        ]);
        self::assertSame(422, $locked->getStatusCode());
        self::assertStringContainsString('5회 잘못 입력했습니다', $this->body($locked));
    }

    #[DataProvider('connectionProvider')]
    public function testSecretPostPasswordLocksAfterFiveWrongTries(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'guest', 'use_secret' => true,
        ]);
        $post = $app->postService()->create($acl, 'free', [
            'title' => '비밀', 'content' => '본문', 'is_secret' => '1',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ]);

        for ($i = 0; $i < 5; $i++) {
            try {
                $app->postService()->loadForRead($app->guestAcl(), $post['id'], 'wrong-' . $i);
                self::fail('비밀글은 열리면 안 된다');
            } catch (DomainError $e) {
                self::assertContains($e->status(), [403, 422]);
            }
        }

        try {
            $app->postService()->loadForRead($app->guestAcl(), $post['id'], 'guest-pass-123');
            self::fail('잠긴 뒤에는 맞는 비밀번호도 막혀야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertStringContainsString('5회 잘못 입력했습니다', $e->details()['password']);
        }
    }
}
