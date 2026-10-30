<?php

declare(strict_types=1);

namespace SunsetReverie\Log;

use SunsetReverie\Config\Config;
use SunsetReverie\Container\Container;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Foundation\ServiceProvider;

/**
 * 日志组件服务提供者。
 *
 * 注册管理器（单例）与默认通道的 Logger（单例，供上下文栈共享）。
 */
final class LogServiceProvider extends ServiceProvider
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->container->singleton(LogManager::class, function (Container $container): LogManager {
            /** @var Config $config */
            $config = $container->get(Config::class);
            /** @var Application $app */
            $app = $container->get(Application::class);

            return new LogManager(
                (array) $config->get('log', []),
                $app->basePath(),
            );
        });

        // 默认通道的 Logger 必须是单例：中间件 push/pop 的上下文栈要靠它共享
        $this->container->singleton(Logger::class, function (Container $container): Logger {
            /** @var LogManager $manager */
            $manager = $container->get(LogManager::class);

            return $manager->channel();
        });

        $this->container->bind('log', LogManager::class);
    }
}
