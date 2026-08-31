<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** 비회원 글쓰기: 폼이 이름·비밀번호 칸을 내주고, 새 주소는 /new 다. */
final class GuestWriteTest extends WebTestCase
{
    private function makeGuestBoard(array $dbConfig): \GnuCms\App
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'guest',
        ]);

        return $app;
    }

    #[DataProvider('connectionProvider')]
    public function testGuestSeesNameAndPasswordFieldsOnWriteForm(array $dbConfig): void
    {
        $app = $this->makeGuestBoard($dbConfig);

        $body = $this->body($this->get($app, '/boards/free/new'));

        self::assertStringContainsString('name="author_name"', $body);
        self::assertStringContainsString('name="password"', $body);
        self::assertStringContainsString('수정·삭제에 씁니다', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testMemberDoesNotSeeGuestFields(array $dbConfig): void
    {
        $app = $this->makeGuestBoard($dbConfig);
        $id = $app->users()->create('user@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '회원사람');
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'user@example.com', 'password' => 'member-password-123',
        ]);

        $body = $this->body($this->get($app, '/boards/free/new'));

        self::assertStringNotContainsString('name="author_name"', $body);
        self::assertStringNotContainsString('name="password"', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestCanWriteAPostThroughTheForm(array $dbConfig): void
    {
        $app = $this->makeGuestBoard($dbConfig);
        $this->get($app, '/boards/free/new');

        $created = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '손님의 글', 'content' => '본문입니다',
            'author_name' => '지나가던손님', 'password' => 'guest-pass-123',
        ]);

        self::assertSame(303, $created->getStatusCode());
        $show = $this->body($this->get($app, $created->getHeaderLine('Location')));
        self::assertStringContainsString('손님의 글', $show);
        self::assertStringContainsString('지나가던손님', $show);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestMissingPasswordSeesTheErrorOnItsField(array $dbConfig): void
    {
        $app = $this->makeGuestBoard($dbConfig);
        $this->get($app, '/boards/free/new');

        $response = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '손님의 글', 'content' => '본문입니다', 'author_name' => '지나가던손님',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->body($response);
        // 비밀번호 칸 바로 아래에 오류 문구가 보여야 한다. 예전에는 칸 자체가 없어 아무것도 안 보였다.
        self::assertStringContainsString('name="password"', $body);
        self::assertStringContainsString('필수 항목입니다', $body);
        // 다시 그린 폼에 입력값이 남는다.
        self::assertStringContainsString('지나가던손님', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testOldWriteUrlRedirectsToNew(array $dbConfig): void
    {
        $app = $this->makeGuestBoard($dbConfig);

        $response = $this->get($app, '/boards/free/write');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/boards/free/new', $response->getHeaderLine('Location'));
    }

    #[DataProvider('connectionProvider')]
    public function testWrongGuestPasswordSaysSoInsteadOfAskingToLogIn(array $dbConfig): void
    {
        $app = $this->makeGuestBoard($dbConfig);
        $this->get($app, '/boards/free/new');
        $created = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '손님의 글', 'content' => '본문입니다',
            'author_name' => '지나가던손님', 'password' => 'guest-pass-123',
        ]);
        $postUrl = $created->getHeaderLine('Location');

        $response = $this->post($app, $postUrl . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '고친 제목', 'content' => '본문입니다', 'password' => 'wrong-pass-999',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->body($response);
        self::assertStringContainsString('비밀번호가 올바르지 않습니다', $body);
        self::assertStringNotContainsString('로그인이 필요합니다', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testMissingGuestPasswordAsksForIt(array $dbConfig): void
    {
        $app = $this->makeGuestBoard($dbConfig);
        $this->get($app, '/boards/free/new');
        $created = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '손님의 글', 'content' => '본문입니다',
            'author_name' => '지나가던손님', 'password' => 'guest-pass-123',
        ]);

        $response = $this->post($app, $created->getHeaderLine('Location') . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '고친 제목', 'content' => '본문입니다',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('비밀번호를 입력해 주세요', $this->body($response));
    }

    #[DataProvider('connectionProvider')]
    public function testGuestEditingMemberPostStillGetsUnauthorized(array $dbConfig): void
    {
        $app = $this->makeGuestBoard($dbConfig);
        $post = $app->postService()->create($this->adminAcl(), 'free', ['title' => '회원 글', 'content' => '본문']);

        try {
            $app->postService()->update(new \GnuCms\Auth\Acl(\GnuCms\Auth\Identity::guest()), $post['id'], [
                'title' => '가로채기', 'content' => '본문', 'password' => 'whatever-123',
            ]);
            self::fail('401 이 나와야 한다');
        } catch (\GnuCms\Error\DomainError $e) {
            // 회원 글은 비밀번호가 소유 증명이 아니므로 기존대로 로그인 안내다.
            self::assertSame(401, $e->status());
            self::assertSame('로그인이 필요합니다.', $e->getMessage());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testWrongGuestPasswordOnDeleteSaysSoToo(array $dbConfig): void
    {
        $app = $this->makeGuestBoard($dbConfig);
        $this->get($app, '/boards/free/new');
        $created = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '손님의 글', 'content' => '본문입니다',
            'author_name' => '지나가던손님', 'password' => 'guest-pass-123',
        ]);

        $response = $this->post($app, $created->getHeaderLine('Location') . '/delete', [
            'csrf_token' => $_SESSION['csrf_token'], 'password' => 'wrong-pass-999',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->body($response);
        self::assertStringContainsString('비밀번호가 올바르지 않습니다', $body);
        self::assertStringNotContainsString('입력값을 확인해 주세요', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testGuestNameLongerThanTwentyCharsIsRejected(array $dbConfig): void
    {
        $app = $this->makeGuestBoard($dbConfig);
        $this->get($app, '/boards/free/new');

        $response = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '손님 글', 'content' => '본문',
            'author_name' => str_repeat('가', 21), 'password' => 'guest-pass-123',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('20자', $this->body($response));
    }
}

