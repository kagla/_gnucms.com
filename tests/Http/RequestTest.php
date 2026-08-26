<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Http;

use PHPUnit\Framework\TestCase;
use ApiBoard\Http\Cors;
use ApiBoard\Http\Request;

final class RequestTest extends TestCase
{
    public function testBearerTokenIsExtracted(): void
    {
        $request = new Request('GET', '/', [], [], ['Authorization' => 'Bearer abc.def.ghi'], []);

        $this->assertSame('abc.def.ghi', $request->bearerToken());
    }

    public function testBearerPrefixIsCaseInsensitive(): void
    {
        $request = new Request('GET', '/', [], [], ['Authorization' => 'bearer abc'], []);

        $this->assertSame('abc', $request->bearerToken());
    }

    public function testMissingAuthorizationGivesNull(): void
    {
        $this->assertNull((new Request('GET', '/', [], [], [], []))->bearerToken());
    }

    public function testNonBearerSchemeGivesNull(): void
    {
        $request = new Request('GET', '/', [], [], ['Authorization' => 'Basic xyz'], []);

        $this->assertNull($request->bearerToken());
    }

    public function testInputPrefersBodyOverQuery(): void
    {
        $request = new Request('POST', '/', ['a' => 'query'], ['a' => 'body'], [], []);

        $this->assertSame('body', $request->input('a'));
    }

    public function testInputFallsBackToQuery(): void
    {
        $request = new Request('POST', '/', ['a' => 'query'], [], [], []);

        $this->assertSame('query', $request->input('a'));
    }

    public function testCorsAllowsListedOriginOnly(): void
    {
        $allowed = ['https://app.example.com'];

        $headers = Cors::headersFor('https://app.example.com', $allowed);
        $this->assertSame('https://app.example.com', $headers['Access-Control-Allow-Origin']);
        $this->assertSame('Origin', $headers['Vary']);

        $this->assertSame([], Cors::headersFor('https://evil.example.com', $allowed));
        $this->assertSame([], Cors::headersFor(null, $allowed));
    }

    public function testCorsWildcardIsNeverEmitted(): void
    {
        $headers = Cors::headersFor('https://a.example.com', ['*']);

        $this->assertSame([], $headers);
    }
}
