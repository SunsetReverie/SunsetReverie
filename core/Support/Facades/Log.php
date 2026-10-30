<?php

declare(strict_types=1);

namespace SunsetReverie\Support\Facades;

use SunsetReverie\Log\LogManager;
use SunsetReverie\Support\Facade;

/**
 * 日志门面。
 *
 * 未定义的方法会转发到**默认通道**（见 LogManager::__call）。
 *
 * @method static \SunsetReverie\Log\Logger channel(?string $name = null)
 * @method static void debug(string $message, array<string, mixed> $context = [])
 * @method static void info(string $message, array<string, mixed> $context = [])
 * @method static void warning(string $message, array<string, mixed> $context = [])
 * @method static void error(string $message, array<string, mixed> $context = [])
 */
final class Log extends Facade
{
    /**
     * {@inheritDoc}
     */
    protected static function accessor(): string
    {
        return LogManager::class;
    }
}
