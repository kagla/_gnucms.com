<?php

declare(strict_types=1);

namespace StandardBoard\Tests\Http;

use PHPUnit\Framework\TestCase;
use StandardBoard\Http\ApiError;

final class ApiErrorTest extends TestCase
{
    public function testFactoriesCarryCodeAndStatus(): void
    {
        $this->assertSame(401, ApiError::unauthorized('로그인이 필요합니다.')->status());
        $this->assertSame('UNAUTHORIZED', ApiError::unauthorized('x')->code());
        $this->assertSame(403, ApiError::forbidden('x')->status());
        $this->assertSame(404, ApiError::notFound('x')->status());
        $this->assertSame(413, ApiError::tooLarge('x')->status());
        $this->assertSame(500, ApiError::internal('x')->status());
    }

    public function testValidationCarriesFieldDetails(): void
    {
        $error = ApiError::validation(['title' => '필수 항목입니다.']);

        $this->assertSame('VALIDATION_FAILED', $error->code());
        $this->assertSame(422, $error->status());
        $this->assertSame(['title' => '필수 항목입니다.'], $error->details());
    }
}
