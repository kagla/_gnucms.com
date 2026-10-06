<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Aligo\AligoService;
use GnuCms\Error\DomainError;

/**
 * 알림톡 채널. 본문은 카카오 승인 템플릿의 것이고, 이 채널이 하는 일은 코어 변수값을
 * 그 템플릿의 변수 이름으로 바꿔 끼우는 것뿐이다.
 *
 * 저장된 템플릿을 그대로 믿지 않는다 — NotifySettings::templateFor() 가 읽을 때마다
 * 승인·존재·본문 일치를 다시 확인하고, 하나라도 어긋나면 null 을 돌려준다. 그때
 * available() 은 false 가 되어 이 채널만 조용히 건너뛴다(메일은 그대로 나간다).
 *
 * 발송은 SmsChannel 과 같은 이유로 AligoService::send() 하나만 거친다.
 */
final class AlimtalkChannel implements ChannelInterface
{
    private AligoService $aligo;
    private NotifySettings $settings;
    private string $siteUrl;

    public function __construct(AligoService $aligo, NotifySettings $settings, string $siteUrl = '')
    {
        $this->aligo = $aligo;
        $this->settings = $settings;
        $this->siteUrl = $siteUrl;
    }

    public function key(): string
    {
        return 'alimtalk';
    }

    /**
     * 네 가지를 모두 만족해야 보낼 수 있다: 받을 번호가 있고, 이 알림이 전화로 보낼 수
     * 있는 것이고, 관리자가 고른 승인 템플릿이 지금도 살아 있고, 알리고 알림톡 발송이 켜져 있다.
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
            && $this->settings->templateFor($event) !== null
            && $this->aligo->settings->isEnabled('at');
    }

    public function send(string $event, Recipient $to, array $vars): void
    {
        // available() 이 답이고, null 검사는 그 뒤 타입을 위해 남긴다(available() 이
        // 참이면 템플릿은 반드시 있다).
        $template = $this->settings->templateFor($event);
        if ($template === null || !$this->available($event, $to)) {
            throw DomainError::validation(['alimtalk' => '알림톡으로 보낼 수 없는 알림입니다.']);
        }

        // 템플릿 변수명 => 코어 변수명. 값은 코어 쪽 이름으로 들어온다. 카탈로그 변수만
        // 먼저 걸러 내므로(MessageVars), var_map 이 가리킬 수 있는 이름도 카탈로그
        // 안쪽뿐이다 — 저장 시점 검증(save())과 읽기 시점 검증(templateFor())에 이어
        // 세 번째 방어선이다.
        $values = MessageVars::forBody($event, $vars);
        $mapped = [];
        // 비밀을 담은 변수의 **템플릿 쪽 이름**. 발송 요청에 실리는 변수 이름은 매핑을
        // 지난 뒤의 것이라, 코어 이름 그대로 넘기면 아무것도 가리지 못한다.
        $secret = [];
        foreach ($template['var_map'] as $templateName => $coreName) {
            // 저장된 매핑은 약속이 아니다. templateFor() 는 템플릿 본문에 실제로 쓰인
            // 변수만 검사하므로, 손으로 고친 값이나 옛 버전이 남긴 값이 그 밖의 칸에
            // 문자열 아닌 것을 들고 있을 수 있다 — 그대로 배열 첨자로 쓰면 TypeError 로
            // 터진다. 건너뛰면 그 변수는 빈 채로 남고, 그것이 진짜 템플릿 변수였다면
            // Variables::apply() 가 발송 전에 거절한다.
            if (!is_string($coreName)) {
                continue;
            }
            $mapped[(string) $templateName] = $values[$coreName] ?? '';
            if (in_array($coreName, Events::secretVars($event), true)) {
                $secret[] = (string) $templateName;
            }
        }

        $fallbackBody = $this->settings->smsBody($event);
        $failover = $fallbackBody !== '' && $this->aligo->settings->isEnabled('sms');

        $jobId = $this->aligo->send([
            'channel' => 'at',
            'tpl_code' => $template['tpl_code'],
            'failover' => $failover,
            'event_key' => $event,
            'secret_vars' => $secret,
            'recipients' => [['phone' => $to->phone, 'name' => $to->name,
                'user_id' => $to->userId, 'vars' => $mapped,
                'fallback_body' => $failover ? $fallbackBody : null,
                'fallback_vars' => $failover ? SmsLinks::forBody($event, $vars, $this->siteUrl) : [],
                'fallback_secret_vars' => $failover ? Events::secretVars($event) : []]],
        ]);
        // 작업 행이 생겼다는 것과 알리고가 그것을 받았다는 것은 다른 사실이다 —
        // 그 둘을 가르는 이유는 PhoneOutcome 주석에 있다.
        PhoneOutcome::assertAccepted($this->aligo, $this->key(), $jobId);
    }
}
