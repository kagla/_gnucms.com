<?php

declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Aligo\Variables;
use GnuCms\Error\DomainError;

/** 일반 텍스트 이메일 편집·치환. 미리보기와 실제 발송이 같은 문구를 사용한다. */
final class MailEditor
{
    public const SUBJECT_LIMIT = 200;
    public const BODY_LIMIT = 20000;
    private const TOKEN_EVENTS = ['password_reset', 'email_verify', 'social_email_verify'];

    public static function input(string $event, array $input): array
    {
        foreach (['mail_subject', 'mail_body'] as $field) {
            if (!is_string($input[$field] ?? null)) {
                throw DomainError::validation([$field => '이메일 제목과 본문을 문자열로 입력해 주세요.']);
            }
        }
        $template = ['subject' => trim($input['mail_subject']),
            'body' => trim(str_replace(["\r\n", "\r"], "\n", $input['mail_body']))];
        self::validate($event, $template);
        return $template;
    }

    /** 저장하지 않은 문구는 기존 코어 문구를 쓴다. 꺼진 채널의 편집 내용도 보존한다. */
    public static function template(string $event, array $stored): array
    {
        $default = MailBodies::defaults($event);
        $template = [];
        foreach (['subject', 'body'] as $field) {
            $value = $stored[$event . '.mail_' . $field] ?? '';
            if ($event === 'comment_new' && $field === 'body' && is_string($value)
                && trim(str_replace(["\r\n", "\r"], "\n", $value)) === MailBodies::LEGACY_COMMENT_BODY) {
                $value = '';
            }
            $template[$field] = is_string($value) && $value !== '' ? $value : $default[$field];
        }
        self::validate($event, $template);
        return $template;
    }

    public static function validate(string $event, array $template): void
    {
        if (!Events::exists($event) || !MailBodies::has($event)) {
            throw DomainError::validation(['event' => '이메일을 편집할 수 없는 알림입니다.']);
        }
        foreach (['subject' => self::SUBJECT_LIMIT, 'body' => self::BODY_LIMIT] as $field => $limit) {
            $value = $template[$field] ?? null;
            $key = 'mail_' . $field;
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $limit) {
                throw DomainError::validation([$key => '내용을 입력해 주세요. 최대 ' . number_format($limit) . '자입니다.']);
            }
            $controls = $field === 'subject' ? '/[\x00-\x1F\x7F]/u' : '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';
            if (preg_match($controls, $value)) {
                throw DomainError::validation([$key => $field === 'subject'
                    ? '이메일 제목에는 줄바꿈이나 제어 문자를 넣을 수 없습니다.'
                    : '본문에 사용할 수 없는 제어 문자가 있습니다.']);
            }
            $withoutMarkers = preg_replace('/#\{([^}\r\n]{1,50})\}/u', '', $value);
            if (preg_match('/#\{\s*\}/u', $value) || str_contains((string) $withoutMarkers, '#{')) {
                throw DomainError::validation([$key => '변수는 #{이름}처럼 제공되는 이름과 닫는 괄호를 포함해 주세요.']);
            }
            $unknown = array_diff(Variables::names($value), Events::variables($event));
            if ($unknown !== []) {
                throw DomainError::validation([$key => '이 알림이 제공하지 않는 변수: ' . implode(', ', $unknown)]);
            }
        }
        if (in_array($event, self::TOKEN_EVENTS, true)) {
            foreach (['링크', '유효시간'] as $required) {
                if (!in_array($required, Variables::names($template['body']), true)) {
                    throw DomainError::validation(['mail_body' => '인증·비밀번호 재설정 본문에는 #{링크}와 #{유효시간}이 필요합니다.']);
                }
            }
        }
    }

    public static function render(string $event, array $vars, array $template): array
    {
        self::validate($event, $template);
        $values = MessageVars::forBody($event, $vars) + array_fill_keys(Events::variables($event), '');
        if (in_array($event, self::TOKEN_EVENTS, true)
            && (trim($values['링크']) === '' || trim($values['유효시간']) === '')) {
            throw DomainError::validation(['mail_preview' => '인증 링크와 유효시간 값이 필요합니다.']);
        }
        foreach ($template as $field => &$value) {
            $value = (string) preg_replace_callback('/#\{([^}\r\n]{1,50})\}/u',
                static fn (array $match): string => $values[trim($match[1])] ?? '', $value);
        }
        unset($value);
        // 회원명 등 실제 치환 값도 메일 제목의 헤더에 줄바꿈을 넣을 수 없다.
        $template['subject'] = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $template['subject']);
        return $template;
    }

    public static function preview(string $event, array $input): array
    {
        $template = self::input($event, $input);
        $samples = SmsEditor::samples($event);
        $posted = is_array($input['mail_samples'] ?? null) ? $input['mail_samples'] : [];
        foreach ($samples as $name => &$value) {
            if (array_key_exists($name, $posted)) {
                if (!is_string($posted[$name]) || mb_strlen($posted[$name]) > 1000) {
                    throw DomainError::validation(['mail_preview' => '예시 변수는 1,000자 이하 문자열로 입력해 주세요.']);
                }
                $value = trim($posted[$name]);
            }
        }
        unset($value);
        $mail = self::render($event, $samples, $template);
        if (Events::subscriptionMail($event)) {
            $mail['body'] .= MailPreferences::footerText('https://example.com/notifications/email/unsubscribe?token=preview-only');
        }
        return $mail;
    }
}
