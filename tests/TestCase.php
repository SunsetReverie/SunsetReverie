<?php

declare(strict_types=1);

namespace Tests;

use Throwable;

/**
 * 极简测试基类：零依赖，提供最常用的断言。
 *
 * v0.1 阶段不引入 PHPUnit，避免为跑一个断言而装整套工具链；
 * 后续接入 PHPUnit 时，只需让测试类改为继承 PHPUnit\Framework\TestCase。
 */
abstract class TestCase
{
    /** @var int 断言计数 */
    private int $assertions = 0;

    /**
     * 每个测试方法执行前调用。
     *
     * @return void
     */
    protected function setUp(): void
    {
    }

    /**
     * 每个测试方法执行后调用。
     *
     * @return void
     */
    protected function tearDown(): void
    {
    }

    /**
     * 获取当前测试已执行的断言数。
     *
     * @return int 断言数
     */
    public function assertionCount(): int
    {
        return $this->assertions;
    }

    /**
     * 断言两值全等。
     *
     * @param mixed $expected 期望值
     * @param mixed $actual 实际值
     * @param string $message 失败提示
     * @return void
     * @throws AssertionFailedException 断言失败时抛出
     */
    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($expected !== $actual) {
            throw new AssertionFailedException(sprintf(
                "%s\n期望：%s\n实际：%s",
                $message !== '' ? $message : '值不全等',
                $this->stringify($expected),
                $this->stringify($actual)
            ));
        }
    }

    /**
     * 断言两值相等（宽松比较）。
     *
     * @param mixed $expected 期望值
     * @param mixed $actual 实际值
     * @param string $message 失败提示
     * @return void
     */
    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($expected != $actual) {
            throw new AssertionFailedException(sprintf(
                "%s\n期望：%s\n实际：%s",
                $message !== '' ? $message : '值不相等',
                $this->stringify($expected),
                $this->stringify($actual)
            ));
        }
    }

    /**
     * 断言为真。
     *
     * @param bool $condition 条件
     * @param string $message 失败提示
     * @return void
     */
    protected function assertTrue(bool $condition, string $message = ''): void
    {
        $this->assertSame(true, $condition, $message !== '' ? $message : '期望为 true');
    }

    /**
     * 断言为假。
     *
     * @param bool $condition 条件
     * @param string $message 失败提示
     * @return void
     */
    protected function assertFalse(bool $condition, string $message = ''): void
    {
        $this->assertSame(false, $condition, $message !== '' ? $message : '期望为 false');
    }

    /**
     * 断言为 null。
     *
     * @param mixed $value 值
     * @param string $message 失败提示
     * @return void
     */
    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertSame(null, $value, $message !== '' ? $message : '期望为 null');
    }

    /**
     * 断言数组包含键。
     *
     * @param string|int $key 键
     * @param array<mixed> $array 数组
     * @param string $message 失败提示
     * @return void
     */
    protected function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        $this->assertions++;

        if (!array_key_exists($key, $array)) {
            throw new AssertionFailedException(
                ($message !== '' ? $message : '数组缺少键') . '：' . (string) $key
            );
        }
    }

    /**
     * 断言字符串包含子串。
     *
     * @param string $needle 子串
     * @param string $haystack 被搜索字符串
     * @param string $message 失败提示
     * @return void
     */
    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;

        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailedException(sprintf(
                "%s\n未找到：%s\n实际内容：%s",
                $message !== '' ? $message : '字符串不包含期望内容',
                $needle,
                $haystack
            ));
        }
    }

    /**
     * 断言抛出指定异常。
     *
     * @param class-string<Throwable> $expected 期望异常类
     * @param callable $callback 待执行闭包
     * @param string $message 失败提示
     * @return Throwable 捕获到的异常
     */
    protected function assertThrows(string $expected, callable $callback, string $message = ''): Throwable
    {
        $this->assertions++;

        try {
            $callback();
        } catch (Throwable $e) {
            if (!$e instanceof $expected) {
                throw new AssertionFailedException(sprintf(
                    "%s\n期望异常：%s\n实际异常：%s（%s）",
                    $message !== '' ? $message : '异常类型不符',
                    $expected,
                    $e::class,
                    $e->getMessage()
                ));
            }

            return $e;
        }

        throw new AssertionFailedException(
            ($message !== '' ? $message . "\n" : '') . '期望抛出异常 ' . $expected . '，但未抛出'
        );
    }

    /**
     * 将值转为可读字符串（失败信息用）。
     *
     * @param mixed $value 值
     * @return string 可读表示
     */
    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . $value . "'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: 'array';
        }

        if (is_object($value)) {
            return $value::class;
        }

        return (string) $value;
    }
}
