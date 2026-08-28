<?php

declare(strict_types=1);

namespace GnuCms\Error;

use RuntimeException;

/**
 * 사용자에게 그대로 보여줄 수 있는 오류. 이 예외가 아닌 모든 예외는
 * 프론트 컨트롤러에서 INTERNAL 로 변환되고 원문은 로그에만 남는다.
 *
 * 도메인 계층이 던지므로 HTTP 네임스페이스에 두지 않는다. status() 가
 * HTTP 상태 코드인 것은 이 예외를 화면으로 옮기는 층의 편의를 위한 것이고,
 * 도메인은 이 값을 읽지 않는다.
 */
final class DomainError extends RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $status;

    /** @var array */
    private $details;

    public function __construct(string $code, string $message, int $status, array $details = [])
    {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->status = $status;
        $this->details = $details;
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): array
    {
        return $this->details;
    }

    public static function unauthorized(string $message): self
    {
        return new self('UNAUTHORIZED', $message, 401);
    }

    public static function forbidden(string $message): self
    {
        return new self('FORBIDDEN', $message, 403);
    }

    public static function notFound(string $message): self
    {
        return new self('NOT_FOUND', $message, 404);
    }

    public static function validation(array $details): self
    {
        return new self('VALIDATION_FAILED', '입력값을 확인해 주세요.', 422, $details);
    }

    public static function tooLarge(string $message): self
    {
        return new self('PAYLOAD_TOO_LARGE', $message, 413);
    }

    public static function internal(string $message): self
    {
        return new self('INTERNAL', $message, 500);
    }
}
