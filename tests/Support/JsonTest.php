<?php

declare(strict_types=1);

namespace StandardBoard\Tests\Support;

use PHPUnit\Framework\TestCase;
use StandardBoard\Http\ApiError;
use StandardBoard\Support\Json;

final class JsonTest extends TestCase
{
    public function testEncodeKeepsKoreanUnescaped(): void
    {
        $this->assertSame('{"name":"홍길동"}', Json::encode(['name' => '홍길동']));
    }

    public function testEncodeKeepsSlashesUnescaped(): void
    {
        $this->assertSame('{"url":"/posts/1"}', Json::encode(['url' => '/posts/1']));
    }

    public function testDecodeReturnsArray(): void
    {
        $this->assertSame(['a' => 1], Json::decode('{"a":1}'));
    }

    public function testDecodeEmptyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], Json::decode(''));
    }

    public function testDecodeInvalidJsonThrows(): void
    {
        $this->expectException(ApiError::class);
        Json::decode('{not json');
    }
}
