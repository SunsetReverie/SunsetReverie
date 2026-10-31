<?php

declare(strict_types=1);

namespace SunsetReverie\Support\Facades;

use SunsetReverie\Routing\Router;
use SunsetReverie\Support\Facade;

/**
 * 路由门面。
 *
 * @method static \SunsetReverie\Routing\Route get(string $uri, mixed $handler, ?string $name = null)
 * @method static \SunsetReverie\Routing\Route post(string $uri, mixed $handler, ?string $name = null)
 * @method static \SunsetReverie\Routing\Route put(string $uri, mixed $handler, ?string $name = null)
 * @method static \SunsetReverie\Routing\Route patch(string $uri, mixed $handler, ?string $name = null)
 * @method static \SunsetReverie\Routing\Route delete(string $uri, mixed $handler, ?string $name = null)
 * @method static \SunsetReverie\Routing\Route any(string $uri, mixed $handler, ?string $name = null)
 * @method static void group(array<string, mixed> $attributes, \Closure $callback)
 * @method static list<mixed> routes()
 */
final class Route extends Facade
{
    /**
     * {@inheritDoc}
     */
    protected static function accessor(): string
    {
        return Router::class;
    }
}
