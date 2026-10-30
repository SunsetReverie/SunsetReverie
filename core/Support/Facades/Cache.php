<?php

declare(strict_types=1);

namespace SunsetReverie\Support\Facades;

use SunsetReverie\Cache\CacheManager;
use SunsetReverie\Support\Facade;

/**
 * 缓存门面。
 *
 * 未定义的方法会转发到**默认驱动**（见 CacheManager::__call），
 * 因此 `Cache::get()` 与 `Cache::store('redis')->get()` 都能用。
 *
 * @method static \SunsetReverie\Cache\CacheStoreInterface store(?string $name = null)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool put(string $key, mixed $value, ?int $ttl = null)
 * @method static bool forget(string $key)
 * @method static bool flush()
 * @method static mixed remember(string $key, ?int $ttl, \Closure $callback)
 */
final class Cache extends Facade
{
    /**
     * {@inheritDoc}
     */
    protected static function accessor(): string
    {
        return CacheManager::class;
    }
}
