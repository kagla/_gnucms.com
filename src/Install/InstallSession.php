<?php

declare(strict_types=1);

namespace GnuCms\Install;

/**
 * 설치 단계 사이의 값과 "어디까지 끝냈나" 를 배열(보통 $_SESSION)에 둔다.
 * hidden 칸으로 DB 비밀번호를 실어 나르지 않기 위해서다.
 */
final class InstallSession
{
    public const LAST_STEP = 5;

    /** @var array<string, mixed> */
    private array $store;

    /** @param array<string, mixed> $store 참조로 받는다. install.php 는 $_SESSION 을 넘긴다 */
    public function __construct(array &$store)
    {
        $this->store = &$store;
        if (!isset($this->store['done'])) {
            $this->store['done'] = 0;
        }
    }

    /** 끝낸 마지막 단계 번호. 아무것도 안 했으면 0. */
    public function done(): int
    {
        return (int) $this->store['done'];
    }

    public function complete(int $step): void
    {
        $this->store['done'] = max($this->done(), $step);
    }

    /** 요청한 단계가 아직 열리지 않았으면 열린 마지막 단계로 낮춘다. */
    public function allowedStep(int $requested): int
    {
        return max(1, min($requested, $this->done() + 1, self::LAST_STEP));
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        return isset($this->store['data'][$key]) && is_array($this->store['data'][$key])
            ? $this->store['data'][$key]
            : null;
    }

    /** @param array<string, mixed> $data */
    public function set(string $key, array $data): void
    {
        $this->store['data'][$key] = $data;
    }

    public function reset(): void
    {
        $this->store = ['done' => 0];
    }
}
