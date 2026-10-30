<?php

declare(strict_types=1);

namespace SunsetReverie\Support\Facades;

use SunsetReverie\Support\Facade;

/**
 * 配置门面。
 *
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void set(string $key, mixed $value)
 * @method static bool has(string $key)
 * @method static array all()
 */
final class Config extends Facade
{
    /**
     * {@inheritDoc}
     */
    protected static function accessor(): string
    {
        return \SunsetReverie\Config\Config::class;
    }
}
