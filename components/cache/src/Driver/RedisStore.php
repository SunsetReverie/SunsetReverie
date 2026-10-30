<?php

declare(strict_types=1);

namespace SunsetReverie\Cache\Driver;

use Redis;
use RedisException;
use SunsetReverie\Cache\CacheStoreInterface;
use SunsetReverie\Cache\Concern\RememberTrait;
use SunsetReverie\Cache\Exception\CacheException;

/**
 * Redis 缓存驱动（基于 phpredis 扩展）。
 *
 * 两个设计要点：
 *
 * 1. **flush() 只删本应用的键**，绝不 `FLUSHDB`。同一 Redis 库常被多个应用共用，
 *    `FLUSHDB` 会连带删掉别人的数据——这是生产事故的经典来源。
 *    实现上用 SCAN 按前缀遍历，再批量 DEL。
 *
 * 2. **increment() 要保住 TTL**。Redis 的 INCR 不会清除已有 TTL，
 *    但首次 INCR 出来的键没有过期时间，会永久堆积；所以首次写入时补 EXPIRE。
 */
final class RedisStore implements CacheStoreInterface
{
    use RememberTrait;

    /** @var Redis|null 连接（懒建立） */
    private ?Redis $client = null;

    /**
     * @param array<string, mixed> $config 连接配置（host/port/password/database/timeout/prefix）
     * @param string $prefix 键前缀
     * @param int $ttl 默认 TTL
     */
    public function __construct(
        private readonly array $config,
        private readonly string $prefix = '',
        private readonly int $ttl = 3600,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $raw = $this->client()->get($this->prefix . $key);
        } catch (RedisException $e) {
            throw new CacheException('Redis 读取失败：' . $e->getMessage());
        }

        if ($raw === false) {
            return $default;
        }

        return $this->decode((string) $raw);
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->ttl;
        $payload = $this->encode($value);

        try {
            $result = $ttl > 0
                ? $this->client()->setex($this->prefix . $key, $ttl, $payload)
                : $this->client()->set($this->prefix . $key, $payload);
        } catch (RedisException $e) {
            throw new CacheException('Redis 写入失败：' . $e->getMessage());
        }

        return $result !== false;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        try {
            return (bool) $this->client()->exists($this->prefix . $key);
        } catch (RedisException $e) {
            throw new CacheException('Redis 查询失败：' . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $key): bool
    {
        try {
            return $this->client()->del($this->prefix . $key) > 0;
        } catch (RedisException $e) {
            throw new CacheException('Redis 删除失败：' . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     *
     * 只删除本实例前缀下的键，不影响同库的其他应用。
     */
    public function flush(): bool
    {
        /*
         * 前缀为空时拒绝执行。
         *
         * 空前缀会拼出 pattern `*`，等于清空整个 Redis 库——
         * 同一实例上往往还跑着别的应用，这属于不可逆的误删。
         * 宁可报错让人显式配置 prefix，也不做"看起来成功"的破坏性操作。
         */
        if ($this->prefix === '') {
            throw new CacheException(
                '拒绝执行 flush：缓存前缀为空（会清空整个 Redis 库，含其他应用的数据）。'
                . '请在 config/cache.php 设置 cache.prefix。'
            );
        }

        $pattern = $this->prefix . '*';
        $deleted = 0;

        try {
            $iterator = null;

            // SCAN 而非 KEYS：KEYS 在大库上会阻塞 Redis
            while (($keys = $this->client()->scan($iterator, $pattern, 500)) !== false) {
                if ($keys !== []) {
                    $deleted += $this->client()->del($keys);
                }

                if ((int) $iterator === 0) {
                    break;
                }
            }
        } catch (RedisException $e) {
            throw new CacheException('Redis 清空失败：' . $e->getMessage());
        }

        return $deleted >= 0;
    }

    /**
     * {@inheritDoc}
     */
    public function increment(string $key, int $step = 1): int
    {
        $fullKey = $this->prefix . $key;

        try {
            $value = (int) $this->client()->incrBy($fullKey, $step);

            // 首次 INCR 产生的键没有过期时间，补上；否则会永久堆积
            if ($value === $step && $this->ttl > 0) {
                $this->client()->expire($fullKey, $this->ttl);
            }
        } catch (RedisException $e) {
            throw new CacheException('Redis 自增失败：' . $e->getMessage());
        }

        return $value;
    }

    /**
     * 获取底层连接（诊断与自定义命令用）。
     *
     * @return Redis 连接
     */
    public function client(): Redis
    {
        if ($this->client instanceof Redis) {
            return $this->client;
        }

        if (!extension_loaded('redis')) {
            throw new CacheException('Redis 驱动需要 phpredis 扩展，请先安装 ext-redis');
        }

        $client = new Redis();
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 6379);
        $timeout = (float) ($this->config['timeout'] ?? 1.5);

        try {
            if (!$client->connect($host, $port, $timeout)) {
                throw new CacheException(sprintf('无法连接 Redis：%s:%d', $host, $port));
            }

            $password = (string) ($this->config['password'] ?? '');

            if ($password !== '') {
                $client->auth($password);
            }

            $database = (int) ($this->config['database'] ?? 0);

            if ($database > 0) {
                $client->select($database);
            }
        } catch (RedisException $e) {
            throw new CacheException('Redis 连接失败：' . $e->getMessage());
        }

        return $this->client = $client;
    }

    /**
     * 序列化（保留类型：数组、布尔、null 都能原样取回）。
     *
     * @param mixed $value 值
     * @return string 序列化结果
     */
    private function encode(mixed $value): string
    {
        return serialize($value);
    }

    /**
     * 反序列化。
     *
     * @param string $raw 原始内容
     * @return mixed 值
     */
    private function decode(string $raw): mixed
    {
        $restored = @unserialize($raw, ['allowed_classes' => false]);

        // 非本框架写入的值（如纯字符串）原样返回，避免误判为 false
        return $restored === false && $raw !== serialize(false) ? $raw : $restored;
    }
}
