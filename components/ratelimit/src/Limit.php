<?php

declare(strict_types=1);

namespace SunsetReverie\RateLimit;

/**
 * 一次限流判定的结果（不可变值对象）。
 *
 * 对应响应头：
 * - X-RateLimit-Limit     窗口内允许的总次数
 * - X-RateLimit-Remaining 剩余可用次数
 * - Retry-After           超限时建议的重试等待秒数
 */
final class Limit
{
    /**
     * @param int $limit 窗口内允许的最大次数
     * @param int $remaining 剩余次数（已超限时为 0）
     * @param int $retryAfter 距离窗口重置的秒数
     * @param bool $allowed 本次是否放行
     */
    public function __construct(
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $retryAfter,
        public readonly bool $allowed,
    ) {
    }
}
