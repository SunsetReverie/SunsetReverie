<?php

declare(strict_types=1);

namespace SunsetReverie\Cache\Driver;

use SunsetReverie\Cache\CacheStoreInterface;
use SunsetReverie\Cache\Concern\RememberTrait;

/**
 * 进程内数组缓存。
 *
 * 用途：单元测试、单请求内的重复计算消除。
 * 注意：进程结束即消失，**不能**用于跨请求共享（多进程下各存各的）。
 */
final class ArrayStore implements CacheStoreInterface
{
    use RememberTrait;

    /** @var array<string, array{value: mixed, expires: float|null}> 缓存项 */
    private static array $items = [];

    /**
     * @param string $prefix 键前缀
     * @param int $defaultTtl 默认存活秒数
     */
    public function __construct(
        private readonly string $prefix = '',
        private readonly int $defaultTtl = 3600,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $entry = self::$items[$this->prefix . $key] ?? null;

        if ($entry === null) {
            return $default;
        }

        if ($entry['expires'] !== null && $entry['expires'] < microtime(true)) {
            unset(self::$items[$this->prefix . $key]);

            return $default;
        }

        return $entry['value'];
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->defaultTtl;

        self::$items[$this->prefix . $key] = [
            'value' => $value,
            'expires' => $ttl > 0 ? microtime(true) + $ttl : null,
        ];

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return $this->get($key, self::class) !== self::class;
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $key): bool
    {
        unset(self::$items[$this->prefix . $key]);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): bool
    {
        foreach (array_keys(self::$items) as $key) {
            if ($this->prefix === '' || str_starts_with($key, $this->prefix)) {
                unset(self::$items[$key]);
            }
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function increment(string $key, int $step = 1): int
    {
        $current = (int) $this->get($key, 0);
        $next = $current + $step;
        $this->put($key, $next, $this->defaultTtl);

        return $next;
    }
}
