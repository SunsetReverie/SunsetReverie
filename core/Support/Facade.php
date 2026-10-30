<?php

declare(strict_types=1);

namespace SunsetReverie\Support;

use BadMethodCallException;
use RuntimeException;
use SunsetReverie\Container\Container;

/**
 * 静态门面基类。
 *
 * 作用：让 `Route::get()` / `DB::table()` 这类静态写法转发到容器里的实例，
 * 避免业务代码到处传参。**它只是语法糖，不改变依赖方向**——
 * 解析仍然走容器，测试里换成假实例即可。
 *
 * 代价（必须知道）：
 * - 引入一个静态的容器引用，属于全局状态。PHP-FPM 每请求新进程无影响；
 *   长驻模式下若一个进程跑多个应用实例，需要重新 `setContainer()`；
 * - 静态调用在 IDE 里跳转到门面文件而非实现类，排查时多用 `Xxx::resolve()`。
 *
 * 因此约定：**门面只用于"配置式代码"**（路由注册、控制器里的一次性调用），
 * 领域服务内部一律用构造函数注入，保持可测性。
 */
abstract class Facade
{
    /** @var Container|null 应用容器（由 Application 引导时注入） */
    private static ?Container $container = null;

    /**
     * 设置容器（Application 引导时调用）。
     *
     * @param Container $container 容器
     * @return void
     */
    public static function setContainer(Container $container): void
    {
        self::$container = $container;
    }

    /**
     * 清除容器引用（测试与长驻模式重置用）。
     *
     * @return void
     */
    public static function clearContainer(): void
    {
        self::$container = null;
    }

    /**
     * 解析门面对应的实例。
     *
     * @return mixed 实例
     * @throws RuntimeException 应用尚未引导时抛出
     */
    public static function resolve(): mixed
    {
        if (self::$container === null) {
            throw new RuntimeException(sprintf(
                '[%s] 在应用引导完成前被调用：请确认已执行 Application::boot()，'
                . '或在配置文件中改用注入方式（配置文件在容器就绪前加载）',
                static::class
            ));
        }

        return self::$container->get(static::accessor());
    }

    /**
     * 判断是否已就绪（诊断与测试用）。
     *
     * @return bool 就绪返回 true
     */
    public static function isReady(): bool
    {
        return self::$container !== null;
    }

    /**
     * 门面绑定的容器标识（通常是类名）。
     *
     * @return string 容器标识
     */
    abstract protected static function accessor(): string;

    /**
     * 静态调用转发到实例方法。
     *
     * @param string $method 方法名
     * @param array<int, mixed> $arguments 参数
     * @return mixed 调用结果
     * @throws BadMethodCallException 实例上不存在该方法时抛出
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        $instance = static::resolve();

        // 注意：不能用 is_callable —— 它对 __call 魔术方法返回 false。
        // CacheManager / LogManager 正是靠 __call 转发到默认驱动/通道。
        if (!method_exists($instance, $method) && !method_exists($instance, '__call')) {
            throw new BadMethodCallException(sprintf(
                '%s 不支持 %s()；可用方法见 %s',
                static::class,
                $method,
                $instance::class
            ));
        }

        return $instance->{$method}(...$arguments);
    }
}
