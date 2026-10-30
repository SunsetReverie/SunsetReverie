<?php

declare(strict_types=1);

namespace SunsetReverie\Cache;

use SunsetReverie\Api\DomainContext;
use SunsetReverie\Config\Config;
use SunsetReverie\Container\Container;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Foundation\ServiceProvider;

/**
 * 缓存组件服务提供者。
 *
 * 只注册管理器（单例），不创建任何驱动实例。
 */
final class CacheServiceProvider extends ServiceProvider
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->container->singleton(CacheManager::class, function (Container $container): CacheManager {
            /** @var Config $config */
            $config = $container->get(Config::class);
            /** @var Application $app */
            $app = $container->get(Application::class);

            return new CacheManager(
                (array) $config->get('cache', []),
                $app->basePath(),
                $container->get(DomainContext::class),
            );
        });

        $this->container->bind('cache', CacheManager::class);
    }
}
