<?php

declare(strict_types=1);

namespace SunsetReverie\Cache;

use Closure;
use SunsetReverie\Api\DomainContext;
use SunsetReverie\Cache\Driver\ArrayStore;
use SunsetReverie\Cache\Driver\FileStore;
use SunsetReverie\Cache\Exception\CacheException;

/**
 * 缓存管理器：按配置选驱动，懒加载并复用实例。
 *
 * 与数据库连接管理器保持同一种模式（driver/getDefaultDriver/extend/purge），
 * 扩展者只需记住一种写法。
 */
final class CacheManager
{
    /** @var array<string, CacheStoreInterface> 已创建的驱动实例 */
    private array $stores = [];

    /** @var array<string, Closure(array<string, mixed>): CacheStoreInterface> 自定义驱动工厂 */
    private array $extensions = [];

    /**
     * @param array<string, mixed> $config 缓存配置（default + prefix + stores）
     * @param string $basePath 项目根目录（文件缓存路径解析用）
     * @param DomainContext|null $domainContext 域上下文（配置了域时自动加前缀隔离）
     */
    public function __construct(
        private readonly array $config,
        private readonly string $basePath = '',
        private readonly ?DomainContext $domainContext = null,
    ) {
    }

    /**
     * 获取缓存驱动。
     *
     * @param string|null $name 驱动名，null 表示默认驱动
     * @return CacheStoreInterface 驱动实例
     * @throws CacheException 驱动不存在时抛出
     */
    public function store(?string $name = null): CacheStoreInterface
    {
        $name ??= (string) ($this->config['default'] ?? 'file');

        if (isset($this->stores[$name])) {
            return $this->stores[$name];
        }

        $store = $this->createStore($name);
        $this->stores[$name] = $store;

        return $store;
    }

    /**
     * 注册自定义驱动。
     *
     * @param string $driver 驱动名
     * @param Closure(array<string, mixed>): CacheStoreInterface $factory 工厂
     * @return self 当前实例
     * @throws CacheException 同名驱动已注册时抛出
     */
    public function extend(string $driver, Closure $factory): self
    {
        if (isset($this->extensions[$driver])) {
            throw new CacheException(sprintf('缓存驱动 [%s] 已注册，禁止静默覆盖', $driver));
        }

        $this->extensions[$driver] = $factory;

        return $this;
    }

    /**
     * 清除已创建的驱动实例。
     *
     * @param string|null $name 驱动名，null 表示全部
     * @return void
     */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->stores = [];

            return;
        }

        unset($this->stores[$name]);
    }

    /**
     * 创建驱动实例。
     *
     * @param string $name 驱动名
     * @return CacheStoreInterface 驱动实例
     * @throws CacheException 配置或驱动缺失时抛出
     */
    private function createStore(string $name): CacheStoreInterface
    {
        $config = $this->config['stores'][$name] ?? null;

        if (!is_array($config)) {
            throw new CacheException(sprintf(
                '未找到缓存驱动配置 [%s]，请在 config/cache.php 中定义',
                $name
            ));
        }

        $driver = (string) ($config['driver'] ?? $name);

        if (isset($this->extensions[$driver])) {
            return ($this->extensions[$driver])($config);
        }

        // 键前缀：basePrefix + {domain}: ，四个域共用一份缓存也不会互相覆盖
        $prefix = (string) ($this->config['prefix'] ?? '');
        $domain = $this->domainContext?->name() ?? '';

        if ($domain !== '' && ($this->config['isolate_by_domain'] ?? true)) {
            $prefix .= $domain . ':';
        }

        $ttl = (int) ($config['ttl'] ?? 3600);

        return match ($driver) {
            'array' => new ArrayStore($prefix, $ttl),
            'file' => new FileStore($this->resolvePath((string) ($config['path'] ?? 'storage/cache')), $prefix, $ttl),
            default => throw new CacheException(sprintf(
                '不支持的缓存驱动 [%s]，可用：array / file，或通过 extend() 注册',
                $driver
            )),
        };
    }

    /**
     * 解析相对路径为绝对路径。
     *
     * @param string $path 路径
     * @return string 绝对路径
     */
    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return $path;
        }

        return $this->basePath === '' ? $path : $this->basePath . '/' . ltrim($path, '/');
    }
}
