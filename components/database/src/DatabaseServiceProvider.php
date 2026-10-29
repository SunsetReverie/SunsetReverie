<?php

declare(strict_types=1);

namespace SunsetReverie\Database;

use SunsetReverie\Config\Config;
use SunsetReverie\Container\Container;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Foundation\ServiceProvider;

/**
 * 数据库组件服务提供者。
 *
 * 只注册连接管理器（单例），不建立实际连接——连接在首次使用时才建立。
 */
final class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->container->singleton(ConnectionManager::class, function (Container $container): ConnectionManager {
            /** @var Config $config */
            $config = $container->get(Config::class);
            /** @var Application $app */
            $app = $container->get(Application::class);

            return new ConnectionManager(
                (array) $config->get('database', []),
                $app->basePath(),
            );
        });

        // 别名：注入 ConnectionManager 与 DB 均可
        $this->container->bind('db', ConnectionManager::class);
    }
}
