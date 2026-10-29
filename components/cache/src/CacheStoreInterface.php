<?php

declare(strict_types=1);

namespace SunsetReverie\Cache;

/**
 * 缓存存储契约：只声明所有驱动都能保证的能力。
 *
 * 高级能力（分布式锁、标签、扫描）应通过独立能力接口声明（见规划文档 9.7），
 * 不在本接口中提供"看起来支持、实际不可靠"的方法。
 */
interface CacheStoreInterface
{
    /**
     * 读取缓存。
     *
     * @param string $key 键名（不含前缀）
     * @param mixed $default 默认值
     * @return mixed 缓存值或默认值
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * 写入缓存。
     *
     * @param string $key 键名
     * @param mixed $value 值（必须可序列化）
     * @param int|null $ttl 存活秒数，null 表示使用驱动默认值
     * @return bool 成功返回 true
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * 是否存在且未过期。
     *
     * @param string $key 键名
     * @return bool 存在返回 true
     */
    public function has(string $key): bool;

    /**
     * 删除单个键。
     *
     * @param string $key 键名
     * @return bool 成功返回 true
     */
    public function forget(string $key): bool;

    /**
     * 清空当前驱动的全部缓存。
     *
     * @return bool 成功返回 true
     */
    public function flush(): bool;

    /**
     * 自增计数（用于限流、计数器场景）。
     *
     * @param string $key 键名
     * @param int $step 步长
     * @return int 自增后的值
     */
    public function increment(string $key, int $step = 1): int;
}
