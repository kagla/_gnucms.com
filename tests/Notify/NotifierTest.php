<?php

declare(strict_types=1);

namespace GnuCms\Tests\Notify;

use GnuCms\Aligo\AligoService;
use GnuCms\Aligo\AlimtalkApi;
use GnuCms\Aligo\Settings as AligoSettings;
use GnuCms\Aligo\SettingsRepository as AligoSettingsRepository;
use GnuCms\Aligo\Templates;
use GnuCms\App;
use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Notify\ChannelInterface;
use GnuCms\Notify\InboxChannel;
use GnuCms\Notify\MailChannel;
use GnuCms\Notify\Notifier;
use GnuCms\Notify\NotifySettings;
use GnuCms\Notify\Recipient;
use GnuCms\Notify\SettingsRepository;
use GnuCms\Notify\SmsChannel;
use GnuCms\Repository\CommentRepository;
use GnuCms\Repository\NotificationRepository;
use GnuCms\Repository\PostRepository;
use GnuCms\Service\NotificationService;
use GnuCms\Tests\Support\CollectingMailer;
use GnuCms\Tests\Support\FakeAligoTransport;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 팬아웃의 시험대.
 *
 * 채널은 익명 클래스로 대신한다 — 여기서 보려는 것은 "어느 채널이, 어떤 차례로,
 * 실패하면 어떻게 되는가"이지 채널 넷이 제 일을 하는지가 아니다(그쪽은 ChannelsTest).
 * 반대로 NotifySettings 는 진짜를 쓴다: final 이라 흉내 낼 수 없기도 하고, "켠 채널"의
 * 판단은 그 클래스 몫이라 흉내 내면 시험하는 대상이 바뀐다.
 *
 * App 배선을 보는 뒤쪽 시험들이 WebTestCase 를 필요로 해서 DatabaseTestCase 대신
 * 그쪽을 상속한다(WebTestCase 도 DatabaseTestCase 다 — connectionProvider 는 그대로다).
 */
final class NotifierTest extends WebTestCase
{
    /** Notifier 가 남긴 로그 줄. 실패·미발송은 화면이 아니라 여기로 간다. */
    private array $logged = [];

    /** 채널들이 실제로 send() 된 차례. 순서 시험이 이것을 본다. */
    private array $order = [];

    /** 발송과 로그를 한 줄에 섞어 적은 기록. "언제 적었는가"를 보는 시험이 이것을 본다. */
    private array $timeline = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logged = [];
        $this->order = [];
        $this->timeline = [];
    }

    private function channel(string $key, bool $available = true, bool $throws = false): ChannelInterface
    {
        $record = function (string $sent): void {
            $this->order[] = $sent;
            $this->timeline[] = 'send:' . $sent;
        };

        return new class ($key, $available, $throws, $record) implements ChannelInterface {
            /** @var list<string> */
            public array $sent = [];
            /** 실패한 채널을 다시 부르지 않는지 본다 — 재시도는 두 번 보내는 것과 같다. */
            public int $calls = 0;

            public function __construct(
                private string $k,
                private bool $ok,
                private bool $boom,
                private \Closure $record
            ) {
            }

            public function key(): string
            {
                return $this->k;
            }

            public function available(string $event, Recipient $to): bool
            {
                return $this->ok;
            }

            public function send(string $event, Recipient $to, array $vars): void
            {
                $this->calls++;
                if ($this->boom) {
                    throw DomainError::serviceUnavailable('테스트 실패');
                }
                ($this->record)($this->k);
                $this->sent[] = $event;
            }
        };
    }

    /** available() 조차 답하지 못하는 채널(설정을 읽다 DB 가 끊긴 문자 채널 같은). */
    private function brokenChannel(string $key): ChannelInterface
    {
        return new class ($key) implements ChannelInterface {
            public function __construct(private string $k)
            {
            }

            public function key(): string
            {
                return $this->k;
            }

            public function available(string $event, Recipient $to): bool
            {
                throw new \RuntimeException('available 가 터졌다');
            }

            public function send(string $event, Recipient $to, array $vars): void
            {
                throw new \LogicException('여기까지 오면 안 된다');
            }
        };
    }

    /**
     * Notifier 는 진짜 NotifySettings 를 받는다. 여기서는 DB 를 한 벌 만들고 원하는
     * 채널만 켜 둔다. 승인 템플릿 T1 을 같이 심어 두는 것은 alimtalk 를 켤 수 있게
     * 하기 위해서다 — 매핑이 없으면 NotifySettings 가 저장을 거절한다.
     */
    private function notifier(array $dbConfig, array $channels, array $on, string $event = 'welcome'): Notifier
    {
        $db = $this->freshDatabase($dbConfig);
        $db->insert('alimtalk_templates', ['tpl_code' => 'T1', 'senderkey' => 'SK1', 'name' => '안내',
            'content' => '#{고객명}님 #{주소} 를 확인하세요', 'status' => 'A', 'insp_status' => 'APR',
            'enabled' => 1, 'fetched_at' => '2026-09-17 10:00:00']);
        $settings = $this->settings($db);

        $input = ['mail' => '0', 'alimtalk' => '0', 'sms' => '0', 'inbox' => '0'];
        foreach ($on as $channel) {
            $input[$channel] = '1';
        }
        // 문자 본문이 있어야 sms 를 켤 수 있고, 매핑이 있어야 alimtalk 를 켤 수 있다.
        $input['sms_body'] = '#{이름}님 안녕하세요';
        $input['tpl_code'] = 'T1';
        $input['var_map'] = ['고객명' => '이름', '주소' => '사이트명'];
        $settings->save($event, $input);

        return new Notifier($settings, $channels, $this->log());
    }

    private function settings(Connection $db): NotifySettings
    {
        $aligo = new AligoSettings(new AligoSettingsRepository($db), new SecretCipher('s'));

        return new NotifySettings(
            new SettingsRepository($db),
            new Templates($db, new AlimtalkApi(new FakeAligoTransport(), $aligo), $aligo)
        );
    }

    private function log(): \Closure
    {
        return function (string $line): void {
            $this->logged[] = $line;
            $this->timeline[] = 'log';
        };
    }

    /** 주어진 예외를 그대로 던지는 채널. 로그가 그 예외에서 무엇을 건져 내는지 본다. */
    private function throwingChannel(string $key, \Throwable $error): ChannelInterface
    {
        return new class ($key, $error) implements ChannelInterface {
            public function __construct(private string $k, private \Throwable $error)
            {
            }

            public function key(): string
            {
                return $this->k;
            }

            public function available(string $event, Recipient $to): bool
            {
                return true;
            }

            public function send(string $event, Recipient $to, array $vars): void
            {
                throw $this->error;
            }
        };
    }

    private function loggedText(): string
    {
        return implode("\n", $this->logged);
    }

    private function to(): Recipient
    {
        return Recipient::forUser(['id' => '1', 'display_name' => '홍', 'email' => 'a@example.com']);
    }

    #[DataProvider('connectionProvider')]
    public function testSendsThroughEveryChannelThatIsOn(array $dbConfig): void
    {
        $mail = $this->channel('mail');
        $sms = $this->channel('sms');
        $this->notifier($dbConfig, [$mail, $sms], ['mail', 'sms'])->notify('welcome', $this->to(), []);

        self::assertSame(['welcome'], $mail->sent);
        self::assertSame(['welcome'], $sms->sent);
        self::assertSame([], $this->logged, '정상 발송은 로그를 남기지 않는다');
    }

    #[DataProvider('connectionProvider')]
    public function testChannelsThatAreOffAreNotUsed(array $dbConfig): void
    {
        $mail = $this->channel('mail');
        $sms = $this->channel('sms');
        $this->notifier($dbConfig, [$mail, $sms], ['mail'])->notify('welcome', $this->to(), []);

        self::assertSame(['welcome'], $mail->sent);
        self::assertSame([], $sms->sent);
    }

    #[DataProvider('connectionProvider')]
    public function testOneFailingChannelDoesNotStopTheOthers(array $dbConfig): void
    {
        $mail = $this->channel('mail');
        $sms = $this->channel('sms', true, true);
        $this->notifier($dbConfig, [$mail, $sms], ['mail', 'sms'])->notify('welcome', $this->to(), []);

        self::assertSame(['welcome'], $mail->sent, '문자가 실패해도 메일은 나가야 한다');
        self::assertStringContainsString('sms', $this->loggedText(), '실패한 채널은 로그에 남는다');
        self::assertStringContainsString('테스트 실패', $this->loggedText(), '실패 이유도 남는다');
    }

    /** 실패한 채널은 다시 부르지 않는다. 결과를 모르는 요청을 다시 보내면 진짜 전화기로
     *  두 번 간다 — 이 분기에서 재시도는 없다. */
    #[DataProvider('connectionProvider')]
    public function testAFailingChannelIsNeverRetried(array $dbConfig): void
    {
        $sms = $this->channel('sms', true, true);
        $mail = $this->channel('mail');
        $this->notifier($dbConfig, [$mail, $sms], ['mail', 'sms'])->notify('welcome', $this->to(), []);

        self::assertSame(1, $sms->calls, '실패한 채널을 다시 부르면 두 번 발송이 된다');
    }

    /** 문자·알림톡은 "보낼 수 있느냐"에 답하려고 DB 와 알리고 설정을 읽는다 — 그 질문에
     *  답하다 터지는 것도 그 채널만의 실패다. 메일은 그대로 나가야 한다. */
    #[DataProvider('connectionProvider')]
    public function testAChannelThatCannotEvenAnswerAvailableDoesNotStopTheOthers(array $dbConfig): void
    {
        $mail = $this->channel('mail');
        $this->notifier($dbConfig, [$mail, $this->brokenChannel('sms')], ['mail', 'sms'])
            ->notify('welcome', $this->to(), []);

        self::assertSame(['welcome'], $mail->sent);
        self::assertStringContainsString('available 가 터졌다', $this->loggedText());
    }

    /** 그 채널 하나뿐이었다면 아무 데도 못 간 것이다 — 조용히 넘어가면 안 된다. */
    #[DataProvider('connectionProvider')]
    public function testAChannelThatCannotAnswerAvailableCountsAsAFailure(array $dbConfig): void
    {
        $this->expectException(DomainError::class);
        $this->notifier($dbConfig, [$this->brokenChannel('sms')], ['sms'])
            ->notify('welcome', $this->to(), []);
    }

    #[DataProvider('connectionProvider')]
    public function testEveryChannelFailingRaises(array $dbConfig): void
    {
        $mail = $this->channel('mail', true, true);
        $this->expectException(DomainError::class);
        $this->notifier($dbConfig, [$mail], ['mail'])->notify('welcome', $this->to(), []);
    }

    /** 올라가는 예외는 화면에 그대로 보인다 — 채널이 뱉은 원문(DB 오류·알리고 응답)을
     *  거기에 실어 보내지 않는다. 그 원문은 로그에만 남는다. */
    #[DataProvider('connectionProvider')]
    public function testTheRaisedErrorDoesNotLeakTheChannelMessage(array $dbConfig): void
    {
        $mail = $this->channel('mail', true, true);
        try {
            $this->notifier($dbConfig, [$mail], ['mail'])->notify('welcome', $this->to(), []);
            self::fail('아무 데도 못 보냈는데 조용히 성공했습니다');
        } catch (DomainError $e) {
            self::assertSame('SERVICE_UNAVAILABLE', $e->code());
            self::assertStringNotContainsString('테스트 실패', $e->getMessage());
            self::assertSame([], $e->details());
        }
        self::assertStringContainsString('테스트 실패', $this->loggedText());
    }

    /**
     * 이 스택의 거절은 거의 전부 DomainError::validation() 이고, 그 getMessage() 는 언제나
     * '입력값을 확인해 주세요.' 라는 고정 문구다 — 진짜 이유는 details() 에만 있다.
     * 실패를 보이게 하려고 만든 자리에 아무것도 말하지 않는 문장을 남기면 안 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheLogSaysWhyTheChannelRefused(array $dbConfig): void
    {
        // 알리고 Dispatch 가 번호 하나도 못 쓸 때 실제로 던지는 모양 그대로.
        $sms = $this->throwingChannel('sms',
            DomainError::validation(['recipients' => '보낼 수 있는 수신번호가 없습니다.']));
        $this->notifier($dbConfig, [$this->channel('mail'), $sms], ['mail', 'sms'])
            ->notify('welcome', $this->to(), []);

        self::assertStringContainsString('recipients', $this->loggedText());
        self::assertStringContainsString('보낼 수 있는 수신번호가 없습니다.', $this->loggedText(),
            '고정 문구만 남기면 운영자는 무엇이 잘못됐는지 알 수 없다');
    }

    /** details() 의 값은 던지는 쪽이 만든 자유 문자열이다. 자격증명처럼 읽히는 칸의 값은
     *  적지 않는다 — 비밀이 생긴다면 바로 그 이름으로 온다. */
    #[DataProvider('connectionProvider')]
    public function testCredentialShapedDetailsAreNotWrittenToTheLog(array $dbConfig): void
    {
        $sms = $this->throwingChannel('sms',
            DomainError::validation(['api_key' => 'LIVE-KEY-abc123', 'senderkey' => 'SK-secret']));

        try {
            $this->notifier($dbConfig, [$sms], ['sms'])->notify('welcome', $this->to(), []);
            self::fail('아무 데도 못 보냈는데 조용히 성공했습니다');
        } catch (DomainError $e) {
        }

        self::assertStringContainsString('api_key=***', $this->loggedText());
        self::assertStringNotContainsString('LIVE-KEY-abc123', $this->loggedText());
        self::assertStringNotContainsString('SK-secret', $this->loggedText());
    }

    /**
     * 가리는 규칙이 실제로 쓰일 이름들을 하나씩 짚는다. 처음 규칙은 api[_-]?key 처럼
     * 온전한 이름만 알아봐서, 정작 이 저장소에서 다음 사람이 가장 쓸 법한 aligo_key 와
     * 맨 Authorization 이 그대로 찍혔다 — 가장 있을 법한 자리에서 새는 보험이었다.
     */
    #[DataProvider('connectionProvider')]
    public function testEveryCredentialShapedFieldNameIsRedacted(array $dbConfig): void
    {
        $fields = [
            'aligo_key' => 'LEAK-aligo-1',            // 이 저장소가 알리고 키를 부를 법한 이름
            'alimtalk_api_key' => 'LEAK-at-2',        // 실제 설정 칸 이름
            'senderkey' => 'LEAK-sk-3',
            'api_key' => 'LEAK-api-4',
            'apiKey' => 'LEAK-camel-5',
            'x-api-key' => 'LEAK-header-6',
            'Authorization' => 'LEAK-header-7',       // 전송 계층 예외가 헤더 이름을 실어 올 수 있다
            'AUTH' => 'LEAK-auth-8',
            'auth_token' => 'LEAK-token-9',
            'reset_token' => 'LEAK-token-10',
            'client_secret' => 'LEAK-oauth-11',
            'PASSWORD' => 'LEAK-pw-12',
            'session_id' => 'LEAK-session-13',
        ];
        $sms = $this->throwingChannel('sms', DomainError::validation($fields));

        try {
            $this->notifier($dbConfig, [$sms], ['sms'])->notify('welcome', $this->to(), []);
            self::fail('아무 데도 못 보냈는데 조용히 성공했습니다');
        } catch (DomainError $e) {
        }

        foreach ($fields as $name => $value) {
            self::assertStringContainsString($name . '=***', $this->loggedText(),
                $name . ' 은 자격증명처럼 읽히는 이름인데 값이 그대로 찍혔습니다');
            self::assertStringNotContainsString($value, $this->loggedText());
        }
    }

    /** 반대로, 멀쩡한 칸까지 가리면 1차 수정이 되살린 정보가 도로 사라진다. */
    #[DataProvider('connectionProvider')]
    public function testOrdinaryFieldsAreStillReadableInTheLog(array $dbConfig): void
    {
        $sms = $this->throwingChannel('sms', DomainError::validation([
            'recipients' => '보낼 수 있는 수신번호가 없습니다.',
            'tpl_code' => '사용 중인 승인 템플릿을 골라 주세요.',
            'body' => '본문을 입력해 주세요.',
            'sender' => '발신번호를 확인해 주세요.',
        ]));

        try {
            $this->notifier($dbConfig, [$sms], ['sms'])->notify('welcome', $this->to(), []);
            self::fail('아무 데도 못 보냈는데 조용히 성공했습니다');
        } catch (DomainError $e) {
        }

        self::assertStringContainsString('보낼 수 있는 수신번호가 없습니다.', $this->loggedText());
        self::assertStringContainsString('사용 중인 승인 템플릿을 골라 주세요.', $this->loggedText());
        self::assertStringContainsString('본문을 입력해 주세요.', $this->loggedText());
        self::assertStringContainsString('발신번호를 확인해 주세요.', $this->loggedText());
    }

    /** 긴 값은 잘라서 적는다 — 본문 한 통이 통째로 로그에 눕지 않게. */
    #[DataProvider('connectionProvider')]
    public function testALongDetailIsCutShort(array $dbConfig): void
    {
        $body = str_repeat('가', 500);
        $sms = $this->throwingChannel('sms', DomainError::validation(['body' => $body]));

        try {
            $this->notifier($dbConfig, [$sms], ['sms'])->notify('welcome', $this->to(), []);
            self::fail('아무 데도 못 보냈는데 조용히 성공했습니다');
        } catch (DomainError $e) {
        }

        self::assertStringNotContainsString($body, $this->loggedText());
        self::assertStringContainsString('…', $this->loggedText());
        self::assertLessThan(400, mb_strlen($this->loggedText()));
    }

    /**
     * 로그에 들어가는 것은 실패의 모양뿐이다. 수신자의 번호와 $vars(재설정 링크 같은)는
     * 실패를 알아보는 데 필요하지 않고, 링크가 로그에 남으면 그 로그를 읽는 사람이 계정을
     * 가져갈 수 있다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheLogCarriesNeitherTheRecipientNorTheMessage(array $dbConfig): void
    {
        $to = Recipient::forUser(['id' => '1', 'display_name' => '홍',
            'email' => 'a@example.com', 'phone' => '01012345678']);
        $sms = $this->throwingChannel('sms', DomainError::validation(['vars' => '값이 비어 있는 변수가 있습니다: 이름']));

        try {
            $this->notifier($dbConfig, [$sms], ['sms'])
                ->notify('welcome', $to, ['링크' => 'https://example.com/r/TOKEN-abc123']);
            self::fail('아무 데도 못 보냈는데 조용히 성공했습니다');
        } catch (DomainError $e) {
        }

        self::assertStringNotContainsString('TOKEN-abc123', $this->loggedText());
        self::assertStringNotContainsString('01012345678', $this->loggedText());
        self::assertStringNotContainsString('a@example.com', $this->loggedText());
    }

    /** 실패는 일어난 자리에서 바로 적는다. 모아 두었다가 끝나고 적으면, 뒤 채널이
     *  프로세스째 죽는 순간(타임아웃·메모리) 앞 채널의 실패 기록까지 함께 사라진다. */
    #[DataProvider('connectionProvider')]
    public function testEachFailureIsRecordedBeforeTheNextChannelRuns(array $dbConfig): void
    {
        $mail = $this->channel('mail', true, true);
        $inbox = $this->channel('inbox');
        $this->notifier($dbConfig, [$mail, $inbox], ['mail', 'inbox'], 'comment_new')
            ->notify('comment_new', $this->to(), []);

        self::assertSame(['log', 'send:inbox'], $this->timeline);
        self::assertStringContainsString('mail', $this->logged[0]);
    }

    /**
     * 채널은 상대 서버가 메시지를 받아들인 뒤에도 터질 수 있다 — 이 분기가 재시도하지
     * 않는 이유가 그것이다. 그래 놓고 화면이 "다시 시도해 주세요"라고 하면, 코드가
     * 일부러 하지 않는 일을 사람에게 시켜 진짜 전화기로 두 통을 보낸다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheRaisedErrorDoesNotAskForASecondSend(array $dbConfig): void
    {
        try {
            $this->notifier($dbConfig, [$this->channel('mail', true, true)], ['mail'])
                ->notify('welcome', $this->to(), []);
            self::fail('아무 데도 못 보냈는데 조용히 성공했습니다');
        } catch (DomainError $e) {
            self::assertStringNotContainsString('다시 시도', $e->getMessage());
            self::assertStringContainsString('관리자', $e->getMessage());
        }
    }

    #[DataProvider('connectionProvider')]
    public function testUnavailableChannelsAreSkippedQuietly(array $dbConfig): void
    {
        // 번호가 없어 문자가 불가능한 경우. 실패가 아니므로 예외가 없어야 한다.
        $sms = $this->channel('sms', false);
        $this->notifier($dbConfig, [$sms], ['sms'])->notify('welcome', $this->to(), []);

        self::assertSame([], $sms->sent);
    }

    /** 다만 조용히 넘어가는 것과 아무도 모르는 것은 다르다 — 켠 채널이 있는데 한 곳도
     *  보내지 못했으면 운영자가 볼 수 있게 로그에 남긴다. */
    #[DataProvider('connectionProvider')]
    public function testNothingDeliverableIsRecordedForTheOperator(array $dbConfig): void
    {
        $sms = $this->channel('sms', false);
        $this->notifier($dbConfig, [$sms], ['sms'])->notify('welcome', $this->to(), []);

        self::assertStringContainsString('welcome', $this->loggedText());
        self::assertStringContainsString('sms', $this->loggedText());
    }

    /** 반대로 관리자가 모든 채널을 꺼 둔 알림은 사고가 아니라 설정이다. 조용하다. */
    #[DataProvider('connectionProvider')]
    public function testAnEventWithNoChannelIsSilent(array $dbConfig): void
    {
        $mail = $this->channel('mail');
        $this->notifier($dbConfig, [$mail], [])->notify('welcome', $this->to(), []);

        self::assertSame([], $mail->sent);
        self::assertSame([], $this->logged, '꺼 둔 알림까지 로그를 채우면 진짜 사고가 묻힌다');
    }

    /**
     * 순서는 배선 차례도, 설정에 적힌 차례도 아닌 Notifier 가 정한 전달 우선순위다.
     * 그래서 채널을 일부러 거꾸로 배선해 두고 확인한다.
     */
    #[DataProvider('connectionProvider')]
    public function testChannelsRunInDeliveryOrderNotInWiringOrder(array $dbConfig): void
    {
        $channels = [$this->channel('sms'), $this->channel('alimtalk'),
            $this->channel('inbox'), $this->channel('mail')];
        $this->notifier($dbConfig, $channels, ['mail', 'alimtalk', 'sms', 'inbox'], 'comment_new')
            ->notify('comment_new', $this->to(), []);

        self::assertSame(['mail', 'inbox', 'alimtalk', 'sms'], $this->order);
    }

    /** 설정에 있는 채널은 모두 배달 순서에 자리가 있어야 한다. 빠진 채널은 켜 두어도
     *  영영 나가지 않는다 — 다섯 번째 채널이 생길 때 이 시험이 먼저 깨진다. */
    public function testEveryConfigurableChannelHasAPlaceInTheDeliveryOrder(): void
    {
        $order = (new \ReflectionClass(Notifier::class))->getConstant('ORDER');
        self::assertIsArray($order);
        sort($order);
        $channels = NotifySettings::CHANNELS;
        sort($channels);

        self::assertSame($channels, $order);
    }

    /**
     * 켜 둔 채널인데 그 채널 객체가 배선되지 않았다 — 설정이 아니라 조립의 결함이다.
     * 나머지 채널까지 막지는 않지만(메일은 나가야 한다) 조용히 넘어가지도 않는다.
     */
    #[DataProvider('connectionProvider')]
    public function testAnEnabledChannelWithNoObjectIsLoudButDoesNotStopTheRest(array $dbConfig): void
    {
        $mail = $this->channel('mail');
        $this->notifier($dbConfig, [$mail], ['mail', 'sms'])->notify('welcome', $this->to(), []);

        self::assertSame(['welcome'], $mail->sent);
        self::assertStringContainsString('sms', $this->loggedText());
    }

    /** 그 채널이 유일하게 켜 둔 채널이었다면 아무 데도 못 간 것이므로 예외다. */
    #[DataProvider('connectionProvider')]
    public function testAnEnabledChannelWithNoObjectCountsAsAFailure(array $dbConfig): void
    {
        $this->expectException(DomainError::class);
        $this->notifier($dbConfig, [$this->channel('mail')], ['sms'])->notify('welcome', $this->to(), []);
    }

    /**
     * 거꾸로, 설정이 절대 켤 수 없는 키를 가진 채널을 넘기는 것도 조립의 결함이다 —
     * 그 채널은 영영 쓰이지 않는다. 발송 때가 아니라 조립할 때 거절한다.
     */
    #[DataProvider('connectionProvider')]
    public function testAChannelKeyThatSettingsCanNeverEnableIsRefused(array $dbConfig): void
    {
        $settings = $this->settings($this->freshDatabase($dbConfig));

        $this->expectException(DomainError::class);
        new Notifier($settings, [$this->channel('push')]);
    }

    #[DataProvider('connectionProvider')]
    public function testTwoChannelsWithTheSameKeyAreRefused(array $dbConfig): void
    {
        $settings = $this->settings($this->freshDatabase($dbConfig));

        $this->expectException(DomainError::class);
        new Notifier($settings, [$this->channel('mail'), $this->channel('mail')]);
    }

    #[DataProvider('connectionProvider')]
    public function testSomethingThatIsNotAChannelIsRefused(array $dbConfig): void
    {
        $settings = $this->settings($this->freshDatabase($dbConfig));

        $this->expectException(DomainError::class);
        // 배열에는 타입이 없다 — 그래서 채널 아닌 것이 여기까지 올 수 있고, 여기서 막는다.
        new Notifier($settings, ['mail']);
    }

    /** 카탈로그에 없는 이벤트로 부르는 것은 오타이거나 지워진 이벤트를 부르는 호출부다.
     *  채널을 하나도 못 고르고 조용히 끝나면 그 결함은 몇 달을 산다. */
    #[DataProvider('connectionProvider')]
    public function testAnUnknownEventIsRefused(array $dbConfig): void
    {
        $notifier = $this->notifier($dbConfig, [$this->channel('mail')], ['mail']);

        $this->expectException(DomainError::class);
        $notifier->notify('welcome_typo', $this->to(), []);
    }

    /** $vars 는 손대지 않고 그대로 채널에 넘긴다 — 무엇을 거를지는 채널과 MessageVars 몫. */
    #[DataProvider('connectionProvider')]
    public function testVariablesReachTheChannelUntouched(array $dbConfig): void
    {
        $spy = new class () implements ChannelInterface {
            public array $vars = [];
            public function key(): string
            {
                return 'mail';
            }
            public function available(string $event, Recipient $to): bool
            {
                return true;
            }
            public function send(string $event, Recipient $to, array $vars): void
            {
                $this->vars = $vars;
            }
        };
        $this->notifier($dbConfig, [$spy], ['mail'])
            ->notify('welcome', $this->to(), ['이름' => '홍', '_post_id' => 3]);

        self::assertSame(['이름' => '홍', '_post_id' => 3], $spy->vars);
    }

    // ---------------------------------------------------------------- App 배선

    #[DataProvider('connectionProvider')]
    public function testAppWiresAllFourChannelsOnce(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $notifier = $app->notifier();
        $keys = array_keys((new \ReflectionProperty(Notifier::class, 'channels'))->getValue($notifier));
        sort($keys);

        self::assertSame(['alimtalk', 'inbox', 'mail', 'sms'], $keys);
        self::assertSame($notifier, $app->notifier(), '요청마다 한 번만 조립한다');
    }

    /**
     * App 의 게터는 new 가 끝난 **뒤에** 메모이즈한다. notifier() 가 조립 도중에
     * notificationService() 를 만들면, 그 서비스가 알림을 보내게 되는 날(5단계) 그
     * 게터가 다시 notifier() 를 불러 무한 재귀가 된다. 그래서 알림함 채널은 서비스가
     * 아니라 그것을 돌려줄 callable 을 쥔다 — 이 시험은 조립이 끝난 뒤에도 알림함
     * 서비스가 아직 만들어지지 않았음을 못박는다.
     */
    #[DataProvider('connectionProvider')]
    public function testBuildingTheNotifierDoesNotBuildTheNotificationService(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->notifier();

        self::assertNull((new \ReflectionProperty(App::class, 'notificationService'))->getValue($app),
            '알림함 서비스를 조립 중에 만들면 5단계에서 무한 재귀가 된다');
    }

    /**
     * 그리고 그 날이 오면 실제로 어떻게 되는지를 여기서 미리 돌려 본다. App 과 같은
     * 모양(지연 + new 뒤 메모이즈)의 작은 컨테이너를 만들되, 이번에는 알림함 서비스가
     * 알림 발송기를 필요로 하게 한다. 재귀가 생기면 스택이 터져 스위트 전체가 죽으므로
     * 컨테이너가 재진입을 먼저 잡아 시험 실패로 바꾼다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheInboxWiringSurvivesAServiceThatNeedsTheNotifier(array $dbConfig): void
    {
        $db = $this->freshDatabase($dbConfig);
        $settings = $this->settings($db);
        $container = new class ($db, $settings) {
            public ?Notifier $notifier = null;
            public ?NotificationService $service = null;
            private bool $building = false;

            public function __construct(private Connection $db, private NotifySettings $settings)
            {
            }

            public function notifier(): Notifier
            {
                if ($this->notifier === null) {
                    if ($this->building) {
                        throw new \RuntimeException('notifier() 가 조립 도중 자기 자신을 다시 요구했습니다');
                    }
                    $this->building = true;
                    $notifier = new Notifier($this->settings, [
                        new InboxChannel(fn (): NotificationService => $this->service()),
                    ]);
                    $this->notifier = $notifier;
                    $this->building = false;
                }

                return $this->notifier;
            }

            public function service(): NotificationService
            {
                if ($this->service === null) {
                    // 5단계의 모습: 알림함을 쓰는 서비스가 알림 발송기도 쓴다.
                    $this->notifier();
                    $this->service = new NotificationService(new NotificationRepository($this->db),
                        new PostRepository($this->db), new CommentRepository($this->db));
                }

                return $this->service;
            }
        };

        $container->notifier()->notify('comment_new', Recipient::forUser(['id' => '7', 'display_name' => '홍']),
            ['글제목' => '첫 글', '작성자' => '김철수',
                '_kind' => NotificationService::KIND_COMMENT, '_post_id' => '3']);

        self::assertSame('7', $db->selectOne('SELECT * FROM ' . $db->table('notifications'))['user_id']);
    }

    /**
     * canReach() 는 켠 채널에게 "이 모양의 사람에게 갈 수 있느냐"를 묻는다. 하나라도
     * 그렇다고 하면 참이다 — 전부 그래야 참인 것이 아니다. AND 로 바뀌면 메일은 멀쩡히
     * 나가는데 화면은 "보낼 수 없습니다"라고 말하게 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testCanReachIsTrueWhenAnySingleChannelCanReach(array $dbConfig): void
    {
        $notifier = $this->notifier($dbConfig,
            [$this->channel('mail'), $this->channel('sms', false)], ['mail', 'sms']);

        self::assertTrue($notifier->canReach('welcome', $this->to()));
    }

    #[DataProvider('connectionProvider')]
    public function testCanReachIsFalseWhenNoChannelCanReach(array $dbConfig): void
    {
        $notifier = $this->notifier($dbConfig,
            [$this->channel('mail', false), $this->channel('sms', false)], ['mail', 'sms']);

        self::assertFalse($notifier->canReach('welcome', $this->to()));
    }

    /**
     * 답을 못 한 채널은 "못 간다"로 치지 않는다. 문자·알림톡은 DB 와 알리고 설정을 읽으며
     * 답하므로 여기서 터질 수 있는데, 그때 "못 간다"고 단정하면 DB 가 한 번 흔들린 사이
     * 가입이 통째로 막힌다. 모르면 된다고 보고 넘어가되, 실제 발송에서 다시 터지면 그때는
     * notify() 가 시끄럽게 실패한다 — 조용히 갇히는 쪽만 막으면 된다.
     */
    #[DataProvider('connectionProvider')]
    public function testAChannelThatCannotAnswerCountsAsMightReach(array $dbConfig): void
    {
        $notifier = $this->notifier($dbConfig, [$this->brokenChannel('sms')], ['sms']);

        self::assertTrue($notifier->canReach('welcome', $this->to()));
    }

    /** 켠 채널이 하나도 없으면 물어볼 것도 없다. */
    #[DataProvider('connectionProvider')]
    public function testCanReachIsFalseWhenNothingIsOn(array $dbConfig): void
    {
        $notifier = $this->notifier($dbConfig, [$this->channel('mail')], [], 'welcome');

        self::assertFalse($notifier->canReach('welcome', $this->to()));
    }

    /** 메일 전송기·알리고를 바꾸면 이미 조립된 알림 발송기도 다시 만든다 — 그러지 않으면
     *  시험이 가짜로 바꿔 둔 뒤에도 진짜 전송기가 알림을 내보낸다. */
    #[DataProvider('connectionProvider')]
    public function testReplacingTheMailerOrAligoRebuildsTheNotifier(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->notifier();

        $mailer = new CollectingMailer();
        $app->setMailer($mailer);
        $channels = (new \ReflectionProperty(Notifier::class, 'channels'))->getValue($app->notifier());
        self::assertSame($mailer,
            (new \ReflectionProperty(MailChannel::class, 'mailer'))->getValue($channels['mail']));

        $aligo = new AligoService($app->db(), new FakeAligoTransport(), new SecretCipher('x'));
        $app->setAligo($aligo);
        $channels = (new \ReflectionProperty(Notifier::class, 'channels'))->getValue($app->notifier());
        self::assertSame($aligo,
            (new \ReflectionProperty(SmsChannel::class, 'aligo'))->getValue($channels['sms']));
    }
}
