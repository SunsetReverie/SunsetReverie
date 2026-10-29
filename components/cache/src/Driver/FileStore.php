<?php

declare(strict_types=1);

namespace SunsetReverie\Cache\Driver;

use SunsetReverie\Cache\CacheStoreInterface;
use SunsetReverie\Cache\Concern\RememberTrait;
use SunsetReverie\Cache\Exception\CacheException;

/**
 * 文件缓存：单机可用，跨机不可用（NFS 上 flock 语义不可靠）。
 *
 * 写入采用「临时文件 + rename」保证原子性：读取方只会看到完整文件，
 * 不会读到写了一半的内容。
 */
final class FileStore implements CacheStoreInterface
{
    use RememberTrait;

    /**
     * @param string $directory 缓存目录（绝对路径）
     * @param string $prefix 键前缀
     * @param int $defaultTtl 默认存活秒数
     */
    public function __construct(
        private readonly string $directory,
        private readonly string $prefix = '',
        private readonly int $defaultTtl = 3600,
    ) {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new CacheException(sprintf('缓存目录不可创建：[%s]', $this->directory));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->path($key);

        if (!is_file($file)) {
            return $default;
        }

        $payload = @unserialize((string) @file_get_contents($file), ['allowed_classes' => false]);

        if (!is_array($payload) || !array_key_exists('value', $payload)) {
            @unlink($file);

            return $default;
        }

        $expires = $payload['expires'] ?? null;

        if ($expires !== null && $expires < time()) {
            @unlink($file);

            return $default;
        }

        return $payload['value'];
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->defaultTtl;
        $file = $this->path($key);
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        $payload = serialize([
            'expires' => $ttl > 0 ? time() + $ttl : null,
            'value' => $value,
        ]);

        if (@file_put_contents($temporary, $payload) === false) {
            throw new CacheException(sprintf('缓存写入失败：[%s]', $file));
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            throw new CacheException(sprintf('缓存重命名失败：[%s]', $file));
        }

        @chmod($file, 0644);

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
        $file = $this->path($key);

        return is_file($file) ? @unlink($file) : true;
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): bool
    {
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->directory . '/*.tmp') ?: [] as $file) {
            @unlink($file);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function increment(string $key, int $step = 1): int
    {
        $next = (int) $this->get($key, 0) + $step;
        $this->put($key, $next, $this->defaultTtl);

        return $next;
    }

    /**
     * 计算键对应的文件路径。
     *
     * @param string $key 键名
     * @return string 文件绝对路径
     */
    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $this->prefix . $key) . '.cache';
    }
}
