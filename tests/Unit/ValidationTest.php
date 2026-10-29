<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use SunsetReverie\Validation\ValidationException;
use SunsetReverie\Validation\Validator;
use Tests\TestCase;

/**
 * 校验器测试。
 */
final class ValidationTest extends TestCase
{
    /** @var Validator 被测校验器 */
    private Validator $validator;

    /**
     * 初始化校验器。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    /**
     * 必填缺失应抛异常并给出字段错误。
     *
     * @return void
     */
    public function testRequiredFailsWhenMissing(): void
    {
        $e = $this->assertThrows(
            ValidationException::class,
            fn () => $this->validator->validate([], ['title' => 'required|string'])
        );

        $this->assertSame(422, $e->status);
        $this->assertSame(30001, $e->errorCode);
        $this->assertArrayHasKey('title', $e->errors ?? []);
    }

    /**
     * 校验通过时只返回声明字段。
     *
     * @return void
     */
    public function testReturnsOnlyDeclaredFields(): void
    {
        $data = $this->validator->validate(
            ['title' => 'Hello', 'status' => 'draft', 'internal_note' => 'secret'],
            ['title' => 'required|string', 'status' => 'in:draft,published']
        );

        $this->assertSame(['title' => 'Hello', 'status' => 'draft'], $data);
    }

    /**
     * 类型规则应生效。
     *
     * @return void
     */
    public function testTypeRules(): void
    {
        $this->assertThrows(
            ValidationException::class,
            fn () => $this->validator->validate(['age' => 'abc'], ['age' => 'integer'])
        );

        $this->assertSame(
            ['age' => '42'],
            $this->validator->validate(['age' => '42'], ['age' => 'integer'])
        );
    }

    /**
     * 邮箱规则应生效。
     *
     * @return void
     */
    public function testEmailRule(): void
    {
        $this->assertThrows(
            ValidationException::class,
            fn () => $this->validator->validate(['email' => 'not-an-email'], ['email' => 'email'])
        );

        $this->assertSame(
            ['email' => 'a@b.com'],
            $this->validator->validate(['email' => 'a@b.com'], ['email' => 'email'])
        );
    }

    /**
     * min / max / between 按字符串长度计算。
     *
     * @return void
     */
    public function testLengthRules(): void
    {
        $this->assertThrows(
            ValidationException::class,
            fn () => $this->validator->validate(['title' => 'ab'], ['title' => 'min:3'])
        );

        $this->assertThrows(
            ValidationException::class,
            fn () => $this->validator->validate(['title' => 'abcdef'], ['title' => 'max:3'])
        );

        $this->assertSame(
            ['title' => 'abcd'],
            $this->validator->validate(['title' => 'abcd'], ['title' => 'between:3,5'])
        );
    }

    /**
     * in 规则应只允许枚举值。
     *
     * @return void
     */
    public function testInRule(): void
    {
        $this->assertThrows(
            ValidationException::class,
            fn () => $this->validator->validate(['status' => 'archived'], ['status' => 'in:draft,published'])
        );
    }

    /**
     * confirmed 依赖 `_confirmation` 字段。
     *
     * @return void
     */
    public function testConfirmedRule(): void
    {
        $this->assertThrows(
            ValidationException::class,
            fn () => $this->validator->validate(
                ['password' => 'a123456', 'password_confirmation' => 'b123456'],
                ['password' => 'required|confirmed']
            )
        );

        $this->assertSame(
            ['password' => 'a123456'],
            $this->validator->validate(
                ['password' => 'a123456', 'password_confirmation' => 'a123456'],
                ['password' => 'required|confirmed']
            )
        );
    }

    /**
     * nullable 应跳过后续规则。
     *
     * @return void
     */
    public function testNullableSkipsOtherRules(): void
    {
        $data = $this->validator->validate(['nickname' => null], ['nickname' => 'nullable|string|max:10']);

        $this->assertSame(['nickname' => null], $data);
    }

    /**
     * 自定义消息应覆盖默认文案。
     *
     * @return void
     */
    public function testCustomMessages(): void
    {
        $validator = new Validator(['title.required' => '标题必须填写']);

        $e = $this->assertThrows(
            ValidationException::class,
            fn () => $validator->validate([], ['title' => 'required'])
        );

        $this->assertSame('标题必须填写', $e->errors['title'][0] ?? null);
    }

    /**
     * 自定义规则应可用。
     *
     * @return void
     */
    public function testCustomRule(): void
    {
        $this->validator->extend('even', static fn (mixed $value): bool => is_numeric($value) && (int) $value % 2 === 0);

        $this->assertThrows(
            ValidationException::class,
            fn () => $this->validator->validate(['n' => 3], ['n' => 'even'])
        );

        $this->assertSame(['n' => 4], $this->validator->validate(['n' => 4], ['n' => 'even']));
    }

    /**
     * 未知规则应显式报错，而非静默通过。
     *
     * @return void
     */
    public function testUnknownRuleThrows(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            fn () => $this->validator->validate(['x' => 1], ['x' => 'no_such_rule'])
        );
    }

    /**
     * errors() 不应抛异常。
     *
     * @return void
     */
    public function testErrorsDoesNotThrow(): void
    {
        $errors = $this->validator->errors(['title' => ''], ['title' => 'required|string']);

        $this->assertArrayHasKey('title', $errors);
    }
}
