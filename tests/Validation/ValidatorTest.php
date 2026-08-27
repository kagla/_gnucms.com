<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Validation;

use PHPUnit\Framework\TestCase;
use ApiBoard\Error\DomainError;
use ApiBoard\Validation\Validator;

final class ValidatorTest extends TestCase
{
    public function testRequiredStringPasses(): void
    {
        $v = new Validator(['title' => '  제목  ']);

        $this->assertSame('제목', $v->requiredString('title', 200));
        $v->check();
    }

    public function testMissingRequiredFieldCollectsError(): void
    {
        $v = new Validator([]);
        $v->requiredString('title', 200);

        try {
            $v->check();
            $this->fail('VALIDATION_FAILED 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertSame(422, $e->status());
            $this->assertSame(['title' => '필수 항목입니다.'], $e->details());
        }
    }

    public function testAllErrorsAreReportedTogether(): void
    {
        $v = new Validator(['title' => str_repeat('가', 201)]);
        $v->requiredString('title', 200);
        $v->requiredString('content');

        try {
            $v->check();
            $this->fail('VALIDATION_FAILED 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertSame(['title', 'content'], array_keys($e->details()));
        }
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $v = new Validator(['title' => str_repeat('가', 200)]);
        $v->requiredString('title', 200);

        $v->check();
        $this->addToAssertionCount(1);
    }

    public function testOptionalStringReturnsDefaultWhenAbsent(): void
    {
        $v = new Validator([]);

        $this->assertNull($v->optionalString('category', 50));
        $v->check();
    }

    public function testEmptyStringCountsAsAbsentForOptional(): void
    {
        $v = new Validator(['category' => '   ']);

        $this->assertNull($v->optionalString('category', 50));
    }

    public function testPasswordMinimumLength(): void
    {
        $v = new Validator(['password' => '123']);
        $v->requiredPassword('password');

        try {
            $v->check();
            $this->fail('VALIDATION_FAILED 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertSame(['password' => '4자 이상이어야 합니다.'], $e->details());
        }
    }

    public function testIntClampsToRange(): void
    {
        $v = new Validator(['per_page' => '500']);

        $this->assertSame(100, $v->int('per_page', 20, 1, 100));
        $this->assertSame(20, (new Validator([]))->int('per_page', 20, 1, 100));
        $this->assertSame(1, (new Validator(['page' => '0']))->int('page', 1, 1, 9999));
    }

    public function testBoolAcceptsCommonTruthyForms(): void
    {
        foreach ([true, 'true', '1', 1] as $truthy) {
            $this->assertTrue((new Validator(['x' => $truthy]))->bool('x', false), var_export($truthy, true));
        }
        foreach ([false, 'false', '0', 0] as $falsy) {
            $this->assertFalse((new Validator(['x' => $falsy]))->bool('x', true), var_export($falsy, true));
        }
        $this->assertTrue((new Validator([]))->bool('x', true));
    }

    public function testInListRejectsUnknownValue(): void
    {
        $v = new Validator(['perm_read' => 'nonsense']);
        $v->inList('perm_read', ['guest', 'member', 'admin'], 'guest');

        try {
            $v->check();
            $this->fail('VALIDATION_FAILED 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertArrayHasKey('perm_read', $e->details());
        }
    }
}
