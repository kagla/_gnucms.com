<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Aligo\Templates;
use GnuCms\Aligo\Variables;
use GnuCms\Error\DomainError;

/**
 * 이벤트마다 어느 채널(메일·알림톡·문자·인앱)을 켤지, 알림톡이면 승인 템플릿의 변수를
 * 코어 변수에 어떻게 이을지를 저장·검증한다. 발송 자체는 하지 않는다.
 *
 * **알 수 없는 이벤트 키.** 관리자가 저장한 값은 업그레이드로 카탈로그(Events::ALL)에서
 * 빠진 이벤트를 계속 가리킬 수 있다 — 저장소에는 그 값이 그대로 남아 있는데 코드는 더는
 * 그 이벤트를 모르는 상태다. 이 클래스는 그런 이벤트를 "아무것도 설정하지 않은 것"과
 * 완전히 같게 다룬다: channelsFor()·isOn()·smsBody() 는 빈 값을, templateFor() 는 null 을
 * 돌려주고, formValues() 의 묶음에도 나타나지 않는다(카탈로그를 훑어 만들기 때문에
 * 자연히 빠진다). save() 만 예외다 — 알 수 없는 이벤트를 새로 저장하려는 시도는 거절한다.
 * 저장소에 남은 낡은 값은 읽지 않을 뿐 지우지도 않는다: 지우는 것은 이 클래스의 책임이
 * 아니고, 같은 키가 나중에 카탈로그로 돌아오면(드문 일이지만) 그 값이 다시 뜻을 갖는다.
 *
 * **저장된 값은 약속이 아니다.** save() 가 알림톡 템플릿을 검증하는 것은 저장하는
 * 그 순간뿐이다. 그 뒤 Templates::fetch() 가(카카오 승인이 풀리거나 목록에서 사라져)
 * 조용히 그 템플릿을 disable 하거나 내용을 바꿀 수 있다 — 관리자가 아무것도 다시
 * 손대지 않아도 저장된 tpl_code 가 더는 쓸 수 없는 것을 가리키게 된다. 그래서
 * channelsFor()·isOn()·templateFor() 는 저장된 alimtalk 설정을 그대로 믿지 않고, 읽을
 * 때마다 Templates::find() 로 그 템플릿이 지금도 존재·enabled 이고 지금의 본문 변수를
 * 저장된 var_map 이 빠짐없이 덮는지 다시 확인한다(validTemplate()). 셋 중 하나라도
 * 이 재확인을 건너뛰면 나머지와 어긋난 답을 하게 되므로, 셋 다 같은 private 메서드
 * 하나로 판단을 모은다. 템플릿이 죽으면 channelsFor() 는 alimtalk 를 빼고,
 * templateFor() 는 null 을 돌려준다 — 관리자가 켰다고 저장한 값과 지금 실제로 쓸 수
 * 있는 값이 다를 수 있다는 뜻을 formValues() 의 alimtalk_tpl_code(원본, 검증 안 함)로
 * 화면에 남긴다.
 *
 * **채널이 꺼져 있으면 그 채널의 내용도 안 내준다.** save() 는 채널을 끌 때 그 채널의
 * tpl_code·var_map·sms_body 를 지우지 않는다 — 관리자가 알림톡·문자를 다시 켤 때 매핑과
 * 본문을 다시 만들지 않아도 되게 하려는 의도된 동작이다. 하지만 그래서 templateFor()·
 * smsBody() 를 channelsFor()/isOn() 과 따로 물으면 "꺼진 채널의 멀쩡한 설정"이 나올 수
 * 있다 — 부르는 쪽이 isOn() 을 먼저 확인하지 않으면 꺼진 채널을 켜진 것처럼 믿게 된다.
 * 그래서 templateFor() 는 isOn($event,'alimtalk') 을, smsBody() 는 isOn($event,'sms') 를
 * 먼저 확인하고, 꺼져 있으면 저장된 값이 무엇이든 null·''을 돌려준다. 저장소의 값은
 * 그대로 남아 있으므로 다시 켜면 바로 돌아온다.
 */
final class NotifySettings
{
    public const CHANNELS = ['mail', 'alimtalk', 'sms', 'inbox'];

    /** 전화 채널(알림톡·문자)에 속한 채널 키. Events::phoneCapable() 이 거짓인 이벤트에는
     *  이 채널들을 절대 켤 수 없다 — save() 는 거절하고, channelsFor() 는 저장소에 무엇이
     *  남아 있든 걸러낸다. */
    private const PHONE_CHANNELS = ['alimtalk', 'sms'];

    /** 설정하기 전의 동작. 지금 코어가 하는 일을 그대로 둔다. */
    private const DEFAULTS = [
        'password_reset' => ['mail'], 'password_changed' => ['mail'], 'welcome' => [],
        'comment_new' => ['inbox'], 'email_verify' => ['mail'],
        'signup_attempt' => ['mail'], 'social_email_verify' => ['mail'],
    ];

    private SettingsRepository $repository;
    private Templates $templates;

    public function __construct(SettingsRepository $repository, Templates $templates)
    {
        $this->repository = $repository;
        $this->templates = $templates;
    }

    /** @return list<string> */
    public function channelsFor(string $event): array
    {
        if (!Events::exists($event)) {
            return [];
        }

        $stored = $this->repository->all();
        $on = isset($stored[$event . '.configured'])
            ? array_filter(self::CHANNELS,
                fn (string $channel): bool => ($stored[$event . '.' . $channel] ?? '0') === '1')
            : (self::DEFAULTS[$event] ?? []);

        // 저장소에 남은 값이 지금 이 이벤트가 쓸 수 없는 전화 채널을 가리켜도(수동 DB
        // 편집이나 검증을 우회한 과거 버전의 흔적일 수 있다) 여기서 한 번 더 걸러낸다.
        // save() 가 이미 막아 두었더라도, 읽는 쪽이 스스로를 지키는 편이 안전하다.
        if (!Events::phoneCapable($event)) {
            $on = array_diff($on, self::PHONE_CHANNELS);
        }
        if (in_array('alimtalk', $on, true) && $this->validTemplate($event, $stored) === null) {
            $on = array_diff($on, ['alimtalk']);
        }

        return array_values(array_intersect(self::CHANNELS, $on));
    }

    public function isOn(string $event, string $channel): bool
    {
        return in_array($channel, $this->channelsFor($event), true);
    }

    /**
     * 알림톡이 꺼져 있으면 매핑이 아무리 멀쩡해도 null 이다 — channelsFor()·isOn() 이
     * "꺼졌다"고 답하는데 이 메서드만 tpl_code 를 내주면, 부르는 쪽이 굳이 isOn() 을
     * 먼저 물어보지 않는 한 "쓸 수 있다"고 믿어 버린다. 저장된 매핑 자체는 save() 가
     * 지우지 않는다(다시 켤 때 다시 고르지 않아도 되게) — 여기서는 그 값을 안 내줄
     * 뿐이다.
     *
     * @return array{tpl_code:string,var_map:array}|null
     */
    public function templateFor(string $event): ?array
    {
        if (!Events::exists($event) || !$this->isOn($event, 'alimtalk')) {
            return null;
        }

        return $this->validTemplate($event, $this->repository->all());
    }

    /**
     * 저장된 tpl_code 가 지금도 실제로 보낼 수 있는 템플릿을 가리키는지 다시 확인한다.
     * find() 가 못 찾거나(삭제됨) enabled 가 아니거나(승인·정상을 잃음), 그 사이 알리고
     * 쪽에서 본문이 바뀌어 저장된 var_map 이 지금의 변수를 다 덮지 못하면 전부 "쓸 수
     * 없음"이다 — save() 가 저장할 때 검증한 것과 정확히 같은 기준을, 읽을 때 지금의
     * Templates 사본을 대상으로 다시 적용할 뿐이다.
     *
     * @return array{tpl_code:string,var_map:array}|null
     */
    private function validTemplate(string $event, array $stored): ?array
    {
        $code = (string) ($stored[$event . '.tpl_code'] ?? '');
        if ($code === '') {
            return null;
        }
        $template = $this->templates->find($code);
        if ($template === null || (int) $template['enabled'] !== 1) {
            return null;
        }

        $map = json_decode((string) ($stored[$event . '.var_map'] ?? '[]'), true);
        $map = is_array($map) ? $map : [];
        $allowed = Events::variables($event);
        foreach (Variables::names((string) $template['content']) as $name) {
            $core = $map[$name] ?? null;
            if (!is_string($core) || $core === '' || !in_array($core, $allowed, true)) {
                return null;
            }
        }

        return ['tpl_code' => $code, 'var_map' => $map];
    }

    /** 같은 이유로 문자도 꺼져 있으면 본문을 내주지 않는다 — 저장된 본문 자체는
     *  save() 가 지우지 않는다. */
    public function smsBody(string $event): string
    {
        if (!Events::exists($event) || !$this->isOn($event, 'sms')) {
            return '';
        }

        return (string) ($this->repository->all()[$event . '.sms_body'] ?? '');
    }

    public function save(string $event, array $input): void
    {
        if (!Events::exists($event)) {
            throw DomainError::validation(['event' => '알 수 없는 알림입니다.']);
        }
        $allowed = Events::variables($event);
        $saved = [$event . '.configured' => '1'];

        foreach (self::CHANNELS as $channel) {
            $on = ($input[$channel] ?? '') === '1';
            if ($on && in_array($channel, self::PHONE_CHANNELS, true) && !Events::phoneCapable($event)) {
                throw DomainError::validation([$channel =>
                    '이 알림은 이메일로만 보낼 수 있습니다. 받는 사람이 이메일로만 확인됩니다.']);
            }
            $saved[$event . '.' . $channel] = $on ? '1' : '0';
        }

        if ($saved[$event . '.sms'] === '1') {
            $body = $this->stringInput($input, 'sms_body');
            if ($body === '') {
                throw DomainError::validation(['sms_body' => '문자로 보낼 본문을 입력해 주세요.']);
            }
            $unknown = array_diff(Variables::names($body), $allowed);
            if ($unknown !== []) {
                throw DomainError::validation(['sms_body' =>
                    '이 알림이 제공하지 않는 변수가 있습니다: ' . implode(', ', $unknown)
                    . '. 쓸 수 있는 변수는 ' . implode(', ', $allowed) . ' 입니다.']);
            }
            $saved[$event . '.sms_body'] = $body;
        }

        if ($saved[$event . '.alimtalk'] === '1') {
            $saved += $this->alimtalkSettings($event, $input, $allowed);
        }

        $this->repository->save($saved);
    }

    /**
     * 승인 템플릿의 변수명은 사이트마다 다르다. 템플릿의 변수 하나하나가 이 이벤트가
     * 실제로 갖고 있는 코어 변수와 이어져야만 켤 수 있다 — 매핑이 아예 없는 변수도,
     * 존재하지 않는 코어 변수를 가리키는 매핑도 "아직 못 이었다"는 점에서 똑같다: 어느
     * 쪽이든 발송 시점에 그 변수는 채울 값이 없다.
     */
    private function alimtalkSettings(string $event, array $input, array $allowed): array
    {
        $code = $this->stringInput($input, 'tpl_code');
        $template = $code === '' ? null : $this->templates->find($code);
        if ($template === null || (int) $template['enabled'] !== 1) {
            throw DomainError::validation(['tpl_code' => '사용 중인 승인 템플릿을 골라 주세요.']);
        }

        $rawMap = is_array($input['var_map'] ?? null) ? $input['var_map'] : [];
        $map = [];
        $unmapped = [];
        foreach (Variables::names((string) $template['content']) as $name) {
            $core = is_scalar($rawMap[$name] ?? null) ? trim((string) $rawMap[$name]) : '';
            if ($core === '' || !in_array($core, $allowed, true)) {
                $unmapped[] = $name;
                continue;
            }
            $map[$name] = $core;
        }
        if ($unmapped !== []) {
            throw DomainError::validation(['var_map' =>
                '템플릿 변수에 넣을 값을 모두 골라 주세요. 남은 변수: ' . implode(', ', $unmapped)]);
        }

        return [
            $event . '.tpl_code' => $code,
            $event . '.var_map' => (string) json_encode($map, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** 스칼라가 아닌 입력(배열 등)은 문자열로 캐스팅하지 않고 빈 문자열로 다룬다 —
     *  캐스팅 경고 없이 "값 없음"으로 취급해 뒤이은 검증이 자연히 거절하게 한다. */
    private function stringInput(array $input, string $key): string
    {
        return is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    }

    public function formValues(): array
    {
        $stored = $this->repository->all();
        $values = [];
        foreach (Events::ALL as $key => $event) {
            $values[$key] = [
                'label' => $event['label'],
                'vars' => $event['vars'],
                'phone' => $event['phone'],
                'channels' => $this->channelsFor($key),
                'template' => $this->templateFor($key),
                'sms_body' => $this->smsBody($key),
                // 검증을 거치지 않은 원본 tpl_code. template 이 null 인데 이 값이 비어
                // 있지 않다면, 관리자가 골라 둔 템플릿이 그 사이 못 쓰게 된 것이다 —
                // 화면이 "알림톡이 꺼졌습니다"가 아니라 "고르신 템플릿(OOO)을 더는 쓸 수
                // 없습니다"라고 진짜 이유를 말할 수 있게 남겨 둔다.
                'alimtalk_tpl_code' => (string) ($stored[$key . '.tpl_code'] ?? ''),
            ];
        }

        return $values;
    }
}
