<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\App;
use ApiBoard\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** 글 수정과 삭제. 비회원 글은 비밀번호로 주인을 확인한다. */
final class PostEditTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testGuestEditsOwnPostWithPassword(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $this->seedGuestPost($app);

        $form = $this->get($app, '/posts/' . $id . '/edit');
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('name="password"', $this->body($form));

        $response = $this->post($app, '/posts/' . $id . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'password'   => 'post-pass-1',
            'title'      => '고친 제목',
            'content'    => '고친 본문',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('고친 제목', $this->body($this->get($app, '/posts/' . $id)));
    }

    /** 비밀번호가 틀리면 오류 화면이 아니라 폼에서 이유를 알려 줘야 한다. */
    #[DataProvider('connectionProvider')]
    public function testWrongPasswordComesBackToTheForm(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $this->seedGuestPost($app);

        $this->get($app, '/posts/' . $id . '/edit');
        $response = $this->post($app, '/posts/' . $id . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'password'   => '틀린비밀번호',
            'title'      => '고친 제목',
            'content'    => '고친 본문',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('name="password"', $this->body($response));
        self::assertStringNotContainsString('고친 제목', $this->body($this->get($app, '/posts/' . $id)));
    }

    #[DataProvider('connectionProvider')]
    public function testDeleteRemovesThePostFromTheList(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $this->seedGuestPost($app);

        $this->get($app, '/posts/' . $id . '/edit');
        $response = $this->post($app, '/posts/' . $id . '/delete', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'password'   => 'post-pass-1',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/boards/free', $response->getHeaderLine('Location'));
        self::assertSame(404, $this->get($app, '/posts/' . $id)->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testDeleteWithWrongPasswordKeepsThePost(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $this->seedGuestPost($app);

        $this->get($app, '/posts/' . $id . '/edit');
        $response = $this->post($app, '/posts/' . $id . '/delete', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'password'   => '틀림',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(200, $this->get($app, '/posts/' . $id)->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testCsrfTokenIsRequiredForDelete(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $this->seedGuestPost($app);

        $response = $this->post($app, '/posts/' . $id . '/delete', ['csrf_token' => 'wrong']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(200, $this->get($app, '/posts/' . $id)->getStatusCode());
    }

    /** 글 보기 화면에 수정으로 가는 길이 있어야 한다. */
    #[DataProvider('connectionProvider')]
    public function testPostPageOffersTheEditLink(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $this->seedGuestPost($app);

        self::assertStringContainsString(
            '/posts/' . $id . '/edit',
            $this->body($this->get($app, '/posts/' . $id))
        );
    }

    private function seedGuestPost(App $app): int
    {
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유게시판', 'perm_write' => 'guest',
        ]);
        $this->get($app, '/boards/free/write');
        $this->post($app, '/boards/free/write', [
            'csrf_token'  => $_SESSION['csrf_token'] ?? '',
            'author_name' => '아무개',
            'password'    => 'post-pass-1',
            'title'       => '원래 제목',
            'content'     => '원래 본문',
        ]);

        return (int) $app->db()->selectOne('SELECT MAX(id) AS id FROM ' . $app->db()->q('posts'))['id'];
    }
}
