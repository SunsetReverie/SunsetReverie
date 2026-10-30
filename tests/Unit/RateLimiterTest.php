<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Cache\CacheManager;
use SunsetReverie\RateLimit\RateLimiter;
use Tests\TestCase;

/**
 * 限流器测试（固定窗口）。
 */
final class RateLimiterTest extends TestCase
{
    /** @var RateLimiter 被测限流器 */
    private RateLimiter $limiter;

    /**
     * 初始化。
     *
     * @return void
     */
    protected function setUp(): void
    {
        // ArrayStore 是进程内共享存储，用唯一前缀保证测试之间互不干扰
        $this->limiter = new RateLimiter(new CacheManager([
            'default' => 'array',
            'prefix' => 'test:' . uniqid('', true) . ':',
            'stores' => ['array' => ['driver' => 'array']],
        ]), '', 'rl:');
    }

    /**
     * 未超限时应放行并递减剩余次数。
     *
     * @return void
     */
    public function testAllowsWithinLimit(): void
    {
        $first = $this->limiter->attempt('k', 3, 60);
        $this->assertTrue($first->allowed);
        $this->assertSame(3, $first->limit);
        $this->assertSame(2, $first->remaining);

        $second = $this->limiter->attempt('k', 3, 60);
        $this->assertSame(1, $second->remaining);
    }

    /**
     * 超过上限时应拒绝且剩余为 0。
     *
     * @return void
     */
    public function testRejectsAfterLimit(): void
    {
        $this->limiter->attempt('k', 2, 60);
        $this->limiter->attempt('k', 2, 60);

        $third = $this->limiter->attempt('k', 2, 60);

        $this->assertFalse($third->allowed);
        $this->assertSame(0, $third->remaining);
        $this->assertTrue($third->retryAfter > 0);
    }

    /**
     * 不同键应互不影响。
     *
     * @return void
     */
    public function testKeysAreIndependent(): void
    {
        $this->limiter->attempt('a', 1, 60);
        $other = $this->limiter->attempt('b', 1, 60);

        $this->assertTrue($other->allowed);
    }

    /**
     * reset 应清零当前窗口计数。
     *
     * @return void
     */
    public function testResetClearsCounter(): void
    {
        $this->limiter->attempt('k', 1, 60);

        $this->limiter->reset('k', 60);

        $again = $this->limiter->attempt('k', 1, 60);
        $this->assertTrue($again->allowed);
    }

    /**
     * count 不应增加计数。
     *
     * @return void
     */
    public function testCountDoesNotIncrement(): void
    {
        $this->limiter->attempt('k', 5, 60);

        $this->assertSame(1, $this->limiter->count('k', 60));
        $this->assertSame(1, $this->limiter->count('k', 60));
    }
}
