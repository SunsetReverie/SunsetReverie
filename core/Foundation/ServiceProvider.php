<?php

declare(strict_types=1);

namespace SunsetReverie\Foundation;

use SunsetReverie\Container\Container;

/**
 * 服务提供者基类。
 *
 * 生命周期：
 * 1. register()：只做绑定，不解析任何服务（此阶段所有 Provider 尚未就绪）；
 * 2. boot()：可解析服务，注册事件、路由、命令等。
 *
 * 约束：构造函数不得做重活（连接数据库、读文件），否则未使用的组件也会付出代价。
 */
abstract class ServiceProvider
{
    /**
     * @param Container $container 容器
     */
    public function __construct(protected readonly Container $container)
    {
    }

    /**
     * 注册绑定（不要在此解析服务）。
     *
     * @return void
     */
    public function register(): void
    {
    }

    /**
     * 启动（可解析服务）。
     *
     * @return void
     */
    public function boot(): void
    {
    }

    /**
     * 声明依赖的其他 Provider（用于顺序约束）。
     *
     * @return list<class-string<ServiceProvider>> 前置 Provider 类名
     */
    public function dependsOn(): array
    {
        return [];
    }
}
