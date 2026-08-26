<?php

declare(strict_types=1);

namespace StandardBoard\Tests\Docs;

use PHPUnit\Framework\TestCase;

/**
 * 문서가 코드보다 먼저 낡는 것을 막는다.
 *
 * 개발 머신에 YAML 확장이 없을 수 있으므로 스펙을 정규식으로 훑는다. 완전한
 * 파서가 아니라 표류 감시용이다. 경로를 하나 추가하고 문서를 잊으면 여기서 걸린다.
 */
final class OpenApiTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../docs/openapi.yaml';
    private const ROUTES = __DIR__ . '/../../src/Routes.php';

    public function testEveryRouteIsDocumented(): void
    {
        $missing = array_diff($this->routes(), $this->documented());

        $this->assertSame([], array_values($missing), '문서에 빠진 경로: ' . implode(', ', $missing));
    }

    public function testEveryDocumentedPathExists(): void
    {
        $extra = array_diff($this->documented(), $this->routes());

        $this->assertSame([], array_values($extra), '코드에 없는데 문서에 있는 경로: ' . implode(', ', $extra));
    }

    public function testEveryOperationHasAnOperationId(): void
    {
        $spec = (string) file_get_contents(self::SPEC);
        $operations = preg_match_all('/^      tags: \[/m', $spec);
        $ids = preg_match_all('/^      operationId: /m', $spec);

        $this->assertSame($operations, $ids, '모든 오퍼레이션에 operationId 가 있어야 한다');
    }

    public function testErrorCodesMatchApiError(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Http/ApiError.php');
        preg_match_all("/new self\('([A-Z_]+)'/", $source, $matches);
        $codes = array_values(array_unique($matches[1]));

        $spec = (string) file_get_contents(self::SPEC);
        preg_match('/enum: \[(UNAUTHORIZED[^\]]*)\]/', $spec, $found);
        $documented = array_map('trim', explode(',', $found[1] ?? ''));

        sort($codes);
        sort($documented);
        $this->assertSame($codes, $documented);
    }

    /** @return string[] "METHOD /path" 목록 */
    private function routes(): array
    {
        $source = (string) file_get_contents(self::ROUTES);
        preg_match_all("/router->(get|post|patch|delete)\('([^']+)'/", $source, $matches, PREG_SET_ORDER);

        $routes = [];
        foreach ($matches as $match) {
            $routes[] = strtoupper($match[1]) . ' ' . $match[2];
        }
        sort($routes);

        return $routes;
    }

    /** @return string[] "METHOD /path" 목록 */
    private function documented(): array
    {
        $lines = explode("\n", (string) file_get_contents(self::SPEC));

        $documented = [];
        $path = null;
        $inPaths = false;
        foreach ($lines as $line) {
            if ($line === 'paths:') {
                $inPaths = true;
                continue;
            }
            if ($inPaths && $line !== '' && $line[0] !== ' ') {
                break; // paths 절이 끝났다
            }
            if (!$inPaths) {
                continue;
            }
            if (preg_match('/^  (\/\S*):$/', $line, $match) === 1) {
                $path = $match[1];
                continue;
            }
            if ($path !== null && preg_match('/^    (get|post|patch|delete):$/', $line, $match) === 1) {
                $documented[] = strtoupper($match[1]) . ' ' . $path;
            }
        }
        sort($documented);

        return $documented;
    }
}
