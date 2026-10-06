<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Commerce\ReturnReason;
use PHPUnit\Framework\TestCase;

final class ReturnReasonTest extends TestCase
{
    public function testKnownReasonNeedsNoAdditionalData(): void
    {
        self::assertSame('반품 사유: 상품 불량', ReturnReason::note(['return_reason' => 'defective']));
    }

    public function testOtherNeedsDetails(): void
    {
        $this->expectException(DomainError::class);
        ReturnReason::note(['return_reason' => 'other']);
    }

    public function testUnknownReasonIsRejected(): void
    {
        $this->expectException(DomainError::class);
        ReturnReason::note(['return_reason' => 'unknown']);
    }
}
