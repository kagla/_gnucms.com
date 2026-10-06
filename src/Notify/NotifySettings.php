<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Aligo\Templates;
use GnuCms\Aligo\Variables;
use GnuCms\Error\DomainError;

/** 알림별 메일·문자 선택에 공통 발송 방식을 적용하고 문구·템플릿 연결을 저장한다. */
final class NotifySettings
{
    public const CHANNELS = ['mail', 'alimtalk', 'sms', 'inbox'];

    // 발송기가 배선되지 않은 계정 서비스의 기존 이메일 기본 동작을 유지한다.
    private const DEFAULTS = [
        'password_reset' => ['mail'], 'password_changed' => ['mail', 'inbox'], 'welcome' => ['mail', 'inbox'],
        'comment_new' => ['mail', 'inbox'], 'email_verify' => ['mail'],
        'signup_attempt' => ['mail'], 'social_email_verify' => ['mail'],
        'order_pending' => ['inbox'], 'order_paid' => ['inbox'], 'order_cancelled' => ['inbox'],
        'order_refunded' => ['inbox'], 'inquiry_replied' => ['inbox'],
        'order_confirmed' => ['inbox'], 'order_shipped' => ['inbox'], 'order_completed' => ['inbox'],
    ];

    private SettingsRepository $repository;
    private Templates $templates;
    private \Closure $mailEnabled;
    private \Closure $phoneStatus;

    public function __construct(SettingsRepository $repository, Templates $templates,
        ?callable $mailEnabled = null, ?callable $phoneStatus = null)
    {
        $this->repository = $repository;
        $this->templates = $templates;
        $this->mailEnabled = $mailEnabled === null
            ? static fn (): bool => true : \Closure::fromCallable($mailEnabled);
        $this->phoneStatus = $phoneStatus === null
            ? static fn (): array => ['alimtalk_enabled' => false, 'sms_enabled' => false]
            : \Closure::fromCallable($phoneStatus);
    }

    public static function defaultChannels(string $event): array
    {
        return self::DEFAULTS[$event] ?? [];
    }

    /** 선택을 저장하기 전에는 현재 공통 설정을 따르던 동작을 유지한다. */
    private static function selection(string $event, array $stored): array
    {
        $configured = ($stored[$event . '.delivery_configured'] ?? '') === '1';
        return [
            'mail' => !$configured || ($stored[$event . '.delivery_mail'] ?? '0') === '1',
            'phone' => Events::phoneCapable($event)
                && (!$configured || ($stored[$event . '.delivery_phone'] ?? '0') === '1'),
        ];
    }

    /** 알림별 선택과 공통 사용 설정이 모두 허용하는 채널만 보낸다. */
    private function configured(string $event): array
    {
        if (!Events::exists($event)) return [];
        $selection = self::selection($event, $this->repository->all());
        $on = [];
        if ($selection['mail'] && ($this->mailEnabled)()) $on[] = 'mail';
        if ($selection['phone']) {
            $status = ($this->phoneStatus)();
            if ($status['alimtalk_enabled'] ?? false) $on[] = 'alimtalk';
            if ($status['sms_enabled'] ?? false) $on[] = 'sms';
        }
        if (Events::inboxCapable($event)) $on[] = 'inbox';
        return $on;
    }

    /** 기존 발송기의 인터페이스: 템플릿 유효성을 검사하기 전 공통 설정 채널. */
    public function storedChannelsFor(string $event): array
    {
        return $this->configured($event);
    }

    public function channelsFor(string $event): array
    {
        $on = $this->configured($event);
        if (in_array('alimtalk', $on, true)
            && $this->validTemplate($event, $this->repository->all()) === null) {
            $on = array_values(array_diff($on, ['alimtalk']));
        }
        return $on;
    }

    public function isOn(string $event, string $channel): bool
    {
        return in_array($channel, $this->channelsFor($event), true);
    }

    public function mailEnabled(): bool
    {
        return ($this->mailEnabled)();
    }

    public function mailTemplate(string $event): array
    {
        return MailEditor::template($event, $this->repository->all());
    }

    public function templateFor(string $event): ?array
    {
        return $this->isOn($event, 'alimtalk')
            ? $this->validTemplate($event, $this->repository->all()) : null;
    }

    /** 같은 이름은 자동 연결하고, 기존에 사용하던 회사명·고객명도 이어 준다. */
    public static function variableMap(string $event, array $names, array $overrides = []): array
    {
        $allowed = Events::variables($event);
        $aliases = ['고객명' => '이름', '회원명' => '이름', '회사명' => '사이트명', '서비스명' => '사이트명'];
        $map = [];
        foreach ($names as $name) {
            $override = is_string($overrides[$name] ?? null) ? trim($overrides[$name]) : '';
            $core = $override !== '' ? $override : (in_array($name, $allowed, true) ? $name : ($aliases[$name] ?? ''));
            if (in_array($core, $allowed, true)) $map[$name] = $core;
        }
        return $map;
    }

    private function validTemplate(string $event, array $stored): ?array
    {
        if (!Events::phoneCapable($event)) return null;
        $code = (string) ($stored[$event . '.tpl_code'] ?? '');
        $template = $code === '' ? null : $this->templates->find($code);
        if ($template === null || !$this->templates->canUse($template)) return null;
        $names = Variables::names((string) $template['content']);
        $map = self::variableMap($event, $names, self::storedMap($stored, $event));
        if (array_diff($names, array_keys($map)) !== []) return null;
        return ['tpl_code' => $code, 'var_map' => $map];
    }

    /** 문자를 따로 편집하지 않아도 기본 문구로 발송한다. 빈 저장 문구도 기본값을 뜻한다. */
    public function smsBody(string $event): string
    {
        if (!$this->isOn($event, 'sms')) return '';
        $body = (string) ($this->repository->all()[$event . '.sms_body'] ?? '');
        return $body !== '' ? $body : Events::defaultSmsBody($event);
    }

    public function smsTitle(string $event): string
    {
        return $this->isOn($event, 'sms')
            ? (string) ($this->repository->all()[$event . '.sms_title'] ?? '') : '';
    }

    public function save(string $event, array $input): void
    {
        if (!Events::exists($event)) throw DomainError::validation(['event' => '알 수 없는 알림입니다.']);
        $stored = $this->repository->all();
        $saved = [];
        // 새 선택 폼의 체크 해제와 이전 문구 편집 요청의 항목 누락을 구별한다.
        if (($input['delivery_choice'] ?? '') === '1') {
            $phone = ($input['phone'] ?? '') === '1';
            if ($phone && !Events::phoneCapable($event)) {
                throw DomainError::validation(['phone' => '이 알림은 이메일 전용이라 문자를 선택할 수 없습니다.']);
            }
            $saved[$event . '.delivery_configured'] = '1';
            $saved[$event . '.delivery_mail'] = ($input['mail'] ?? '') === '1' ? '1' : '0';
            $saved[$event . '.delivery_phone'] = $phone ? '1' : '0';
        }
        if (array_key_exists('sms_body', $input) || array_key_exists('sms_title', $input)) {
            if (!Events::phoneCapable($event)) {
                throw DomainError::validation(['sms_body' => '이 알림은 이메일로만 보낼 수 있습니다.']);
            }
            $body = array_key_exists('sms_body', $input) ? $this->stringInput($input, 'sms_body')
                : (string) ($stored[$event . '.sms_body'] ?? '');
            $title = array_key_exists('sms_title', $input) ? $this->stringInput($input, 'sms_title')
                : (string) ($stored[$event . '.sms_title'] ?? '');
            SmsEditor::validate($event, $body !== '' ? $body : Events::defaultSmsBody($event), $title);
            if (array_key_exists('sms_body', $input)) $saved[$event . '.sms_body'] = $body;
            if (array_key_exists('sms_title', $input)) $saved[$event . '.sms_title'] = $title;
        }

        $code = $this->stringInput($input, 'tpl_code');
        if ($code !== '') {
            if (!Events::phoneCapable($event)) {
                throw DomainError::validation(['tpl_code' => '이 알림은 알림톡으로 보낼 수 없습니다.']);
            }
            $template = $this->templates->find($code);
            if ($template === null || !$this->templates->canUse($template)) {
                throw DomainError::validation(['tpl_code' => '현재 채널의 승인된 템플릿을 골라 주세요.']);
            }
            $rawMap = array_key_exists('var_map', $input)
                ? (is_array($input['var_map']) ? $input['var_map'] : [])
                : (((string) ($stored[$event . '.tpl_code'] ?? '')) === $code ? self::storedMap($stored, $event) : []);
            $names = Variables::names((string) $template['content']);
            $map = self::variableMap($event, $names, $rawMap);
            $missing = array_diff($names, array_keys($map));
            if ($missing !== []) {
                throw DomainError::validation(['var_map' => '자동으로 연결할 수 없는 변수입니다. 변수 연결에서 값을 골라 주세요: ' . implode(', ', $missing)]);
            }
            $saved[$event . '.tpl_code'] = $code;
            $saved[$event . '.var_map'] = (string) json_encode($map, JSON_UNESCAPED_UNICODE);
        } elseif (($input['tpl_clear'] ?? '') === '1') {
            if ($this->validTemplate($event, $stored) !== null) {
                throw DomainError::validation(['tpl_clear' => '이 템플릿을 지금은 다시 쓸 수 있습니다. 화면을 새로 고쳐 확인해 주세요.']);
            }
            $saved[$event . '.tpl_code'] = '';
            $saved[$event . '.var_map'] = '[]';
        }
        // 미연결·승인 대기 중에도 이메일과 문자 문구는 저장할 수 있다.
        // 빈 템플릿 선택은 기존 연결을 보존하며, 죽은 연결만 명시적으로 지운다.
        if (array_key_exists('mail_subject', $input) || array_key_exists('mail_body', $input)) {
            $mail = MailEditor::input($event, $input);
            $defaults = MailBodies::defaults($event);
            foreach (['subject', 'body'] as $field) {
                $saved[$event . '.mail_' . $field] = $mail[$field] === $defaults[$field] ? '' : $mail[$field];
            }
        }
        $this->repository->save($saved);
    }

    private function stringInput(array $input, string $key): string
    {
        return is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    }

    private static function storedMap(array $stored, string $event): array
    {
        $map = json_decode((string) ($stored[$event . '.var_map'] ?? '[]'), true);
        return is_array($map) ? array_filter($map, static fn (mixed $core): bool => is_string($core)) : [];
    }

    public function formValues(): array
    {
        $stored = $this->repository->all();
        $values = [];
        foreach (Events::ALL as $key => $event) {
            $configured = $this->configured($key);
            $code = (string) ($stored[$key . '.tpl_code'] ?? '');
            $template = $code === '' ? null : $this->templates->find($code);
            $map = self::storedMap($stored, $key);
            if ($template !== null) $map = self::variableMap($key, Variables::names((string) $template['content']), $map);
            $values[$key] = [
                'label' => $event['label'], 'vars' => $event['vars'], 'phone' => $event['phone'], 'inbox' => $event['inbox'],
                'selection' => self::selection($key, $stored),
                'channels' => $this->channelsFor($key), 'mail_on' => in_array('mail', $configured, true),
                'template' => $this->templateFor($key), 'sms_body' => $this->smsBody($key),
                'alimtalk_tpl_code' => $code, 'alimtalk_template_usable' => $this->validTemplate($key, $stored) !== null,
                'alimtalk_on' => in_array('alimtalk', $configured, true), 'alimtalk_var_map' => $map,
                'sms_body_stored' => (string) ($stored[$key . '.sms_body'] ?? ''),
                'sms_title_stored' => (string) ($stored[$key . '.sms_title'] ?? ''),
                'mail_template' => MailEditor::template($key, $stored),
            ];
        }
        return $values;
    }
}
