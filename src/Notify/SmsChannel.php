<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Aligo\AligoService;
use GnuCms\Error\DomainError;

/**
 * 문자 채널. 본문은 관리자가 알림마다 써 둔 것(NotifySettings::smsBody())을 쓰고,
 * 발송은 관리자 발송 화면과 **같은 문**(AligoService::send())으로 나간다.
 *
 * 그래서 알림 한 통마다 message_jobs 행 하나가 생긴다. 의도한 것이다 — 진짜로 돈이
 * 드는 발송이고, 운영자가 발송 이력에서 봐야 하는 건이다. 알림용으로 더 가벼운 두 번째
 * 발송 경로를 만들지 않는다(그 경로는 채널 허용 스위치·길이 분류·중복 제거·결과 조회를
 * 저마다 다시 틀리게 구현할 자리가 된다).
 */
final class SmsChannel implements ChannelInterface
{
    private AligoService $aligo;
    private NotifySettings $settings;

    public function __construct(AligoService $aligo, NotifySettings $settings)
    {
        $this->aligo = $aligo;
        $this->settings = $settings;
    }

    public function key(): string
    {
        return 'sms';
    }

    /**
     * 네 가지를 모두 만족해야 보낼 수 있다: 받을 번호가 있고, 이 알림이 전화로 보낼 수
     * 있는 것이고, 관리자가 이 알림의 문자 본문을 써 두었고, 알리고 문자 발송이 켜져 있다.
     *
     * **Events::phoneCapable() 은 일부러 남긴 죽은 가지다.** NotifySettings 가 이미
     * 전화 불가 이벤트에서 전화 채널을 걸러 내므로(channelsFor()), 이 검사를 지워도
     * 스위트는 초록이다 — **어떤 테스트도 이 줄을 죽일 수 없다.** 그래도 두는 이유는
     * NotifySettings 가 읽을 때마다 저장된 행을 다시 따지는 것(validTemplate())과 같다:
     * 읽는 쪽이 저장된 설정을 믿는 대신 카탈로그에서 스스로 한 번 더 확인한다.
     * 이메일로만 확인되는 수신자(email_verify 같은)에게 문자가 나가는 것은 되돌릴 수
     * 없는 종류의 사고이고, 그것을 막는 층이 하나뿐인 편보다 둘인 편이 낫다.
     * 지우려는 사람에게: 이 줄이 테스트로 보호되지 않는다는 사실은 결함이 아니라
     * 위 문단이 설명하는 의도다.
     */
    public function available(string $event, Recipient $to): bool
    {
        return $to->phone !== null
            && Events::phoneCapable($event)
            && $this->settings->smsBody($event) !== ''
            && $this->aligo->settings->isEnabled('sms');
    }

    public function send(string $event, Recipient $to, array $vars): void
    {
        if (!$this->available($event, $to)) {
            throw DomainError::validation(['sms' => '문자로 보낼 수 없는 알림입니다.']);
        }

        $this->aligo->send([
            'channel' => 'sms',
            'body' => $this->settings->smsBody($event),
            'event_key' => $event,
            // 카탈로그 변수만 넘긴다. 채널 문맥(_post_id 등)은 여기서 걸러지므로 본문에
            // 닿을 수 없고, 그런 이름을 담은 본문은 값을 찾지 못해 Variables::apply()
            // 가 거절한다 — 조용히 채워 내보내지 않는다.
            'recipients' => [['phone' => $to->phone, 'name' => $to->name,
                'user_id' => $to->userId, 'vars' => MessageVars::forBody($event, $vars)]],
        ]);
    }
}
