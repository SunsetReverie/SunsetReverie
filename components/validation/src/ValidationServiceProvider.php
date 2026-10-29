<?php

declare(strict_types=1);

namespace SunsetReverie\Validation;

use SunsetReverie\Config\Config;
use SunsetReverie\Container\Container;
use SunsetReverie\Foundation\ServiceProvider;

/**
 * 校验组件服务提供者。
 *
 * 注册 Validator 单例，消息模板来自 config/validation.php。
 */
final class ValidationServiceProvider extends ServiceProvider
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->container->singleton(Validator::class, function (Container $container): Validator {
            /** @var Config $config */
            $config = $container->get(Config::class);

            return new Validator((array) $config->get('validation.messages', []));
        });

        $this->container->bind('validator', Validator::class);
    }
}
