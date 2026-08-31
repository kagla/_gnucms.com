<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** 사이트 설정의 쓰기 규칙(본문 최소 글자수)이 글 저장에 걸리는지. */
final class WriteRulesTest extends WebTestCase
{
    private function guestBoardApp(array $dbConfig, string $minChars): \GnuCms\App
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['post_min_chars' => $minChars]);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'perm_write' => 'guest',
        ]);

        return $app;
    }

    #[DataProvider('connectionProvider')]
    public function testShortContentIsRejectedWhenMinimumIsSet(array $dbConfig): void
    {
        $app = $this->guestBoardApp($dbConfig, '10');
        $this->get($app, '/boards/free/new');

        $response = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '제목',
            // 태그와 공백을 빼면 4자다. 편집기가 감싼 태그로 길이를 속일 수 없어야 한다.
            'content' => '<p>  본문임다  </p>',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('10자 이상', $this->body($response));
    }

    #[DataProvider('connectionProvider')]
    public function testLongEnoughContentPasses(array $dbConfig): void
    {
        $app = $this->guestBoardApp($dbConfig, '10');
        $this->get($app, '/boards/free/new');

        $response = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '제목',
            'content' => '<p>열 글자는 넘는 본문입니다</p>',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ]);

        self::assertSame(303, $response->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testZeroMeansNoMinimum(array $dbConfig): void
    {
        $app = $this->guestBoardApp($dbConfig, '0');
        $this->get($app, '/boards/free/new');

        $response = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '제목', 'content' => '짧다',
            'author_name' => '손님', 'password' => 'guest-pass-123',
        ]);

        self::assertSame(303, $response->getStatusCode());
    }
}
