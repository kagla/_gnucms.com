<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Auth\Acl;
use GnuCms\Auth\Identity;
use GnuCms\Error\DomainError;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AccountPageTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testGuestCannotOpenAccountPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->assertLoginRedirect($this->get($app, '/account'), '/account');
    }

    #[DataProvider('connectionProvider')]
    public function testMemberEditsNameAndPasswordAndKeepsSession(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('me@example.com', password_hash('old-password-123', PASSWORD_DEFAULT), '나', false);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'me@example.com', 'password' => 'old-password-123',
        ]);

        $form = $this->body($this->get($app, '/account'));
        self::assertStringContainsString('회원정보 수정', $form);
        self::assertStringContainsString('me@example.com', $form);
        self::assertStringContainsString('name="current_password"', $form);
        self::assertStringContainsString('name="profile_image"', $form);

        // 이름만 바꾼다. 비밀번호 칸은 비워 둔다.
        $renamed = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '새이름',
            'current_password' => '', 'password' => '', 'password_confirmation' => '',
        ]);
        self::assertSame(303, $renamed->getStatusCode(), $this->body($renamed));
        self::assertSame('새이름', $app->users()->findById($id)['display_name']);
        self::assertStringContainsString('새이름', $this->body($this->get($app, '/')), '머리글의 이름이 바뀌어야 한다');

        // 현재 비밀번호가 틀리면 막힌다.
        $wrong = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '새이름',
            'current_password' => 'nope', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
        ]);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('현재 비밀번호가 올바르지 않습니다', $this->body($wrong));

        // 맞으면 바뀌고, 지금 세션은 살아 있다.
        $changed = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '새이름',
            'current_password' => 'old-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
        ]);
        self::assertSame(303, $changed->getStatusCode(), $this->body($changed));
        self::assertTrue(password_verify('new-password-456', (string) $app->users()->findById($id)['password_hash']));
        self::assertSame(200, $this->get($app, '/account')->getStatusCode(), '비밀번호를 바꿔도 지금 세션은 살아 있어야 한다');
    }

    #[DataProvider('connectionProvider')]
    public function testSocialOnlyAccountDoesNotShowPasswordChangeFields(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->createSocial('social@example.com', '소셜회원');
        $this->get($app, '/login');
        session_start();
        $_SESSION['user_id'] = $id;
        $_SESSION['session_epoch'] = 0;
        session_write_close();

        $form = $this->body($this->get($app, '/account'));
        self::assertStringContainsString('표시 이름을 바꿉니다.', $form);
        self::assertStringNotContainsString('비밀번호 바꾸기', $form);
        self::assertStringNotContainsString('name="current_password"', $form);
        self::assertStringNotContainsString('name="password"', $form);
        self::assertStringNotContainsString('name="password_confirmation"', $form);

        $renamed = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '새소셜회원',
        ]);
        self::assertSame(303, $renamed->getStatusCode(), $this->body($renamed));
        self::assertSame('새소셜회원', $app->users()->findById($id)['display_name']);
    }

    #[DataProvider('connectionProvider')]
    public function testPasswordMemberWithdrawsAndEmailCanBeReused(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->users()->create('owner@example.com', password_hash('owner-password-123', PASSWORD_DEFAULT), '관리자', true);
        $id = $app->users()->create('leave@example.com', password_hash('leave-password-123', PASSWORD_DEFAULT), '떠날회원');
        $app->users()->verifyEmail($id);
        $app->identities()->attach($id, 'google', 'old-google-uid');
        $postId = $app->posts()->create([
            'board_id' => 1, 'title' => '남길 글', 'content' => '본문', 'author_id' => (string) $id,
            'author_name' => '떠날회원', 'author_ip' => '198.51.100.20',
        ]);
        $commentId = $app->comments()->create([
            'board_id' => 1, 'post_id' => $postId, 'content' => '남길 댓글',
            'author_id' => (string) $id, 'author_name' => '떠날회원', 'author_ip' => '198.51.100.21',
        ]);
        $app->loginEvents()->record($id, 'leave@example.com', 'password', 'success', '198.51.100.22', 'Test');
        $app->users()->updatePhone($id, '01044445555');

        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'leave@example.com',
            'password' => 'leave-password-123',
        ]);
        $page = $this->body($this->get($app, '/account'));
        self::assertStringContainsString('회원 탈퇴', $page);
        self::assertStringContainsString('작성한 글과 댓글은 삭제되지 않고', $page);

        $wrong = $this->post($app, '/account/withdraw', [
            'csrf_token' => $_SESSION['csrf_token'], 'withdraw_current_password' => 'wrong',
            'confirm_withdrawal' => '1',
        ]);
        self::assertSame(422, $wrong->getStatusCode());

        $withdrawn = $this->post($app, '/account/withdraw', [
            'csrf_token' => $_SESSION['csrf_token'], 'withdraw_current_password' => 'leave-password-123',
            'confirm_withdrawal' => '1',
        ], ['REMOTE_ADDR' => '203.0.113.30']);
        self::assertSame(303, $withdrawn->getStatusCode(), $this->body($withdrawn));
        self::assertSame('/account/withdrawn', $withdrawn->getHeaderLine('Location'));

        $old = $app->users()->findById($id);
        self::assertSame('withdrawn', $old['status']);
        self::assertSame('203.0.113.30', $old['withdrawn_ip']);
        self::assertNotNull($old['withdrawn_at']);
        self::assertNull($old['password_hash']);
        self::assertNotSame('leave@example.com', $old['email']);
        // 번호는 가장 연락하기 쉬운 값이다. 이름·이메일·비밀번호를 익명화하면서 번호만
        // 남기면, 관리자 회원 수정은 탈퇴 회원을 거부하므로 DB 를 직접 건드리는 것
        // 말고는 지울 방법이 없다 — 검색으로는 여전히 찾힌다.
        self::assertNull($old['phone'], '탈퇴하면 번호도 함께 지워져야 한다');
        self::assertSame([], $app->users()->listForAdmin('010-4444-5555'), '지워졌으니 번호로 찾히지도 않아야 한다');
        self::assertSame(0, $app->identities()->countForUser($id));
        self::assertSame('탈퇴한 회원', $app->posts()->find($postId)['author_name']);
        self::assertNull($app->posts()->find($postId)['author_ip']);
        self::assertSame('탈퇴한 회원', $app->comments()->find($commentId)['author_name']);
        self::assertNull($app->comments()->find($commentId)['author_ip']);
        $event = $app->db()->selectOne(
            'SELECT login_identifier, client_ip FROM login_events WHERE user_id = ? ORDER BY id ASC LIMIT 1', [$id]
        );
        self::assertNull($event['login_identifier']);
        self::assertSame('198.51.100.22', $event['client_ip']);
        $this->assertLoginRedirect($this->get($app, '/account'), '/account');

        $newId = $app->users()->create('leave@example.com', password_hash('new-password-123', PASSWORD_DEFAULT), '새회원');
        $app->identities()->attach($newId, 'google', 'old-google-uid');
        self::assertNotSame($id, $newId);
        self::assertSame(1, $app->identities()->countForUser($newId));
    }

    #[DataProvider('connectionProvider')]
    public function testLastAdminCannotWithdraw(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->users()->create('owner@example.com', password_hash('owner-password-123', PASSWORD_DEFAULT), '관리자', true);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'owner@example.com',
            'password' => 'owner-password-123',
        ]);
        $response = $this->post($app, '/account/withdraw', [
            'csrf_token' => $_SESSION['csrf_token'], 'withdraw_current_password' => 'owner-password-123',
            'confirm_withdrawal' => '1',
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('마지막 관리자는 탈퇴할 수 없습니다', $this->body($response));
        self::assertSame('active', $app->users()->findById($id)['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testProfileMenuLinksToAccountNotTerms(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->createPage([
            'slug' => 'service', 'title' => '이용약관', 'content' => '본문', 'seo_description' => null,
            'status' => 'published', 'show_in_menu' => 1, 'sort_order' => 0, 'is_consent' => 1,
        ]);
        $id = $app->users()->create('me@example.com', password_hash('old-password-123', PASSWORD_DEFAULT), '나', false);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'me@example.com', 'password' => 'old-password-123',
        ]);
        $body = $this->body($this->get($app, '/'));
        self::assertMatchesRegularExpression('#class="[^"]*user-menu"[^>]*>(?:(?!</ul>).)*/account#s', $body, '프로필 메뉴에 회원정보 수정이 있어야 한다');
        self::assertDoesNotMatchRegularExpression('#class="[^"]*user-menu"[^>]*>(?:(?!</ul>).)*/terms/#s', $body, '프로필 메뉴에 약관이 있으면 안 된다');
        self::assertStringContainsString('/terms/service', $body, '약관은 하단에는 그대로 있어야 한다');
    }
    /** 표시 이름은 겹치지 않는다. 가입 때 자동으로 지은 이름이 겹치면 숫자를 붙이고, 직접 고를 때 겹치면 막는다. */
    #[DataProvider('connectionProvider')]
    public function testDisplayNamesAreUnique(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $users = $app->users();
        $a = $users->createRegistered('kagla@a.example', password_hash('x-password-123', PASSWORD_DEFAULT), 'kagla');
        $b = $users->createRegistered('kagla@b.example', password_hash('x-password-123', PASSWORD_DEFAULT), 'kagla');
        $c = $users->createSocial('kagla@c.example', 'Kagla');
        self::assertSame('kagla', $users->findById($a)['display_name']);
        self::assertSame('kagla2', $users->findById($b)['display_name']);
        self::assertSame('Kagla3', $users->findById($c)['display_name'], '대소문자만 다른 이름도 겹친 것으로 본다');

        try {
            $app->accountService()->updateProfile($b, ['display_name' => 'KAGLA']);
            self::fail('남이 쓰는 이름으로는 못 바꾼다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('display_name', $e->details());
        }
        // 한글 2자·영문 4자 미만은 안 된다. 자동 이름이 짧으면 '회원' 으로 대신한다.
        // 공백·기호는 안 된다.
        foreach (['홍 길동', 'kagla!', '홍길동_', 'kim lee', '홍길동.'] as $bad) {
            try {
                $app->accountService()->updateProfile($b, ['display_name' => $bad]);
                self::fail($bad . ' 은 막아야 한다');
            } catch (DomainError $e) {
                self::assertArrayHasKey('display_name', $e->details(), $bad);
            }
        }
        // 자동 이름은 허용되지 않는 글자를 걷어 낸다.
        $e = $users->createRegistered('kim.lee@e.example', password_hash('x-password-123', PASSWORD_DEFAULT), 'kim.lee');
        self::assertSame('kimlee', $users->findById($e)['display_name']);
        $f = $users->createSocial('hong@f.example', '홍 길동');
        self::assertSame('홍길동', $users->findById($f)['display_name']);
        foreach (['가', 'ab', 'kim', '김a'] as $short) {
            try {
                $app->accountService()->updateProfile($b, ['display_name' => $short]);
                self::fail($short . ' 은 너무 짧아 막아야 한다');
            } catch (DomainError $e) {
                self::assertArrayHasKey('display_name', $e->details(), $short);
            }
        }
        foreach (['홍길', 'abcd', '김ab'] as $ok) {
            $app->accountService()->updateProfile($b, ['display_name' => $ok]);
            self::assertSame($ok, $users->findById($b)['display_name']);
        }
        $app->accountService()->updateProfile($b, ['display_name' => 'kagla2']);
        $d = $users->createRegistered('kim@d.example', password_hash('x-password-123', PASSWORD_DEFAULT), 'kim');
        self::assertSame('회원', $users->findById($d)['display_name'], '영문 3자 자동 이름은 회원으로 대신한다');

        // 자기 이름을 그대로 두는 것은 겹침이 아니다.
        $app->accountService()->updateProfile($b, ['display_name' => 'kagla2']);
        self::assertSame('kagla2', $users->findById($b)['display_name']);

        try {
            $app->adminService()->updateMember(new Acl(Identity::user('1', '관리자', true)), $c, [
                'email' => 'kagla@c.example', 'display_name' => 'kagla', 'status' => 'active',
            ]);
            self::fail('관리자도 남이 쓰는 이름으로는 못 바꾼다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('display_name', $e->details());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testMemberSetsAndClearsTheirPhoneNumberFromTheProfileScreen(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['signup_phone' => 'optional']);
        $id = $app->users()->create('me@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '나', false);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'me@example.com', 'password' => 'member-password-123',
        ]);

        $form = $this->body($this->get($app, '/account'));
        self::assertStringContainsString('name="phone"', $form);

        $saved = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '나야', 'phone' => '010-1234-5678',
            'current_password' => '', 'password' => '', 'password_confirmation' => '',
        ]);
        self::assertSame(303, $saved->getStatusCode(), $this->body($saved));
        self::assertSame('01012345678', $app->users()->findById($id)['phone']);
        self::assertStringContainsString(
            'value="010-1234-5678"',
            $this->body($this->get($app, '/account')),
            '저장된 번호는 하이픈을 넣어 보여줘야 한다'
        );

        $cleared = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '나야', 'phone' => '',
            'current_password' => '', 'password' => '', 'password_confirmation' => '',
        ]);
        self::assertSame(303, $cleared->getStatusCode(), $this->body($cleared));
        self::assertNull($app->users()->findById($id)['phone']);
    }

    /**
     * 화면이 말하는 것과 서버가 하는 것이 같아야 한다. 번호가 없는 회원에게 required
     * 정책은 아무 것도 막지 않으므로, 칸에 HTML required 를 붙이지 않고 "비워 두고
     * 저장해도 된다"고 적으며, 실제로 이름만 바꾼 제출이 303 으로 저장돼야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testRequiredPolicyDoesNotBlockTheProfileOfAMemberWithNoNumber(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['signup_phone' => 'required']);
        $id = $app->users()->create('me@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '나', false);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'me@example.com', 'password' => 'member-password-123',
        ]);

        $form = $this->body($this->get($app, '/account'));
        preg_match('/<input[^>]*name="phone"[^>]*>/', $form, $tag);
        self::assertNotEmpty($tag, '번호 칸은 있어야 한다');
        self::assertStringNotContainsString(' required', $tag[0], '막지 않을 것에 required 를 붙이면 안 된다');
        self::assertStringContainsString('비워 두고 저장해도 됩니다', $form);
        self::assertStringNotContainsString('비워 두고 저장하면 번호가 지워집니다', $form);

        $saved = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '나야', 'phone' => '',
            'current_password' => '', 'password' => '', 'password_confirmation' => '',
        ]);
        self::assertSame(303, $saved->getStatusCode(), $this->body($saved));
        self::assertSame('나야', $app->users()->findById($id)['display_name']);
    }

    /**
     * 같은 화면의 반대쪽. 번호가 저장돼 있으면 required 는 "지울 수 없다"는 뜻이므로,
     * 칸에 HTML required 가 붙고 문구도 그렇게 말하며, 빈 값 제출은 422 다.
     */
    #[DataProvider('connectionProvider')]
    public function testAStoredNumberIsMarkedAsUnclearableUnderRequiredPolicy(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['signup_phone' => 'required']);
        $id = $app->users()->create('me@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '나', false);
        $app->users()->verifyEmail($id);
        $app->users()->updatePhone($id, '01012345678');
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'me@example.com', 'password' => 'member-password-123',
        ]);

        $form = $this->body($this->get($app, '/account'));
        preg_match('/<input[^>]*name="phone"[^>]*>/', $form, $tag);
        self::assertNotEmpty($tag);
        self::assertStringContainsString(' required', $tag[0], '서버가 거절할 것은 화면도 막아야 한다');
        self::assertStringContainsString('번호를 지울 수는 없습니다', $form);

        $refused = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '나야', 'phone' => '',
            'current_password' => '', 'password' => '', 'password_confirmation' => '',
        ]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('01012345678', $app->users()->findById($id)['phone']);
        // 되그릴 때도 같은 표시가 남아야 한다 — 제출값은 비어 있지만 저장된 번호는 그대로다.
        preg_match('/<input[^>]*name="phone"[^>]*>/', $this->body($refused), $again);
        self::assertStringContainsString(' required', $again[0]);
    }

    /**
     * 수집이 꺼져 있고 저장된 번호도 없으면 보여 줄 것이 없다 — 가입 화면이 칸을
     * 감추는 것과 같게 맞춘다. 저장된 번호가 있으면 그때는 보여 준다(잠긴 채로).
     */
    #[DataProvider('connectionProvider')]
    public function testTheProfileHidesThePhoneFieldWhenCollectionIsOffAndNothingIsStored(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['signup_phone' => 'off']);
        $id = $app->users()->create('me@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '나', false);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'me@example.com', 'password' => 'member-password-123',
        ]);

        $empty = $this->body($this->get($app, '/account'));
        self::assertStringNotContainsString('name="phone"', $empty, '보여 줄 번호가 없으면 칸도 없어야 한다');
        self::assertStringNotContainsString('번호 수집이 꺼져 있어', $empty);

        $app->users()->updatePhone($id, '01012345678');
        $stored = $this->body($this->get($app, '/account'));
        self::assertStringContainsString('name="phone"', $stored, '저장된 번호는 보여 줘야 한다');
        self::assertStringContainsString('value="010-1234-5678"', $stored);
        self::assertStringContainsString('번호 수집이 꺼져 있어', $stored);
    }

    /**
     * 위 시나리오의 HTTP 단. 정책이 off 인 동안 번호 칸은 disabled 로 그려지고(그래서
     * 브라우저가 POST 에 싣지 않는다), 관리자가 정책을 선택으로 바꾼 뒤 그 화면에서
     * 이름만 고쳐 저장하면 번호 칸 없는 제출이 서버에 닿는다. 그것이 "지워라"로
     * 읽히면 안 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testAProfileSaveWithNoPhoneFieldKeepsTheStoredNumber(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['signup_phone' => 'off']);
        $id = $app->users()->create('me@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '나', false);
        $app->users()->verifyEmail($id);
        $app->users()->updatePhone($id, '01012345678');
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'me@example.com', 'password' => 'member-password-123',
        ]);

        // off 화면의 번호 칸은 disabled 다 — 브라우저는 이 칸을 제출하지 않는다.
        preg_match('/<input[^>]*name="phone"[^>]*>/', $this->body($this->get($app, '/account')), $tag);
        self::assertStringContainsString('disabled', $tag[0]);

        // 관리자가 정책을 선택으로 바꾼다. CmsService 는 설정을 메모리에 캐시하므로
        // 리포지토리를 직접 건드리면 이 요청이 여전히 off 를 보고, 그러면 이 테스트는
        // 확인하려던 것을 확인하지 않게 된다 — 캐시를 비우는 공개 API 로 바꾼다.
        $app->cmsService()->saveWritingSettings(
            new Acl(Identity::user('1', '관리자', true)),
            [
                'guest_write_enabled' => '0',
                'post_min_chars' => '0', 'comment_min_chars' => '0',
                'post_rate_interval' => '30', 'post_rate_10m' => '5', 'post_rate_day' => '20',
                'comment_rate_interval' => '5', 'comment_rate_10m' => '20', 'comment_rate_day' => '100',
                'attach_max_mb' => '5', 'attach_limit' => '5',
                'signup_phone' => 'optional',
            ]
        );
        // 정책이 실제로 바뀐 화면인지 확인한다 — 칸이 더는 잠겨 있지 않아야 한다.
        preg_match('/<input[^>]*name="phone"[^>]*>/', $this->body($this->get($app, '/account')), $live);
        self::assertStringNotContainsString('disabled', $live[0], '정책이 바뀐 것이 이 요청에 보여야 한다');
        $saved = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '나야',
            'current_password' => '', 'password' => '', 'password_confirmation' => '',
        ]);

        self::assertSame(303, $saved->getStatusCode(), $this->body($saved));
        self::assertSame('01012345678', $app->users()->findById($id)['phone'],
            '번호 칸이 없는 제출은 지우라는 뜻이 아니다');
        self::assertSame('나야', $app->users()->findById($id)['display_name']);
    }

    /**
     * 컨트롤러·템플릿까지 실제로 거치는 HTTP 단 확인. AuthController::register() 가
     * phone[]=x 를 is_scalar 가드 없이 (string) 캐스팅해 경고를 냈던 것과 같은 결함이
     * 여기서도 날 수 있다 — 같은 방식으로 값을 되돌리는 화면이기 때문이다.
     */
    #[DataProvider('connectionProvider')]
    public function testAccountUpdateRedisplaysPhoneSafelyOnValidationFailure(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->cms()->saveSettings(['signup_phone' => 'optional']);
        $id = $app->users()->create('me@example.com', password_hash('member-password-123', PASSWORD_DEFAULT), '나', false);
        $app->users()->verifyEmail($id);
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'me@example.com', 'password' => 'member-password-123',
        ]);

        // 표시 이름을 비워 검증을 실패시키면서, 번호는 배열로 보낸다.
        $response = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '',
            'current_password' => '', 'password' => '', 'password_confirmation' => '',
            'phone' => ['x'],
        ]);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->body($response);
        self::assertStringNotContainsString('value="Array"', $body);
        self::assertNull($app->users()->findById($id)['phone']);
    }
}
