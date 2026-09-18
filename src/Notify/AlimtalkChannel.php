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

    public function __construct(AligoService $aligo, NotifySettings $settings)
    {
        $this->aligo = $aligo;
        $this->settings = $settings;
    }

    public function key(): string
    {
        return 'alimtalk';
    }

    public function available(string $event, Recipient $to): bool
    {
        return $to->phone !== null
            && Events::phoneCapable($event)
            && $this->settings->templateFor($event) !== null
            && $this->aligo->settings->isEnabled('at');
    }

    public function send(string $event, Recipient $to, array $vars): void
    {
        $template = $this->available($event, $to) ? $this->settings->templateFor($event) : null;
        if ($template === null) {
            throw DomainError::validation(['alimtalk' => '알림톡으로 보낼 수 없는 알림입니다.']);
        }

        // 템플릿 변수명 => 코어 변수명. 값은 코어 쪽 이름으로 들어온다. 카탈로그 변수만
        // 먼저 걸러 내므로(MessageVars), var_map 이 가리킬 수 있는 이름도 카탈로그
        // 안쪽뿐이다 — 저장 시점 검증(save())과 읽기 시점 검증(templateFor())에 이어
        // 세 번째 방어선이다.
        $values = MessageVars::forBody($event, $vars);
        $mapped = [];
        foreach ($template['var_map'] as $templateName => $coreName) {
            $mapped[(string) $templateName] = $values[$coreName] ?? '';
        }

        $this->aligo->send([
            'channel' => 'at',
            'tpl_code' => $template['tpl_code'],
            'event_key' => $event,
            'recipients' => [['phone' => $to->phone, 'name' => $to->name,
                'user_id' => $to->userId, 'vars' => $mapped]],
        ]);
    }
}
