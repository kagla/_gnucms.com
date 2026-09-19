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
 *              아무 일도 하지 않고 조용히 끝난다. 다만 **켜 둔 것이 있었는데 설정 쪽
 *              재확인이 그것을 도로 껐다면** 그건 설정이 아니라 사고에 가깝다 —
 *              그때는 한 줄을 남긴다(recordRevoked()).
 *
 * **재시도는 하지 않는다.** 실패한 채널을 다시 부르지 않는다. 문자·알림톡은 요청이
 * 나갔는지 모르는 채로 끊기는 일이 흔하고, 그때 한 번 더 보내면 진짜 전화기로 두 통이
 * 간다. 다시 보낼지는 사람이 정한다.
 *
 * **무엇이 나갔는지는 돌려준다.** notify() 는 한 채널이라도 실제로 나갔으면 true 를,
 * 아무 데도 가지 않았으면 false 를 돌려준다(켠 채널이 없거나, 켠 채널이 전부 건너뛰기였거나).
 * 실패는 여전히 예외다. 부르는 쪽이 화면에 "보냈습니다"라고 적어도 되는지를 이 값 하나로
 * 판단할 수 있어야 하기 때문이다 — "불러 봤다"를 "갔다"로 그리는 것이 이 저장소가 반복해서
 * 만들어 온 결함이다. 돌려받은 값을 안 쓰는 호출부는 지금까지와 똑같이 동작한다.
 *
 * **아무 데도 못 갔으면 소리를 낸다.** 시도한 채널이 있는데 한 곳도 성공하지 못하면
 * 예외를 올린다 — 아무 데도 안 갔는데 화면이 "보냈습니다"라고 말하면 안 되기 때문이다.
 * 시도할 수 있는 채널이 처음부터 없었던 경우(켜 두었지만 전부 건너뛰기)는 예외 대신
 * 로그다: 번호 없는 회원 하나 때문에 가입 자체가 실패하면 안 되지만, "켜 두었는데 아무
 * 데도 나가지 않는다"는 사실은 운영자가 알아야 한다.
 *
 * **잡는 것.** 채널이 던지는 것은 무엇이든 잡는다(Throwable). DomainError 만 잡으면
 * 정작 흔한 실패 — 알리고 쪽 연결 끊김, PDO 오류 — 가 그대로 올라가 다른 채널을 막는다.
 * 잡은 것은 터진 그 자리에서 곧바로 로그로 간다 — 클래스 이름, 문구, 그리고 DomainError 면
 * 진짜 이유가 들어 있는 details() 까지(reason() 주석). 조용히 사라지지 않는다.
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

    /**
     * 이런 이름의 칸은 값을 로그에 적지 않는다(이름은 적는다 — 어느 칸이 거절했는지는
     * 알아야 한다). reason() 주석이 이유를, 여기가 범위를 정한다.
     *
     * **조각으로 맞춘다.** 앞뒤에 무엇이 붙든 이 조각이 이름 안에 있으면 가린다:
     * key 하나로 api_key·apiKey·x-api-key·aligo_key·senderkey·alimtalk_api_key 가 모두
     * 걸리고, auth 하나로 Authorization·AUTH·auth_token·oauth 가 걸린다. 처음 규칙은
     * api[_-]?key 처럼 온전한 이름을 적어 두었는데, 정작 이 저장소에서 다음 사람이 가장
     * 쓸 법한 이름(aligo_key)이 빠져 있었다.
     *
     * **애매하면 가린다.** 이 규칙은 자격증명이 아닌 칸까지 몇 개 가린다(keyword 같은
     * 이름이나, 지금 senderkey 가 담고 있는 안내 문구). 그 대가는 로그 한 줄에서 문장
     * 하나가 '***' 로 바뀌는 것뿐이고, 반대 방향의 대가는 살아 있는 키가 로그 파일에
     * 평문으로 남는 것이다. 같은 값이 아니다.
     *
     * **못 잡는 것.** 이름은 멀쩡한데 값 속 문장 한가운데 비밀이 섞여 오는 경우
     * (body 에 링크가 들어간 채 거절되는 식)는 이름으로 판단하는 규칙이 잡을 수 없다.
     * 그건 길이 자르기(DETAIL_MAX)와 "수신자·$vars 는 아예 안 적는다"는 규칙이 줄일 뿐
     * 없애지는 못한다 — 로그 값마다 내용을 훑는 쪽이 문제보다 나쁘다.
     */
    private const SECRET_FIELD =
        '/(key|secret|password|passwd|pwd|token|auth|credential|signature|session|cookie)/i';

    /** details() 값 하나를 로그에 적을 때의 길이 상한(글자 수). */
    private const DETAIL_MAX = 200;

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

    /**
     * **이런 모양의 사람**에게 이 알림이 닿을 수 있는가. 켜져 있는 채널 가운데 하나라도
     * 그 사람에게 available() 이라고 답하면 참이다.
     *
     * **"켜져 있는가"로는 모자란다.** 채널이 켜져 있다는 것과 그 채널이 이 사람에게 쓸 수
     * 있다는 것은 다른 사실이다. 알림함은 comment_new 말고는 무엇도 받지 않고, 문자는
     * 번호 없는 사람에게 갈 수 없다 — 그래서 email_verify 를 알림함 하나로만 켜 두면
     * "켜져 있다"는 참인데 인증 링크는 영영 아무 데도 가지 않는다. 그 답을 믿고 가입을
     * 받으면 그 사람은 자기 계정 밖에 갇힌다.
     *
     * **그런데 진짜 수신자로 물으면 그 답이 계정 목록이 된다.** 없는 주소와 있는 주소의
     * 답이 갈리기 때문이다. 그래서 이 메서드는 수신자를 **찾지 않고** 부르는 쪽이 건네는
     * 본으로만 묻는다: 갓 가입할 사람의 모양(주소만, 번호도 회원 번호도 없음), 또는
     * "가장 잘 닿는 사람"의 모양. 값이 아니라 모양이 답을 정하므로, 같은 모양이면 누구를
     * 넣어도 답이 같다. 어떤 본을 쓸지는 화면마다 다르고, 그 선택은 부르는 쪽이 한다.
     *
     * **답을 못 한 채널은 "못 간다"로 치지 않는다.** available() 은 문자·알림톡이 DB 와
     * 알리고 설정을 읽으며 답하는 자리라 터질 수 있다. 그때 "못 간다"고 단정하면 DB 가
     * 한 번 흔들린 사이 가입이 통째로 막힌다. 모르면 된다고 보고 넘어가되, 실제 발송에서
     * 다시 터지면 그때는 notify() 가 시끄럽게 실패한다 — 조용히 갇히는 쪽만 막으면 된다.
     */
    public function canReach(string $event, Recipient $to): bool
    {
        foreach (array_intersect(self::ORDER, $this->settings->channelsFor($event)) as $key) {
            $channel = $this->channels[$key] ?? null;
            if ($channel === null) {
                continue;
            }
            try {
                if ($channel->available($event, $to)) {
                    return true;
                }
            } catch (\Throwable $e) {
                return true;
            }
        }

        return false;
    }

    /** @return bool 한 채널이라도 실제로 나갔는가. 실패(전부 실패)는 예외로 나간다. */
    public function notify(string $event, Recipient $to, array $vars): bool
    {
        // 카탈로그에 없는 이벤트는 호출부의 오타이거나 지워진 이벤트를 부르는 코드다.
        // channelsFor() 는 그런 이벤트에 빈 목록을 돌려주므로, 여기서 막지 않으면
        // "설정이 비어 있다"와 구별되지 않은 채 조용히 아무 일도 일어나지 않는다.
        if (!Events::exists($event)) {
            throw DomainError::internal('알 수 없는 알림입니다: ' . $event);
        }

        $wanted = $this->settings->channelsFor($event);
        if ($wanted === []) {
            $this->recordRevoked($event);

            return false;
        }

        $delivered = 0;
        $failed = 0;

        foreach (array_intersect(self::ORDER, $wanted) as $key) {
            $channel = $this->channels[$key] ?? null;
            if ($channel === null) {
                // 켜 두었는데 채널 객체가 없다. 설정이 아니라 조립의 결함이므로 건너뛰기가
                // 아니라 실패로 센다 — 이것 하나만 켜져 있었다면 예외로 드러난다.
                $failed++;
                $this->recordFailure($event, $key . ': 이 앱에 배선되지 않은 채널입니다');
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
                // 모아 두었다가 끝나고 적지 않는다. 뒤 채널이 프로세스째 죽으면(타임아웃·
                // 메모리) 모아 둔 기록은 함께 사라지고, 운영자에게는 아무 일도 없었던 것처럼
                // 보인다 — 실패는 일어난 자리에서 바로 남긴다.
                $failed++;
                $this->recordFailure($event, self::reason($key, $e));
            }
        }

        if ($delivered > 0) {
            return true;
        }

        if ($failed > 0) {
            // 이유는 로그에만 남긴다. 이 예외의 문구는 화면에 그대로 나가므로, 알리고
            // 응답이나 DB 오류를 거기에 실어 보내지 않는다.
            //
            // "잠시 후 다시 시도해 주세요"라고 말하지 않는다. 채널은 상대 서버가 메시지를
            // 받아들인 **뒤에도** 터질 수 있고(응답을 못 받은 발송), 이 분기는 바로 그래서
            // 재시도하지 않는다. 코드가 일부러 하지 않는 일을 사람에게 시키면 진짜 전화기로
            // 두 통이 간다.
            throw DomainError::serviceUnavailable(
                '알림을 보내지 못했습니다. 다시 보내면 같은 알림이 두 번 갈 수 있으니 관리자에게 알려 주세요.');
        }
        // 실패도 없는데 한 통도 못 보냈다 = 켠 채널이 전부 건너뛰기였다.
        ($this->log)('알림 ' . $event . ' — 켜 둔 채널(' . implode(', ', $wanted)
            . ') 중 지금 보낼 수 있는 것이 없어 아무 데도 나가지 않았습니다');

        return false;
    }

    private function recordFailure(string $event, string $reason): void
    {
        ($this->log)('알림 ' . $event . ' 발송 실패 — ' . $reason);
    }

    /**
     * 켤 채널이 하나도 없는 두 경우를 가른다.
     *
     * **관리자가 아무것도 켜 두지 않았다** — 설정이지 사고가 아니다. 조용히 끝낸다.
     * 이 저장소의 기본값이 이미 그런 알림(welcome)을 갖고 있고, 그 한 줄이 매번 찍히면
     * 정작 읽어야 할 줄을 덮는다.
     *
     * **관리자는 켜 두었는데 엔진이 도로 껐다** — channelsFor() 의 재확인(전화 가능·
     * 알림함 가능·템플릿 유효)이 비어 있지 않던 설정을 비운 경우다. 카카오 승인이 풀린
     * 알림톡 템플릿 하나로 댓글 알림이 통째로 멈추는 것이 이 길이고, 관리자는 아무것도
     * 건드리지 않았다. 세 경우(설정·전부 건너뛰기·미배선) 가운데 **가장 알아채기 어려운
     * 것이 이것인데** 유일하게 아무 줄도 남지 않았다: 한 발 앞선 상태(알리고 채널 스위치
     * 끄기)는 채널이 available() === false 로 답해 시끄러운데, 한 발 더 간 이 상태는
     * 조용했다. comment_new·welcome 처럼 돌려받은 값을 안 보는 호출부에서는 이 줄이
     * 운영자가 받는 유일한 신호다.
     *
     * 이유는 짚어 말하지 않는다 — 여기서는 "무엇이 비웠는가"를 알 수 없고(재확인 넷 중
     * 어느 것이든 될 수 있다), 지어낸 이유는 침묵보다 나쁘다. 대신 무엇이 꺼졌는지와
     * 어디를 봐야 하는지를 말한다. 화면(알림 설정)은 그 이유까지 말할 수 있다.
     */
    private function recordRevoked(string $event): void
    {
        $stored = $this->settings->storedChannelsFor($event);
        if ($stored === []) {
            return;
        }

        ($this->log)('알림 ' . $event . ' — 켜 둔 채널(' . implode(', ', $stored)
            . ')이 지금은 더는 쓸 수 없는 상태여서 아무 데도 나가지 않았습니다.'
            . ' 알림 설정 화면에서 이유를 확인해 주세요.');
    }

    /**
     * 실패 한 건을 로그에 적을 문장.
     *
     * **예외 문구만으로는 아무것도 알 수 없다.** 이 스택의 거절은 거의 전부
     * DomainError::validation() 이고 그 getMessage() 는 언제나 '입력값을 확인해 주세요.'
     * 라는 고정 문구다 — 어느 칸이 왜 거절됐는지는 details() 에만 있다(알리고 Dispatch 의
     * '보낼 수 있는 수신번호가 없습니다.', 알림함의 '적을 값이 모자랍니다' 가 모두 그렇다).
     * 그래서 details() 를 함께 적는다. 이것이 로그 한 줄의 전부다: 예외 문구와 details()
     * 뿐이고, **수신자(번호·주소)도 $vars(링크·토큰)도 넣지 않는다** — 실패를 알아보는 데
     * 필요하지 않고, 비밀번호 재설정 링크가 로그에 남으면 그 로그를 읽는 사람이 계정을
     * 가져갈 수 있다.
     *
     * **details() 의 값은 던지는 쪽이 만든 자유 문자열이다.** 지금 이 스택에서 그 값은
     * 사람에게 보여 줄 안내 문구뿐이지만, 앞으로도 그러리라는 보장은 없다. 그래서 둘을
     * 지킨다: 자격증명처럼 읽히는 칸 이름의 값은 적지 않고(비밀이 생긴다면 바로 그 이름으로
     * 온다 — 지금도 api_key·senderkey 라는 칸이 있다), 나머지 값도 길이를 잘라 본문 같은
     * 것이 통째로 흘러나오지 않게 한다.
     */
    private static function reason(string $key, \Throwable $e): string
    {
        $reason = $key . ': ' . get_class($e) . ': ' . $e->getMessage();
        $parts = [];
        foreach ($e instanceof DomainError ? $e->details() : [] as $field => $value) {
            $name = (string) $field;
            $parts[] = $name . '=' . (preg_match(self::SECRET_FIELD, $name) === 1
                ? '***'
                : self::shorten($value));
        }

        return $parts === [] ? $reason : $reason . ' (' . implode(', ', $parts) . ')';
    }

    private static function shorten(mixed $value): string
    {
        // 배열·객체는 문자열로 뭉개지 않는다(캐스팅하면 'Array' 한 단어가 남고 경고가 뜬다).
        if (!is_scalar($value)) {
            return get_debug_type($value);
        }
        $text = (string) $value;

        return mb_strlen($text) > self::DETAIL_MAX
            ? mb_substr($text, 0, self::DETAIL_MAX) . '…'
            : $text;
    }
}
