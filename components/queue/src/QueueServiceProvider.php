<?php

declare(strict_types=1);

namespace SunsetReverie\Queue;

use SunsetReverie\Config\Config;
use SunsetReverie\Container\Container;
use SunsetReverie\Database\ConnectionManager;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Foundation\ServiceProvider;

/**
 * 队列服务提供者。
 */
final class QueueServiceProvider extends ServiceProvider
{
    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->container->singleton(QueueManager::class, function (Container $container): QueueManager {
            /** @var Config $config */
            $config = $container->get(Config::class);
            /** @var ConnectionManager $connections */
            $connections = $container->get(ConnectionManager::class);

            // 同步驱动的执行器：与 Worker 保持同一套恢复逻辑
            $runner = static function (array $payload): void {
                $class = (string) ($payload['class'] ?? '');

                if (!class_exists($class)) {
                    throw new \RuntimeException(sprintf('任务类不存在：%s', $class));
                }

                /** @var Job $job */
                $job = new $class(...(array) ($payload['data'] ?? []));
                $job->handle();
            };

            return new QueueManager((array) $config->get('queue', []), $connections, \Closure::fromCallable($runner));
        });

        $this->container->bind('queue', QueueManager::class);
    }
}
