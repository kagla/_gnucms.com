<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NoticeFormTest extends WebTestCase
{
    private function loginAsAdmin(\GnuCms\App $app): void
    {
        $id = $app->users()->create('admin@example.com', password_hash('admin-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'admin@example.com', 'password' => 'admin-password-123',
        ]);
    }

    private function loginAsMember(\GnuCms\App $app): void
    {
        $id = $app->users()->create('member@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '회원사람');
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com', 'password' => 'member-password-123',
        ]);
    }

    #[DataProvider('connectionProvider')]
    public function testAdminSeesTheNoticeChoiceAndMemberDoesNot(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유']);

        $this->loginAsAdmin($app);
        $adminForm = $this->body($this->get($app, '/boards/free/new'));
        self::assertStringContainsString('name="notice"', $adminForm);
        self::assertStringContainsString('전체 게시판 공지', $adminForm);

        $app2 = $this->makeApp($dbConfig);
        $app2->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유']);
        $this->loginAsMember($app2);
        self::assertStringNotContainsString('name="notice"', $this->body($this->get($app2, '/boards/free/new')));
    }

    /**
     * 게시판 관리자는 이 게시판 공지까지만 고를 수 있다. 전체 공지 선택지는
     * 사이트 관리자에게만 보인다 (PostController::can_pin_global).
     */
    #[DataProvider('connectionProvider')]
    public function testBoardManagerDoesNotSeeGlobalOptionButSiteAdminDoes(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $managerId = $app->users()->create('manager@example.com', password_hash('manager-password-123', PASSWORD_DEFAULT), '게시판지기');
        $app->users()->verifyEmail($managerId);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free', 'name' => '자유', 'managers' => [(string) $managerId],
        ]);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'manager@example.com', 'password' => 'manager-password-123',
        ]);
        $managerForm = $this->body($this->get($app, '/boards/free/new'));
        self::assertStringContainsString('name="notice"', $managerForm);
        self::assertDoesNotMatchRegularExpression('/<input[^>]*name="notice"[^>]*value="global"/', $managerForm);

        $app2 = $this->makeApp($dbConfig);
        $app2->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유']);
        $this->loginAsAdmin($app2);
        $adminForm = $this->body($this->get($app2, '/boards/free/new'));
        self::assertMatchesRegularExpression('/<input[^>]*name="notice"[^>]*value="global"/', $adminForm);
    }

    #[DataProvider('connectionProvider')]
    public function testAdminCanPinThroughTheForm(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유']);
        $this->loginAsAdmin($app);

        $created = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '폼으로 올린 전체 공지', 'content' => '본문입니다', 'notice' => 'global',
        ]);
        self::assertSame(303, $created->getStatusCode());

        $body = $this->body($this->get($app, '/boards/free'));
        self::assertStringContainsString('전체 공지', $body);
        self::assertStringContainsString('폼으로 올린 전체 공지', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testNoticeChoiceSurvivesAValidationFailure(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), ['board_key' => 'free', 'name' => '자유']);
        $this->loginAsAdmin($app);

        // 제목을 비워 검증에서 422 를 받도록 한다. 이때도 공지 선택은 화면에 남아야 한다.
        $response = $this->post($app, '/boards/free/new', [
            'csrf_token' => $_SESSION['csrf_token'],
            'title' => '', 'content' => '본문입니다', 'notice' => 'global',
        ]);
        self::assertSame(422, $response->getStatusCode());

        $body = $this->body($response);
        self::assertStringContainsString('name="notice"', $body);
        self::assertMatchesRegularExpression('/<input[^>]*name="notice"[^>]*value="global"[^>]*checked/', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testEditFormRemembersTheCurrentScope(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유']);
        $post = $app->postService()->create($acl, 'free', [
            'title' => '전체 공지', 'content' => '본문입니다', 'notice' => 'global',
        ]);
        $this->loginAsAdmin($app);

        $body = $this->body($this->get($app, '/posts/' . $post['id'] . '/edit'));

        self::assertMatchesRegularExpression('/<input[^>]*name="notice"[^>]*value="global"[^>]*checked/', $body);
    }

    /**
     * 게시판 관리자가 이미 전체 공지인 글을 고치다 검증에 실패하면(422), 재렌더된
     * 폼이 "공지 아님"을 조용히 체크해서는 안 된다. 게시판 관리자에게는 global
     * 라디오 자체가 없으므로 notice 값이 아예 제출되지 않는데, 이때 재렌더 폼의
     * $values 를 원 요청 그대로 쓰면 def() 가 'none' 으로 떨어져 "공지 아님"이
     * 체크된 채로 보이고, 그대로 다시 제출하면 사이트 관리자의 전체 공지가
     * 조용히 내려간다.
     */
    #[DataProvider('connectionProvider')]
    public function testBoardManagerReeditingAGlobalNoticeDoesNotShowNoneCheckedOn422(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $managerId = $app->users()->create('manager@example.com', password_hash('manager-password-123', PASSWORD_DEFAULT), '게시판지기');
        $app->users()->verifyEmail($managerId);
        $app->boardService()->create($acl, [
            'board_key' => 'free', 'name' => '자유', 'managers' => [(string) $managerId],
        ]);
        $post = $app->postService()->create($acl, 'free', [
            'title' => '전체 공지', 'content' => '본문입니다', 'notice' => 'global',
        ]);

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'manager@example.com', 'password' => 'manager-password-123',
        ]);

        // 제목을 비워 검증 실패를 유도한다. 게시판 관리자용 폼에는 notice=global
        // 라디오가 없으므로, 재전송 입력에도 notice 키가 없다.
        $invalidEdit = [
            'csrf_token' => $_SESSION['csrf_token'], 'title' => '', 'content' => '고친 본문입니다',
        ];
        $response = $this->post($app, '/posts/' . $post['id'] . '/edit', $invalidEdit);
        self::assertSame(422, $response->getStatusCode());

        $body = $this->body($response);
        self::assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="notice"[^>]*value="none"[^>]*checked/',
            $body,
            '재렌더 폼이 "공지 아님"을 조용히 체크해서는 안 된다'
        );

        // 422 상태 그대로(제목만 채워) 다시 제출한다. 전체 공지가 그대로 남아 있어야 한다.
        $validEdit = $invalidEdit;
        $validEdit['title'] = '전체 공지(고침)';
        $resubmit = $this->post($app, '/posts/' . $post['id'] . '/edit', $validEdit);
        self::assertSame(303, $resubmit->getStatusCode());

        $stored = $app->postService()->get($acl, $post['id'], null);
        self::assertTrue($stored['is_notice'], '재렌더·재제출 과정에서 전체 공지가 조용히 내려가면 안 된다');
        self::assertSame('global', $stored['notice_scope']);
    }
}
