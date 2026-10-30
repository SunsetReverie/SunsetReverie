<?php

declare(strict_types=1);

namespace SunsetReverie\Mail;

use SunsetReverie\Config\Config;
use SunsetReverie\Container\Container;
use SunsetReverie\Foundation\ServiceProvider;
use SunsetReverie\Log\LogManager;

/**
 * 邮件服务提供者。
 */
final class MailServiceProvider extends ServiceProvider
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->container->singleton(MailManager::class, static function (Container $container): MailManager {
            /** @var Config $config */
            $config = $container->get(Config::class);
            /** @var LogManager $log */
            $log = $container->get(LogManager::class);

            return new MailManager((array) $config->get('mail', []), $log);
        });

        $this->container->bind('mail', MailManager::class);
    }
}
