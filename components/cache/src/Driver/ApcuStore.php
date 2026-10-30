<?php

declare(strict_types=1);

namespace SunsetReverie\Cache\Driver;

use SunsetReverie\Cache\CacheStoreInterface;
use SunsetReverie\Cache\Concern\RememberTrait;
use SunsetReverie\Cache\Exception\CacheException;

/**
 * APCu 缓存驱动（单机进程共享内存）。
 *
 * 适用场景：单机部署、无 Redis 依赖，需要跨请求（FPM 多 worker）共享缓存。
 * **不适用**：多机部署（APCu 不跨机器）、需要持久化（重启即丢）。
 *
 * 两个必须知道的限制（实测确认）：
 *
 * 1. **CLI 与 FPM 不共享内存段**。FPM 里写入的值，CLI 进程读不到，反之亦然。
 *    后果：`sr cache:clear` 清不掉 FPM 的 APCu 缓存，只能靠 TTL 过期或重启 FPM。
 *    （FPM 内部多个 worker 之间是共享的，这才是 APCu 的价值所在。）
 *
 * 2. **CLI 下默认不可用**：`apc.enable_cli` 是 PHP_INI_SYSTEM，默认 0，
 *    无法用 ini_set 打开，需要用 `php -d apc.enable_cli=1` 启动。
 *
 * 注意 flush() 不用 `apcu_clear_cache()`——那是清空整个 APCu，
 * 会把同机其他应用的缓存一起删掉。这里按前缀遍历删除。
 */
final class ApcuStore implements CacheStoreInterface
{
    use RememberTrait;

    /**
     * @param string $prefix 键前缀
     * @param int $ttl 默认 TTL
     */
    public function __construct(
        private readonly string $prefix = '',
        private readonly int $ttl = 3600,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertAvailable();

        $success = false;
        $value = apcu_fetch($this->prefix . $key, $success);

        return $success ? $value : $default;
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->assertAvailable();

        return (bool) apcu_store($this->prefix . $key, $value, $ttl ?? $this->ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        $this->assertAvailable();

        return (bool) apcu_exists($this->prefix . $key);
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $key): bool
    {
        $this->assertAvailable();

        return (bool) apcu_delete($this->prefix . $key);
    }

    /**
     * {@inheritDoc}
     *
     * 只删本实例前缀下的键。
     */
    public function flush(): bool
    {
        $this->assertAvailable();

        $iterator = new \APCUIterator(
            '/^' . preg_quote($this->prefix, '/') . '/',
            APC_ITER_KEY
        );

        $keys = [];

        foreach ($iterator as $item) {
            $keys[] = $item['key'];
        }

        if ($keys === []) {
            return true;
        }

        apcu_delete($keys);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function increment(string $key, int $step = 1): int
    {
        $this->assertAvailable();

        $fullKey = $this->prefix . $key;

        // apcu_inc 对不存在的键会创建（初值 0）；首次创建需要显式设置 TTL
        if (!apcu_exists($fullKey)) {
            apcu_store($fullKey, $step, $this->ttl);

            return $step;
        }

        $value = apcu_inc($fullKey, $step);

        if ($value === false) {
            throw new CacheException('APCu 自增失败：' . $fullKey);
        }

        return $value;
    }

    /**
     * 检查扩展是否可用。
     *
     * @return void
     * @throws CacheException 扩展未安装时抛出
     */
    private function assertAvailable(): void
    {
        if (!extension_loaded('apcu') || !function_exists('apcu_fetch')) {
            throw new CacheException('APCu 驱动需要 ext-apcu 扩展，请先安装或改用 file / redis 驱动');
        }

        if (PHP_SAPI === 'cli' && (string) ini_get('apc.enable_cli') !== '1') {
            throw new CacheException('CLI 下使用 APCu 需开启 apc.enable_cli=1');
        }
    }
}
