<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AttachmentFormTest extends WebTestCase
{
    /**
     * @param array $settings /login 요청보다 먼저 적용할 설정. Kernel 이 요청마다
     *   CmsService::settings() 를 캐시하므로, 로그인 뒤에 바꾸면 첫 App::attachments()
     *   호출이 캐시된 옛 값을 본다 — 그래서 로그인 전에 적용한다.
     */
    private function loggedInApp(array $dbConfig, bool $useFile, array $settings = []): \GnuCms\App
    {
        $app = $this->makeApp($dbConfig);
        if ($settings !== []) {
            $app->cms()->saveSettings($settings);
        }
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
        $body = $this->body($this->get($app, '/boards/free/new'));
        self::assertStringContainsString('data-attachments', $body);
        self::assertStringContainsString('/boards/free/files', $body);

        $app2 = $this->loggedInApp($dbConfig, false);
        self::assertStringNotContainsString('data-attachments', $this->body($this->get($app2, '/boards/free/new')));
    }

    #[DataProvider('connectionProvider')]
    public function testSubmittedOrderIsStoredAndShown(array $dbConfig): void
    {
        $app = $this->loggedInApp($dbConfig, true);
        $acl = $this->adminAcl();
        $first = $app->attachments()->upload($acl, 'free', $this->fakeUpload('가.txt', '1'));
        $second = $app->attachments()->upload($acl, 'free', $this->fakeUpload('나.txt', '2'));

        $created = $this->post($app, '/boards/free/new', [
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
        self::assertStringNotContainsString('data-attach-up', $body);
        self::assertStringNotContainsString('data-attach-down', $body);
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

    #[DataProvider('connectionProvider')]
    public function testTooManyAttachmentsShowsServerErrorInForm(array $dbConfig): void
    {
        $app = $this->loggedInApp($dbConfig, true, ['attach_limit' => '1']);
        $acl = $this->adminAcl();
        $first = $app->attachments()->upload($acl, 'free', $this->fakeUpload('하나.txt', '1'));
        $second = $app->attachments()->upload($acl, 'free', $this->fakeUpload('둘.txt', '2'));

        $response = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '한도 초과', 'content' => '본문',
            'attachments' => [$first, $second],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('첨부는 1개까지입니다.', $this->body($response));
    }

    #[DataProvider('connectionProvider')]
    public function testSaveWorksWithoutControllerPreCallingAttachments(array $dbConfig): void
    {
        // PostController::create()/update() 는 더 이상 App::attachments() 를 미리
        // 부르지 않는다(PostService 의 지연 resolver 가 대신한다). 이 테스트는 그
        // resolver 배선이 살아 있는지를 지킨다: 이미 연결된 첨부 서비스 참조를 강제로
        // 끊어 둔 채로도 저장이 되는지 본다. App::postService() 에서
        // setAttachmentResolver() 호출을 지우면 이 테스트가 실패한다.
        $app = $this->loggedInApp($dbConfig, true);
        $acl = $this->adminAcl();
        $descriptor = $app->attachments()->upload($acl, 'free', $this->fakeUpload('지연.txt', '내용'));

        (new \ReflectionProperty(\GnuCms\Service\PostService::class, 'attachments'))
            ->setValue($app->postService(), null);
        (new \ReflectionProperty(\GnuCms\App::class, 'attachmentService'))
            ->setValue($app, null);

        $created = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '지연 연결', 'content' => '본문',
            'attachments' => [$descriptor],
        ]);

        self::assertSame(303, $created->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testAttachmentLimitIsEnforcedEvenWithoutControllerPreCallingAttachments(array $dbConfig): void
    {
        // resolver 는 개수 한도 검사보다 먼저 돌아야 한다: App::attachments() 를
        // 부르는 부수효과로 $attachmentLimit 도 함께 채워지기 때문이다. 순서가
        // 뒤바뀌면(한도 검사 → resolver) 지연 연결 전에는 한도가 기본값 0(무제한)으로
        // 읽혀 개수 제한이 조용히 뚫린다. 실제 다중 프로세스 배포에서 App::attachments()
        // 를 미리 부른 적이 없는 첫 요청을 흉내 내려고, 이미 연결된 참조들을 전부
        // reflection 으로 끊어 둔다.
        $app = $this->loggedInApp($dbConfig, true, ['attach_limit' => '1']);
        $acl = $this->adminAcl();
        $first = $app->attachments()->upload($acl, 'free', $this->fakeUpload('하나.txt', '1'));
        $second = $app->attachments()->upload($acl, 'free', $this->fakeUpload('둘.txt', '2'));

        (new \ReflectionProperty(\GnuCms\App::class, 'attachmentService'))
            ->setValue($app, null);
        (new \ReflectionProperty(\GnuCms\Service\PostService::class, 'attachments'))
            ->setValue($app->postService(), null);
        (new \ReflectionProperty(\GnuCms\Service\PostService::class, 'attachmentLimit'))
            ->setValue($app->postService(), 0);

        $response = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '한도 초과', 'content' => '본문',
            'attachments' => [$first, $second],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('첨부는 1개까지입니다.', $this->body($response));
    }
}
