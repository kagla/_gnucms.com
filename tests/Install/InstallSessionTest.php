<?php

declare(strict_types=1);

namespace GnuCms\Tests\Install;

use GnuCms\Install\InstallSession;
use PHPUnit\Framework\TestCase;

final class InstallSessionTest extends TestCase
{
    public function testFreshSessionOnlyOpensStepOne(): void
    {
        $store = [];
        $s = new InstallSession($store);

        self::assertSame(0, $s->done());
        self::assertSame(1, $s->allowedStep(1));
        self::assertSame(1, $s->allowedStep(4));
        self::assertSame(1, $s->allowedStep(0));
    }

    public function testCompletingOpensTheNextStepOnly(): void
    {
        $store = [];
        $s = new InstallSession($store);
        $s->complete(1);
        $s->complete(2);

        self::assertSame(2, $s->done());
        self::assertSame(3, $s->allowedStep(3));
        self::assertSame(3, $s->allowedStep(5));
        self::assertSame(2, $s->allowedStep(2));
    }

    public function testCompletingLowerStepDoesNotRewind(): void
    {
        $store = [];
        $s = new InstallSession($store);
        $s->complete(3);
        $s->complete(1);

        self::assertSame(3, $s->done());
    }

    public function testAllowedStepNeverExceedsLast(): void
    {
        $store = [];
        $s = new InstallSession($store);
        $s->complete(5);

        self::assertSame(5, $s->allowedStep(9));
    }

    public function testValuesLiveInTheGivenArray(): void
    {
        $store = [];
        $s = new InstallSession($store);
        $s->set('db', ['dsn' => 'sqlite::memory:']);

        self::assertSame(['dsn' => 'sqlite::memory:'], $s->get('db'));
        self::assertNull($s->get('site'));
        self::assertSame(['dsn' => 'sqlite::memory:'], $store['data']['db']);

        $again = new InstallSession($store);
        self::assertSame(['dsn' => 'sqlite::memory:'], $again->get('db'));
    }

    public function testResetClearsEverything(): void
    {
        $store = [];
        $s = new InstallSession($store);
        $s->complete(4);
        $s->set('db', ['dsn' => 'x']);

        $s->reset();

        self::assertSame(0, $s->done());
        self::assertNull($s->get('db'));
        self::assertSame(['done' => 0], $store);
    }
}
