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

    /**
     * ?q[]=x 처럼 배열로 온 값을 (string) 으로 캐스팅하면 "Array to string
     * conversion" 경고가 난다. phpunit.xml.dist 의 failOnWarning="true" 때문에
     * 이 경고 하나가 테스트를 실패시킨다 — 배열은 검증 실패로 처리해야 한다.
     */
    public function testRequiredStringRejectsArrayValueWithoutWarning(): void
    {
        $v = new Validator(['title' => ['x']]);
        $v->requiredString('title', 200);

        try {
            $v->check();
            $this->fail('VALIDATION_FAILED 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertArrayHasKey('title', $e->details());
        }
    }

    /** @see testRequiredStringRejectsArrayValueWithoutWarning */
    public function testOptionalStringRejectsArrayValueWithoutWarning(): void
    {
        $v = new Validator(['q' => ['x']]);
        $v->optionalString('q', 100);

        try {
            $v->check();
            $this->fail('VALIDATION_FAILED 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertArrayHasKey('q', $e->details());
        }
    }

    /**
     * bool() 도 requiredString()/optionalString() 과 같은 이유로 배열 입력에
     * 경고를 낸다: ?include_deleted[]=x 가 (string) 캐스팅을 거치면서
     * "Array to string conversion" 경고가 나고, failOnWarning="true" 때문에
     * 테스트가 실패한다. 배열은 기본값으로 처리해야 한다.
     */
    public function testBoolRejectsArrayValueWithoutWarning(): void
    {
        $v = new Validator(['include_deleted' => ['x']]);

        $this->assertFalse($v->bool('include_deleted', false));
        $v->check();
    }

    /** @see testBoolRejectsArrayValueWithoutWarning */
    public function testRequiredPasswordRejectsArrayValueWithoutWarning(): void
    {
        $v = new Validator(['password' => ['x']]);
        $v->requiredPassword('password');

        try {
            $v->check();
            $this->fail('VALIDATION_FAILED 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertArrayHasKey('password', $e->details());
        }
    }

    /** @see testBoolRejectsArrayValueWithoutWarning */
    public function testOptionalPasswordRejectsArrayValueWithoutWarning(): void
    {
        $v = new Validator(['password' => ['x']]);

        $this->assertNull($v->optionalPassword('password'));
        $v->check();
    }

    /** @see testBoolRejectsArrayValueWithoutWarning */
    public function testInListRejectsArrayValueWithoutWarning(): void
    {
        $v = new Validator(['perm_read' => ['x']]);
        $v->inList('perm_read', ['guest', 'member', 'admin'], 'guest');

        try {
            $v->check();
            $this->fail('VALIDATION_FAILED 가 나와야 한다');
        } catch (DomainError $e) {
            $this->assertArrayHasKey('perm_read', $e->details());
        }
    }
}
