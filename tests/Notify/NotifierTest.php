<?php

declare(strict_types=1);

namespace GnuCms\Tests\Notify;

use GnuCms\Aligo\AlimtalkApi;
use GnuCms\Aligo\Settings as AligoSettings;
use GnuCms\Aligo\SettingsRepository as AligoSettingsRepository;
use GnuCms\Aligo\Templates;
use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use GnuCms\Notify\ChannelInterface;
use GnuCms\Notify\Notifier;
use GnuCms\Notify\NotifySettings;
use GnuCms\Notify\Recipient;
use GnuCms\Notify\SettingsRepository;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->logged = [];
        $this->order = [];
    }

    private function channel(string $key, bool $available = true, bool $throws = false): ChannelInterface
    {
        $record = function (string $sent): void {
            $this->order[] = $sent;
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
        /** @phpstan-ignore-next-line 배열은 타입이 없다 — 그래서 여기서 막는다 */
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
}
