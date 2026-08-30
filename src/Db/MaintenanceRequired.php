<?php

declare(strict_types=1);

namespace GnuCms\Db;

use RuntimeException;
use Throwable;

/**
 * 스키마를 새 판으로 옮기지 못해 요청을 처리할 수 없을 때 던진다.
 * Slim 바깥(Kernel::create)에서 나므로 public/index.php 가 잡아 점검 화면을 낸다.
 */
final class MaintenanceRequired extends RuntimeException
{
    public const BUSY = 'busy';
    public const FAILED = 'failed';

    private string $kind;
    private ?string $backup;

    public function __construct(string $kind, ?string $backup = null, ?Throwable $previous = null)
    {
        parent::__construct(
            $kind === self::BUSY
                ? '데이터 구조를 새 판으로 옮기는 중입니다.'
                : '데이터 구조를 새 판으로 옮기지 못했습니다.',
            0,
            $previous
        );
        $this->kind = $kind;
        $this->backup = $backup;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /** 옮기기 전에 만든 백업 파일 경로. 없으면 null. */
    public function backup(): ?string
    {
        return $this->backup;
    }
}
