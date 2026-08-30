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
        self::assertSame(401, $this->get($app, '/account')->getStatusCode());
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

        // 이름만 바꾼다. 비밀번호 칸은 비워 둔다.
        $renamed = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '새 이름',
            'current_password' => '', 'password' => '', 'password_confirmation' => '',
        ]);
        self::assertSame(303, $renamed->getStatusCode(), $this->body($renamed));
        self::assertSame('새 이름', $app->users()->findById($id)['display_name']);
        self::assertStringContainsString('새 이름', $this->body($this->get($app, '/')), '머리글의 이름이 바뀌어야 한다');

        // 현재 비밀번호가 틀리면 막힌다.
        $wrong = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '새 이름',
            'current_password' => 'nope', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
        ]);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('현재 비밀번호가 올바르지 않습니다', $this->body($wrong));

        // 맞으면 바뀌고, 지금 세션은 살아 있다.
        $changed = $this->post($app, '/account', [
            'csrf_token' => $_SESSION['csrf_token'], 'display_name' => '새 이름',
            'current_password' => 'old-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
        ]);
        self::assertSame(303, $changed->getStatusCode(), $this->body($changed));
        self::assertTrue(password_verify('new-password-456', (string) $app->users()->findById($id)['password_hash']));
        self::assertSame(200, $this->get($app, '/account')->getStatusCode(), '비밀번호를 바꿔도 지금 세션은 살아 있어야 한다');
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

}
