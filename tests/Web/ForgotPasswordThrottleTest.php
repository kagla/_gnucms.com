<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Aligo\AligoService;
use GnuCms\App;
use GnuCms\Mail\SecretCipher;
use GnuCms\Tests\Support\FakeAligoTransport;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * /forgot-password 는 로그인도 필요 없고 누구나 누를 수 있는데, 이 분기부터 **돈이 든다**:
 * 재설정 알림을 문자·알림톡으로 켜 두면 누를 때마다 남의 전화기가 울리고 사이트 주인의
 * 잔여 건수가 줄어든다. 예전에는 메일 한 통이라 아무도 세지 않았다.
 *
 * 여기서 보는 것은 둘이다. 횟수가 실제로 막히는가, 그리고 **막히는 방식이 계정의 존재를
 * 흘리지 않는가**. 뒤의 것은 이 분기가 여러 라운드에 걸쳐 지켜 온 성질이라, 앞의 것을
 * 들이면서 무너뜨리면 고친 것보다 잃은 것이 크다.
 */
final class ForgotPasswordThrottleTest extends WebTestCase
{
    private const KNOWN = 'member@example.com';
    private const UNKNOWN = 'nobody@example.com';

    /**
     * 열두 번 눌러도 문자는 세 통이다. 리뷰가 실제로 몰아 본 수(12)를 그대로 쓴다 —
     * 그때는 열두 통이 나갔다.
     */
    #[DataProvider('connectionProvider')]
    public function testTwelveAnonymousPostsBuyAtMostThreeMessages(array $config): void
    {
        $app = $this->makeApp($config);
        $this->member($app);
        $transport = $this->smsOnly($app);
        for ($i = 0; $i < 12; $i++) {
            $transport->queue(200, (string) json_encode(
                ['result_code' => 1, 'msg_id' => 'M' . $i, 'success_cnt' => 1, 'error_cnt' => 0]));
        }
        $this->get($app, '/forgot-password');

        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->forgot($app, self::KNOWN)->getStatusCode();
        }

        self::assertCount(3, $app->db()->select('SELECT id FROM ' . $app->db()->table('message_jobs')),
            '한도가 돈이 나가는 자리에서 실제로 걸려야 한다');
        self::assertCount(3, $transport->requests, '알리고를 부르지도 않는다');
        self::assertSame([200, 200, 200, 422, 422, 422, 422, 422, 422, 422, 422, 422], $statuses);
    }

    /** 막혔다는 사실은 그 화면이 말해 준다 — 조용히 200 을 돌려주면 사람은 기다리기만 한다. */
    #[DataProvider('connectionProvider')]
    public function testTheScreenSaysWhyItStopped(array $config): void
    {
        $app = $this->makeApp($config);
        $this->member($app);
        $this->get($app, '/forgot-password');
        for ($i = 0; $i < 3; $i++) {
            $this->forgot($app, self::KNOWN);
        }

        $body = $this->body($this->forgot($app, self::KNOWN));

        self::assertStringContainsString('재설정 요청이 너무 잦습니다', $body);
        // 아무 값도 틀리게 적지 않은 사람에게 "잘못 입력했습니다"라고 말하지 않는다.
        self::assertStringNotContainsString('잘못 입력', $body);
    }

    /**
     * **막힌 요청도 계정의 존재를 말하지 않는다.** 가입된 주소와 아닌 주소를 같은 상태에서
     * (앞선 기록을 지우고) 똑같이 네 번씩 눌러, 네 응답이 글자 하나까지 같은지 본다 —
     * 마지막 하나는 잠긴 응답이다. 세는 자리가 주소 조회 **뒤**로 가면 이 시험이 깨진다:
     * 그때는 있는 주소만 잠기기 때문이다.
     */
    #[DataProvider('connectionProvider')]
    public function testEvenAThrottledScreenDoesNotTellWhetherTheAccountExists(array $config): void
    {
        $app = $this->makeApp($config);
        $this->member($app);
        $this->get($app, '/forgot-password');

        $seen = [];
        foreach ([self::KNOWN, self::UNKNOWN] as $email) {
            // 두 주소가 같은 출발점에서 달리게 한다 — 여기서 비교하려는 것은 주소이지
            // 누가 먼저 눌렀는가가 아니다.
            $app->db()->delete('password_attempts', '1 = 1');
            $seen[$email] = [];
            for ($i = 0; $i < 4; $i++) {
                $response = $this->forgot($app, $email);
                // 적어 낸 주소 자체는 폼에 그대로 되돌아온다(본인이 방금 친 값이다).
                // 그것까지 비교하면 무엇을 쳤는지가 달라서 다르다고 말하게 되므로,
                // 주소만 같은 자리 표시로 바꾼 뒤 나머지 전부를 비교한다.
                $seen[$email][] = $response->getStatusCode() . "\n"
                    . str_replace($email, '(주소)', $this->body($response));
            }
        }

        self::assertStringContainsString('재설정 요청이 너무 잦습니다', $seen[self::KNOWN][3],
            '네 번째는 실제로 잠겨야 한다 — 잠기지 않으면 이 시험은 아무것도 비교하지 않는다');
        self::assertSame($seen[self::KNOWN], $seen[self::UNKNOWN], '화면이 계정 존재를 흘린다');
    }

    /**
     * 주소를 바꿔 가며 눌러도 한 회선이 쓸 수 있는 양에는 끝이 있다. 주소별 한도만으로는
     * 주소 목록을 들고 온 쪽을 막지 못한다 — 주소마다 세 통씩 나가기 때문이다.
     */
    #[DataProvider('connectionProvider')]
    public function testOneVisitorCannotWalkAListOfAddresses(array $config): void
    {
        $app = $this->makeApp($config);
        $this->member($app);
        $this->get($app, '/forgot-password');

        $statuses = [];
        foreach (['a@example.com', 'b@example.com', 'c@example.com',
            'd@example.com', 'e@example.com', 'f@example.com'] as $email) {
            $statuses[] = $this->forgot($app, $email)->getStatusCode();
        }

        // 여섯 번째는 이 주소로는 처음인데도 막힌다 — 회선 쪽 한도에 닿았기 때문이다.
        self::assertSame([200, 200, 200, 200, 200, 422], $statuses);
    }

    /**
     * **이메일 형태가 아닌 값은 잠금 표에 행을 만들지 않는다.** 주소 쪽 열쇠는
     * sha256(입력값)이라 아무 문자열이나 넣으면 값마다 영구 행이 하나씩 생기는데,
     * 그 표에는 정리 루틴이 없다(성공한 로그인이 자기 행을 지울 뿐이다). 로그인 쪽은
     * 같은 이유로 이미 같은 조건을 쓰고 있었다.
     *
     * 세지 않아도 잃는 것이 없다는 것도 함께 확인한다: 그 값들은 어떤 회원과도 맞지
     * 않아 한 통도 나가지 않고, 진짜 주소의 한도는 그대로 남아 있다.
     */
    #[DataProvider('connectionProvider')]
    public function testValuesThatAreNotEmailAddressesAreNotCounted(array $config): void
    {
        $app = $this->makeApp($config);
        $this->member($app);
        $transport = $this->smsOnly($app);
        for ($i = 0; $i < 3; $i++) {
            $transport->queue(200, (string) json_encode(
                ['result_code' => 1, 'msg_id' => 'M' . $i, 'success_cnt' => 1, 'error_cnt' => 0]));
        }
        $this->get($app, '/forgot-password');

        $statuses = [];
        foreach (['not-an-email', 'x', '주소', '../../etc/passwd', '<script>', 'a b c'] as $value) {
            $statuses[] = $this->forgot($app, $value)->getStatusCode();
        }

        self::assertSame([200, 200, 200, 200, 200, 200], $statuses,
            '이 값들은 아무 비용도 만들지 않으므로 잠글 이유가 없다');
        self::assertSame([], $app->db()->select('SELECT id FROM ' . $app->db()->table('password_attempts')),
            '값마다 영구 행이 하나씩 생기면 표가 끝없이 자란다');
        self::assertSame([], $transport->requests);

        // 진짜 주소의 한도는 그대로다 — 세지 않는 것과 세는 것을 헷갈리지 않았다.
        $known = [];
        for ($i = 0; $i < 4; $i++) {
            $known[] = $this->forgot($app, self::KNOWN)->getStatusCode();
        }
        self::assertSame([200, 200, 200, 422], $known);
    }

    private function forgot(App $app, string $email)
    {
        return $this->post($app, '/forgot-password',
            ['csrf_token' => $_SESSION['csrf_token'] ?? '', 'email' => $email]);
    }

    private function member(App $app): int
    {
        $id = $app->users()->create(self::KNOWN,
            password_hash('member-password-123', PASSWORD_DEFAULT), '회원이름', false);
        $app->users()->verifyEmail($id);
        $app->users()->updatePhone($id, '01012345678');

        return $id;
    }

    /** 재설정 알림을 문자 하나로만 켜 둔 앱. 그래야 누를 때마다 돈이 나간다. */
    private function smsOnly(App $app): FakeAligoTransport
    {
        $transport = new FakeAligoTransport();
        // setAligo() 는 알림 설정과 발송기를 함께 끊으므로 채널 설정보다 먼저 와야 한다.
        $app->setAligo(new AligoService($app->db(), $transport, new SecretCipher('s')));
        $app->aligo()->settings->save(['user_id' => 'shop', 'api_key' => 'K',
            'sender' => '0212345678', 'senderkey' => 'SK1']);
        $app->aligo()->settings->setEnabled('sms', true);
        $app->notifySettings()->save('password_reset', ['mail' => '0', 'alimtalk' => '0',
            'sms' => '1', 'inbox' => '0', 'sms_body' => '#{이름}님 #{링크}']);

        return $transport;
    }
}
