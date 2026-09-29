<?php

declare(strict_types=1);

namespace GnuCms\Tests\Support;

use GnuCms\Aligo\Transport;
use GnuCms\Aligo\TransportFailure;

final class FakeAligoTransport implements Transport
{
    /** @var list<array{url:string,fields:array}> */
    public array $requests = [];
    /** @var list<array{status:int,body:string}|string> */
    private array $responses = [];

    public function queue(int $status, string $body): void
    {
        $this->responses[] = ['status' => $status, 'body' => $body];
    }

    /** 네트워크 실패를 흉내 낸다. */
    public function queueFailure(): void
    {
        $this->responses[] = 'fail';
    }

    public function post(string $url, array $fields, int $timeout = 10): array
    {
        $this->requests[] = ['url' => $url, 'fields' => $fields];
        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \LogicException('준비된 응답이 없습니다: ' . $url);
        }
        if ($next === 'fail') {
            throw new TransportFailure('테스트용 연결 실패');
        }

        return $next;
    }
}
