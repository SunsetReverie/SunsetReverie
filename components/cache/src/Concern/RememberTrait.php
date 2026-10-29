<?php

declare(strict_types=1);

namespace SunsetReverie\Cache\Concern;

/**
 * remember() 的通用实现：所有驱动共用同一套"读不到就回源"逻辑。
 */
trait RememberTrait
{
    /** 缓存未命中时使用的哨兵值（避免把 null 误判为未命中） */
    private const MISS_SENTINEL = "\0__sr_cache_miss__\0";

    /**
     * 读取缓存，未命中时执行回调并写入。
     *
     * @param string $key 键名
     * @param int|null $ttl 存活秒数
     * @param callable(): mixed $callback 回源回调
     * @return mixed 缓存值或回调结果
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $cached = $this->get($key, self::MISS_SENTINEL);

        if ($cached !== self::MISS_SENTINEL) {
            return $cached;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    /**
     * 读取并删除（一次性取值）。
     *
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed 值
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }
}
