<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AttachmentFormTest extends WebTestCase
{
    private function loggedInApp(array $dbConfig, bool $useFile): \GnuCms\App
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com', 'password' => 'admin-password-123',
        ]);
        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유', 'use_file' => $useFile]);

        return $app;
    }

    #[DataProvider('connectionProvider')]
    public function testWriteFormShowsAttachmentUiOnlyWhenBoardAllowsFiles(array $dbConfig): void
    {
        $app = $this->loggedInApp($dbConfig, true);
        $body = $this->body($this->get($app, '/boards/free/write'));
        self::assertStringContainsString('data-attachments', $body);
        self::assertStringContainsString('/boards/free/files', $body);

        $app2 = $this->loggedInApp($dbConfig, false);
        self::assertStringNotContainsString('data-attachments', $this->body($this->get($app2, '/boards/free/write')));
    }

    #[DataProvider('connectionProvider')]
    public function testSubmittedOrderIsStoredAndShown(array $dbConfig): void
    {
        $app = $this->loggedInApp($dbConfig, true);
        $acl = $this->adminAcl();
        $first = $app->attachments()->upload($acl, 'free', $this->fakeUpload('가.txt', '1'));
        $second = $app->attachments()->upload($acl, 'free', $this->fakeUpload('나.txt', '2'));

        $created = $this->post($app, '/boards/free/write', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '순서 시험', 'content' => '본문',
            // 드래그로 순서를 바꾼 상태를 흉내 낸다: 나 → 가
            'attachments' => [$second, $first],
        ]);
        self::assertSame(303, $created->getStatusCode());

        $show = $this->body($this->get($app, $created->getHeaderLine('Location')));
        $positionOfSecond = strpos($show, '나.txt');
        $positionOfFirst = strpos($show, '가.txt');
        self::assertNotFalse($positionOfSecond);
        self::assertNotFalse($positionOfFirst);
        self::assertLessThan($positionOfFirst, $positionOfSecond, '제출한 순서대로 보여야 한다');
    }

    #[DataProvider('connectionProvider')]
    public function testEditFormPreloadsSignedAttachments(array $dbConfig): void
    {
        $app = $this->loggedInApp($dbConfig, true);
        $acl = $this->adminAcl();
        $descriptor = $app->attachments()->upload($acl, 'free', $this->fakeUpload('기존.txt', 'x'));
        $post = $app->postService()->create($acl, 'free', [
            'title' => '글', 'content' => '본문', 'attachments' => [$descriptor],
        ]);

        $body = $this->body($this->get($app, '/posts/' . $post['id'] . '/edit'));

        self::assertStringContainsString('기존.txt', $body);
        // 프리로드된 hidden input 에 서명이 실려 있어야 다시 저장할 수 있다.
        self::assertMatchesRegularExpression('/name="attachments\[\d+\]\[sig\]" value="[0-9a-f]/', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testEditCanRemoveAnAttachment(array $dbConfig): void
    {
        $app = $this->loggedInApp($dbConfig, true);
        $acl = $this->adminAcl();
        $keep = $app->attachments()->upload($acl, 'free', $this->fakeUpload('남김.txt', '1'));
        $drop = $app->attachments()->upload($acl, 'free', $this->fakeUpload('뺌.txt', '2'));
        $post = $app->postService()->create($acl, 'free', [
            'title' => '글', 'content' => '본문', 'attachments' => [$keep, $drop],
        ]);

        $keepSigned = $app->attachments()->withSignature($post['attachments'][0]);
        $updated = $this->post($app, '/posts/' . $post['id'] . '/edit', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '글', 'content' => '본문',
            'attachments' => [$keepSigned],
        ]);
        self::assertSame(303, $updated->getStatusCode());

        $show = $this->body($this->get($app, '/posts/' . $post['id']));
        self::assertStringContainsString('남김.txt', $show);
        self::assertStringNotContainsString('뺌.txt', $show);
    }
}
