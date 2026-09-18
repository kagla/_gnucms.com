<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Error\DomainError;

/**
 * 코어 알림의 유일한 출구. 알림 하나를 관리자가 켜 둔 채널 모두로 퍼뜨린다.
 * 어느 채널로 나갈지는 NotifySettings 가 정하고, 보낼 수 있는지는 채널이 답하며,
 * 이 클래스는 그 둘을 이어 "무엇이 실패이고 무엇이 아닌가"만 판단한다.
 *
 * **세 가지를 구별한다.**
 *   건너뛰기 — 채널은 있는데 지금 이 수신자에게 전할 수단이 없다(번호 없는 회원의 문자,
 *              손님의 알림함). available() 이 거짓인 경우다. 실패가 아니다: 번호가 없다는
 *              이유로 메일까지 막히면 안 된다.
 *   실패    — 보낼 수 있다고 해 놓고 send() 가 예외를 던졌다(available() 이 답하다가 터진
 *              것도 같다 — 못 보낸다고 답한 것이 아니라 답을 못 한 것이다). 이 채널만 실패한 것이고,
 *              나머지 채널은 그대로 계속 간다(비밀번호 재설정은 문자가 죽어도 메일로 가야
 *              한다). 원문은 로그에 남긴다.
 *   설정    — 관리자가 이 알림의 채널을 하나도 켜 두지 않았다. 사고가 아니라 설정이므로
 *              아무 일도 하지 않고 조용히 끝난다.
 *
 * **재시도는 하지 않는다.** 실패한 채널을 다시 부르지 않는다. 문자·알림톡은 요청이
 * 나갔는지 모르는 채로 끊기는 일이 흔하고, 그때 한 번 더 보내면 진짜 전화기로 두 통이
 * 간다. 다시 보낼지는 사람이 정한다.
 *
 * **아무 데도 못 갔으면 소리를 낸다.** 시도한 채널이 있는데 한 곳도 성공하지 못하면
 * 예외를 올린다 — 아무 데도 안 갔는데 화면이 "보냈습니다"라고 말하면 안 되기 때문이다.
 * 시도할 수 있는 채널이 처음부터 없었던 경우(켜 두었지만 전부 건너뛰기)는 예외 대신
 * 로그다: 번호 없는 회원 하나 때문에 가입 자체가 실패하면 안 되지만, "켜 두었는데 아무
 * 데도 나가지 않는다"는 사실은 운영자가 알아야 한다.
 *
 * **잡는 것.** 채널이 던지는 것은 무엇이든 잡는다(Throwable). DomainError 만 잡으면
 * 정작 흔한 실패 — 알리고 쪽 연결 끊김, PDO 오류 — 가 그대로 올라가 다른 채널을 막는다.
 * 잡은 것은 클래스 이름과 함께 전부 로그에 남으므로 조용히 사라지지 않는다.
 */
final class Notifier
{
    /**
     * 전달 우선순위. 설정에 적힌 차례도, 배선된 차례도 아니다 — 관리자가 화면에서
     * 채널을 어떤 순서로 켜든 이 차례로 나간다.
     *
     * 믿을 수 있고 값이 안 드는 것부터 간다: 메일은 코어가 예전부터 쓰던 길이고 계정을
     * 되찾는 마지막 수단이며, 알림함은 같은 DB 에 한 줄 적는 일이라 거의 실패하지 않는다.
     * 그 뒤가 바깥 서비스를 타는 둘이고, 그중 돈이 더 들고 더 잘 흔들리는 문자가 맨
     * 뒤다. 채널마다 실패를 가두므로 이 차례는 보통 눈에 띄지 않지만, 가둘 수 없는 사고
     * (치명적 오류·타임아웃·프로세스 종료)가 났을 때 무엇이 이미 나갔는지를 이 차례가
     * 정한다. 여기 없는 채널 키는 영영 나가지 않으므로, NotifySettings::CHANNELS 와 이
     * 목록이 같은 집합인지는 시험이 지킨다.
     */
    private const ORDER = ['mail', 'inbox', 'alimtalk', 'sms'];

    private NotifySettings $settings;

    /** @var array<string,ChannelInterface> 채널 키 => 채널 */
    private array $channels = [];

    /** @var \Closure(string): void */
    private \Closure $log;

    /**
     * @param list<ChannelInterface> $channels
     * @param (callable(string): void)|null $log 기본은 PHP 오류 로그. 시험이 바꿔 끼운다.
     */
    public function __construct(NotifySettings $settings, array $channels, ?callable $log = null)
    {
        $this->settings = $settings;
        foreach ($channels as $channel) {
            // 배열에는 타입이 없다. 조립이 틀렸다면 발송 때가 아니라 지금 드러나야 한다.
            if (!$channel instanceof ChannelInterface) {
                throw DomainError::internal('알림 채널이 아닌 것이 섞여 있습니다: '
                    . get_debug_type($channel));
            }
            $key = $channel->key();
            // 설정이 켤 수 없는 키를 가진 채널은 영영 쓰이지 않는다 — 배선한 사람은
            // 켰다고 믿는데 알림은 나가지 않는, 가장 알아채기 어려운 결함이다.
            if (!in_array($key, NotifySettings::CHANNELS, true)) {
                throw DomainError::internal('설정이 켤 수 없는 알림 채널입니다: ' . $key);
            }
            if (isset($this->channels[$key])) {
                throw DomainError::internal('알림 채널 키가 겹칩니다: ' . $key);
            }
            $this->channels[$key] = $channel;
        }
        $this->log = $log === null
            ? static function (string $line): void {
                error_log('[' . GNUCMS_ID . '] ' . $line);
            }
            : \Closure::fromCallable($log);
    }

    public function notify(string $event, Recipient $to, array $vars): void
    {
        // 카탈로그에 없는 이벤트는 호출부의 오타이거나 지워진 이벤트를 부르는 코드다.
        // channelsFor() 는 그런 이벤트에 빈 목록을 돌려주므로, 여기서 막지 않으면
        // "설정이 비어 있다"와 구별되지 않은 채 조용히 아무 일도 일어나지 않는다.
        if (!Events::exists($event)) {
            throw DomainError::internal('알 수 없는 알림입니다: ' . $event);
        }

        $wanted = $this->settings->channelsFor($event);
        if ($wanted === []) {
            return;
        }

        $delivered = 0;
        $failures = [];

        foreach (array_intersect(self::ORDER, $wanted) as $key) {
            $channel = $this->channels[$key] ?? null;
            if ($channel === null) {
                // 켜 두었는데 채널 객체가 없다. 설정이 아니라 조립의 결함이므로 건너뛰기가
                // 아니라 실패로 센다 — 이것 하나만 켜져 있었다면 예외로 드러난다.
                $failures[] = $key . ': 이 앱에 배선되지 않은 채널입니다';
                continue;
            }
            try {
                // 보낼 수 있는지는 채널에게 먼저 묻는다. send() 안의 거절은 마지막 방어선이지
                // 이 자리의 관문이 아니다. available() 도 try 안에 있다 — 문자·알림톡은 그
                // 질문에 답하려고 DB 와 알리고 설정을 읽으므로 여기서도 터질 수 있고, 그때
                // 예외가 그대로 올라가면 가두려던 실패가 메일까지 데려간다.
                if (!$channel->available($event, $to)) {
                    continue;
                }
                $channel->send($event, $to, $vars);
                $delivered++;
            } catch (\Throwable $e) {
                $failures[] = $key . ': ' . get_class($e) . ': ' . $e->getMessage();
            }
        }

        foreach ($failures as $failure) {
            ($this->log)('알림 ' . $event . ' 발송 실패 — ' . $failure);
        }
        if ($delivered > 0) {
            return;
        }

        if ($failures !== []) {
            // 원문은 로그에만 남긴다. 이 예외의 문구는 화면에 그대로 나가므로, 알리고
            // 응답이나 DB 오류를 거기에 실어 보내지 않는다.
            throw DomainError::serviceUnavailable(
                '알림을 보내지 못했습니다. 잠시 후 다시 시도해 주세요.');
        }
        // 실패도 없는데 한 통도 못 보냈다 = 켠 채널이 전부 건너뛰기였다.
        ($this->log)('알림 ' . $event . ' — 켜 둔 채널(' . implode(', ', $wanted)
            . ') 중 지금 보낼 수 있는 것이 없어 아무 데도 나가지 않았습니다');
    }
}
