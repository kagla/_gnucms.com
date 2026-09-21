<?php

declare(strict_types=1);

namespace GnuCms\Shop;

use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Error\DomainError;

final class Input
{
    public static function text(mixed $value, string $field, int $max, bool $optional = true): string
    {
        if ($value === null) $value = '';
        if (!is_string($value) && !is_int($value)) throw DomainError::validation([$field => '입력값을 확인해 주세요.']);
        $value = trim((string) $value);
        if (!$optional && $value === '') throw DomainError::validation([$field => '필수 항목입니다.']);
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) || !preg_match('//u', $value)) {
            throw DomainError::validation([$field => '사용할 수 없는 문자가 있습니다.']);
        }
        if (mb_strlen($value, 'UTF-8') > $max) throw DomainError::validation([$field => $max . '자 이내로 입력해 주세요.']);
        return $value;
    }

    public static function int(mixed $value, string $field, int $min, int $max, ?int $default = null): int
    {
        if ($value === null || $value === '') {
            if ($default === null) throw DomainError::validation([$field => '숫자를 입력해 주세요.']);
            return $default;
        }
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^-?(0|[1-9][0-9]{0,11})$/D', (string) $value)
            || (int) $value < $min || (int) $value > $max) {
            throw DomainError::validation([$field => $min . '~' . $max . ' 범위의 정수를 입력해 주세요.']);
        }
        return (int) $value;
    }

    public static function bool(mixed $value): int
    {
        return $value === '1' || $value === 1 || $value === true ? 1 : 0;
    }

    public static function id(mixed $value, string $field = 'id'): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,15}$/D', (string) $value)) {
            throw DomainError::notFound('항목을 찾을 수 없습니다.');
        }
        return (int) $value;
    }

    public static function optionalId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : self::id($value);
    }

    /**
     * 목록 필터나 폼의 첫 값처럼 "고르지 않음" 이 정상인 자리의 id. id 가 아닌 값은 못 찾았다고 하지 않고 그냥 고르지 않은 것으로 본다.
     * 29판 이전의 분류 코드는 1a·zz 같은 영문·숫자였다 — 그 값이 든 옛 북마크(?ca=1a)로 화면이 404 가 되면 안 된다.
     */
    public static function filterId(mixed $value): ?int
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[1-9][0-9]{0,15}$/D', (string) $value) ? (int) $value : null;
    }

    public static function code(mixed $value, string $field, string $pattern, string $message): string
    {
        if (!is_string($value) || !preg_match($pattern, $value)) throw DomainError::validation([$field => $message]);
        return $value;
    }

    public static function html(mixed $value, string $field, HtmlSanitizer $sanitizer, int $max = 60000): string
    {
        if ($value === null) $value = '';
        if (!is_string($value) || strlen($value) > $max) throw DomainError::validation([$field => '내용이 너무 깁니다.']);
        return $sanitizer->clean($value);
    }

    public static function plain(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? '';
        $text = html_entity_decode(preg_replace('/<[^>]*>/', ' ', $text) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public static function slug(string $name, string $fallback): string
    {
        $slug = preg_replace('~[\s/?#%"\'`<>\\\\]+~u', '-', trim($name)) ?? '';
        $slug = trim(preg_replace('/-{2,}/', '-', $slug) ?? '', '-');
        $slug = mb_substr($slug, 0, 190, 'UTF-8');
        return $slug === '' ? $fallback : $slug;
    }

    public static function extra(array $input): string
    {
        $labels = is_array($input['extra_label'] ?? null) ? $input['extra_label'] : [];
        $values = is_array($input['extra_value'] ?? null) ? $input['extra_value'] : [];
        $extra = [];
        for ($i = 1; $i <= 10; $i++) {
            $extra[] = ['label' => self::text($labels[$i] ?? '', 'extra_label', 100), 'value' => self::text($values[$i] ?? '', 'extra_value', 1000)];
        }
        return json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** 쉼표로 나눈 값. 공백 제거, 빈값·중복 제외, 순서 유지. */
    public static function csv(mixed $value, int $maxItems, int $maxLength, string $field): array
    {
        $items = [];
        foreach (explode(',', self::text($value, $field, $maxItems * ($maxLength + 1))) as $item) {
            $item = trim($item);
            if ($item === '' || in_array($item, $items, true)) continue;
            if (mb_strlen($item, 'UTF-8') > $maxLength || preg_match('/[<>"\']/', $item)) {
                throw DomainError::validation([$field => '값은 ' . $maxLength . '자 이내이며 <>"\' 문자를 쓸 수 없습니다.']);
            }
            $items[] = $item;
        }
        if (count($items) > $maxItems) throw DomainError::validation([$field => '값은 ' . $maxItems . '개까지 입력할 수 있습니다.']);
        return $items;
    }
}
