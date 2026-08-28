<?php

declare(strict_types=1);

namespace GnuCms\Tests\Error;

use GnuCms\Error\DomainError;
use PHPUnit\Framework\TestCase;

final class DomainErrorTest extends TestCase
{
    public function testFactoriesCarryStatusAndCode(): void
    {
        self::assertSame(401, DomainError::unauthorized('로그인이 필요합니다.')->status());
        self::assertSame('UNAUTHORIZED', DomainError::unauthorized('x')->code());
        self::assertSame(403, DomainError::forbidden('x')->status());
        self::assertSame(404, DomainError::notFound('x')->status());
        self::assertSame(413, DomainError::tooLarge('x')->status());
        self::assertSame(500, DomainError::internal('x')->status());
    }

    public function testValidationCarriesDetails(): void
    {
        $error = DomainError::validation(['title' => '필수입니다.']);

        self::assertSame(422, $error->status());
        self::assertSame('VALIDATION_FAILED', $error->code());
        self::assertSame(['title' => '필수입니다.'], $error->details());
    }

    public function testMessageSurvives(): void
    {
        self::assertSame('없습니다.', DomainError::notFound('없습니다.')->getMessage());
    }
}
