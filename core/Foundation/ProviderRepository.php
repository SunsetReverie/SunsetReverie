<?php

declare(strict_types=1);

namespace SunsetReverie\Foundation;

use SunsetReverie\Container\Container;

/**
 * Provider 注册表：负责实例化、按依赖排序、分阶段执行。
 *
 * 排序策略：
 * 1. 先按 dependsOn() 做拓扑排序（存在环则抛异常）；
 * 2. 未声明依赖的保持注册顺序。
 *
 * 只做一次性初始化，运行期无额外开销。
 */
final class ProviderRepository
{
    /** @var array<string, ServiceProvider> 已实例化的 Provider（键是类名，可能来自配置字符串） */
    private array $providers = [];

    /** @var list<string> 注册顺序（类名，顺序即依赖顺序） */
    private array $order = [];

    /** @var bool 是否已完成 boot */
    private bool $booted = false;

    /**
     * @param Container $container 容器
     */
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * 注册 Provider（只调用 register()）。
     *
     * @param list<class-string<ServiceProvider>> $providers Provider 类名
     * @return void
     * @throws \RuntimeException 类不存在或未继承基类时抛出
     */
    public function register(array $providers): void
    {
        foreach ($providers as $class) {
            $this->resolveProvider($class);
        }

        foreach ($this->sorted() as $class) {
            $this->providers[$class]->register();
        }
    }

    /**
     * 启动 Provider（只调用 boot()）。
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->sorted() as $class) {
            $this->providers[$class]->boot();
        }

        $this->booted = true;
    }

    /**
     * 获取全部 Provider 实例。
     *
     * @return array<string, ServiceProvider> Provider 映射（键为类名）
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * 是否已完成启动。
     *
     * @return bool 已启动返回 true
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * 实例化 Provider（幂等）。
     *
     * @param string $class Provider 类名（来自配置，不保证 class-string；内部有存在性检查）
     * @return ServiceProvider Provider 实例
     * @throws \RuntimeException 类不存在或类型不符时抛出
     */
    private function resolveProvider(string $class): ServiceProvider
    {
        if (isset($this->providers[$class])) {
            return $this->providers[$class];
        }

        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf('Provider [%s] 不存在', $class));
        }

        $provider = new $class($this->container);

        if (!$provider instanceof ServiceProvider) {
            throw new \RuntimeException(sprintf('Provider [%s] 必须继承 %s', $class, ServiceProvider::class));
        }

        $this->providers[$class] = $provider;
        $this->order[] = $class;

        return $provider;
    }

    /**
     * 按 dependsOn() 拓扑排序。
     *
     * @return list<class-string<ServiceProvider>> 排序后的类名
     * @throws \RuntimeException 存在循环依赖时抛出
     */
    private function sorted(): array
    {
        $sorted = [];
        $visiting = [];

        /**
         * 深度优先遍历。
         *
         * @param class-string<ServiceProvider> $class 当前节点
         * @return void
         */
        $visit = function (string $class) use (&$visit, &$sorted, &$visiting): void {
            if (in_array($class, $sorted, true)) {
                return;
            }

            if (in_array($class, $visiting, true)) {
                throw new \RuntimeException(sprintf('Provider 依赖存在循环：[%s]', $class));
            }

            $visiting[] = $class;
            $provider = $this->resolveProvider($class);

            foreach ($provider->dependsOn() as $dependency) {
                $visit($dependency);
            }

            $visiting = array_values(array_diff($visiting, [$class]));
            /** @var class-string<\SunsetReverie\Foundation\ServiceProvider> $class */
            $sorted[] = $class;
        };

        foreach ($this->order as $class) {
            $visit($class);
        }

        return $sorted;
    }
}
