<?php

declare(strict_types=1);

namespace SunsetReverie\RateLimit;

use SunsetReverie\Cache\CacheManager;
use SunsetReverie\Config\Config;
use SunsetReverie\Container\Container;
use SunsetReverie\Foundation\ServiceProvider;

/**
 * 限流组件服务提供者。
 *
 * 限流计数复用缓存驱动（默认 file），因此不需要单独配置存储。
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->container->singleton(RateLimiter::class, function (Container $container): RateLimiter {
            /** @var Config $config */
            $config = $container->get(Config::class);
            /** @var CacheManager $cache */
            $cache = $container->get(CacheManager::class);

            $store = (string) $config->get('ratelimit.store', '');

            return new RateLimiter(
                $cache->store($store === '' ? null : $store),
                (string) $config->get('ratelimit.prefix', 'rl:'),
            );
        });
    }
}
