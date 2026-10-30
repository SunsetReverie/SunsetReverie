<?php

declare(strict_types=1);

namespace SunsetReverie\RateLimit;

use SunsetReverie\Cache\CacheManager;
use SunsetReverie\Cache\CacheStoreInterface;

/**
 * 限流器：固定窗口计数。
 *
 * 为什么用固定窗口而不是滑动窗口：
 * - 固定窗口只需一次 get + 一次 put，跨驱动行为一致；
 * - 已知缺陷是"窗口边界突刺"（两个窗口交界处可能放过 2 倍流量），
 *   对防爆破、防刷接口足够，不需要引入令牌桶的复杂度；
 * - 键里带时间片，天然实现"窗口到期自动清零"，不依赖驱动的 TTL 精度。
 */
final class RateLimiter
{
    /**
     * @param CacheManager $cache 缓存管理器
     * @param string $store 驱动名（空串表示默认驱动）
     * @param string $prefix 键前缀（多项目共用一个缓存时用于隔离）
     */
    public function __construct(
        private readonly CacheManager $cache,
        private readonly string $store = '',
        private readonly string $prefix = 'rl:',
    ) {
    }

    /**
     * 取当前域对应的缓存驱动。
     *
     * 每次取而不是构造时注入：长驻运行时域会切换，
     * 固定实例会带着上一个域的前缀继续计数。
     *
     * @return CacheStoreInterface 缓存驱动
     */
    private function store(): CacheStoreInterface
    {
        return $this->cache->store($this->store === '' ? null : $this->store);
    }

    /**
     * 记一次请求并返回判定结果。
     *
     * @param string $key 限流维度（如 IP + 路由）
     * @param int $maxAttempts 窗口内最大次数
     * @param int $decaySeconds 窗口长度（秒）
     * @return Limit 判定结果
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): Limit
    {
        $maxAttempts = max(1, $maxAttempts);
        $decaySeconds = max(1, $decaySeconds);

        $windowStart = intdiv(time(), $decaySeconds) * $decaySeconds;
        $cacheKey = $this->prefix . $key . ':' . $windowStart;

        $cache = $this->store();
        $current = (int) $cache->get($cacheKey, 0) + 1;
        $cache->put($cacheKey, $current, $decaySeconds);

        return new Limit(
            limit: $maxAttempts,
            remaining: max(0, $maxAttempts - $current),
            retryAfter: max(1, $windowStart + $decaySeconds - time()),
            allowed: $current <= $maxAttempts,
        );
    }

    /**
     * 查询当前计数（不计数，用于诊断）。
     *
     * @param string $key 限流维度
     * @param int $decaySeconds 窗口长度
     * @return int 当前窗口已计数
     */
    public function count(string $key, int $decaySeconds): int
    {
        $decaySeconds = max(1, $decaySeconds);
        $windowStart = intdiv(time(), $decaySeconds) * $decaySeconds;

        return (int) $this->store()->get($this->prefix . $key . ':' . $windowStart, 0);
    }

    /**
     * 清空某个维度当前窗口的计数（登录成功后重置失败计数等场景）。
     *
     * @param string $key 限流维度
     * @param int $decaySeconds 窗口长度
     * @return void
     */
    public function reset(string $key, int $decaySeconds): void
    {
        $decaySeconds = max(1, $decaySeconds);
        $windowStart = intdiv(time(), $decaySeconds) * $decaySeconds;

        $this->store()->forget($this->prefix . $key . ':' . $windowStart);
    }
}
