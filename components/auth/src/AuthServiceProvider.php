<?php

declare(strict_types=1);

namespace SunsetReverie\Auth;

use SunsetReverie\Api\DomainContext;
use SunsetReverie\Config\Config;
use SunsetReverie\Container\Container;
use SunsetReverie\Foundation\ServiceProvider;

/**
 * 认证组件服务提供者。
 *
 * 只注册管理器（单例），守卫在首次使用时创建。
 */
final class AuthServiceProvider extends ServiceProvider
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->container->singleton(AuthManager::class, function (Container $container): AuthManager {
            /** @var Config $config */
            $config = $container->get(Config::class);

            return new AuthManager(
                (array) $config->get('auth', []),
                $container->get(DomainContext::class),
            );
        });

        $this->container->bind('auth', AuthManager::class);
    }
}
