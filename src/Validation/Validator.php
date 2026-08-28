<?php

declare(strict_types=1);

namespace GnuCms\Validation;

use GnuCms\Error\DomainError;

/**
 * 오류를 모았다가 check() 에서 한 번에 던진다. 필드 하나 고칠 때마다
 * 왕복하게 만들지 않기 위해서다.
 */
final class Validator
{
    public const PASSWORD_MIN = 4;

    /** @var array */
    private $data;

    /** @var array<string, string> */
    private $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function requiredString(string $field, int $max = 0): string
    {
        $raw = $this->data[$field] ?? '';
        if (!is_scalar($raw)) {
            // ?title[]=x 처럼 배열로 오면 (string) 캐스팅이 경고를 낸다. 문자열이
            // 아니라는 것 자체가 이미 검증 실패다.
            $this->errors[$field] = '문자열이어야 합니다.';

            return '';
        }

        $value = trim((string) $raw);

        if ($value === '') {
            $this->errors[$field] = '필수 항목입니다.';

            return '';
        }
        if ($max > 0 && mb_strlen($value) > $max) {
            $this->errors[$field] = $max . '자를 넘을 수 없습니다.';

            return '';
        }

        return $value;
    }

    public function optionalString(string $field, int $max = 0, ?string $default = null): ?string
    {
        if (!array_key_exists($field, $this->data)) {
            return $default;
        }

        $raw = $this->data[$field];
        if (!is_scalar($raw)) {
            // requiredString() 과 같은 이유. ?q[]=x 같은 배열은 검증 실패로 처리한다.
            $this->errors[$field] = '문자열이어야 합니다.';

            return $default;
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return $default;
        }
        if ($max > 0 && mb_strlen($value) > $max) {
            $this->errors[$field] = $max . '자를 넘을 수 없습니다.';

            return $default;
        }

        return $value;
    }

    public function requiredPassword(string $field): string
    {
        $raw = $this->data[$field] ?? '';
        if (!is_scalar($raw)) {
            // requiredString() 과 같은 이유. 배열은 검증 실패로 처리한다.
            $this->errors[$field] = '필수 항목입니다.';

            return '';
        }

        $value = (string) $raw;

        if ($value === '') {
            $this->errors[$field] = '필수 항목입니다.';

            return '';
        }
        if (mb_strlen($value) < self::PASSWORD_MIN) {
            $this->errors[$field] = self::PASSWORD_MIN . '자 이상이어야 합니다.';

            return '';
        }

        return $value;
    }

    public function optionalPassword(string $field): ?string
    {
        $raw = $this->data[$field] ?? '';
        if (!is_scalar($raw)) {
            // requiredPassword() 와 같은 이유. 배열은 값이 없는 것으로 취급한다.
            return null;
        }

        $value = (string) $raw;

        return $value === '' ? null : $value;
    }

    public function bool(string $field, bool $default): bool
    {
        if (!array_key_exists($field, $this->data)) {
            return $default;
        }

        $value = $this->data[$field];
        if (is_bool($value)) {
            return $value;
        }
        if (!is_scalar($value)) {
            // ?include_deleted[]=x 처럼 배열로 오면 (string) 캐스팅이 경고를 낸다.
            // bool() 은 원래도 인식 못 하는 값이면 truthy 목록에 없으므로 false 로
            // 떨어진다(예: "nonsense" -> false, $default 와 무관). 배열도 같은 방식이다.
            return false;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $field, int $default, int $min, int $max): int
    {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === '') {
            return $default;
        }

        $value = (int) $this->data[$field];

        return max($min, min($max, $value));
    }

    public function inList(string $field, array $allowed, string $default): string
    {
        if (!array_key_exists($field, $this->data)) {
            return $default;
        }

        $raw = $this->data[$field];
        if (!is_scalar($raw)) {
            // requiredString() 과 같은 이유. 배열은 허용 목록에 없으므로 검증 실패다.
            $this->errors[$field] = implode(', ', $allowed) . ' 중 하나여야 합니다.';

            return $default;
        }

        $value = (string) $raw;
        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = implode(', ', $allowed) . ' 중 하나여야 합니다.';

            return $default;
        }

        return $value;
    }

    public function fail(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function check(): void
    {
        if ($this->errors !== []) {
            throw DomainError::validation($this->errors);
        }
    }
}
