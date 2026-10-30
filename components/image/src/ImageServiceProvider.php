<?php

declare(strict_types=1);

namespace SunsetReverie\Image;

use SunsetReverie\Config\Config;
use SunsetReverie\Container\Container;
use SunsetReverie\Foundation\ServiceProvider;

/**
 * 图片服务提供者。
 */
final class ImageServiceProvider extends ServiceProvider
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->container->singleton(ImageManager::class, static function (Container $container): ImageManager {
            /** @var Config $config */
            $config = $container->get(Config::class);

            return new ImageManager((array) $config->get('image', []));
        });

        $this->container->bind('image', ImageManager::class);
    }
}
