<?php

declare(strict_types=1);

namespace SunsetReverie\Support\Facades;

use SunsetReverie\Database\Connection;
use SunsetReverie\Support\Facade;

/**
 * 数据库门面（指向默认连接）。
 *
 * @method static \SunsetReverie\Database\Query\Builder table(string $table)
 * @method static array select(string $sql, array $bindings = [])
 * @method static array selectOne(string $sql, array $bindings = [])
 * @method static mixed scalar(string $sql, array $bindings = [])
 * @method static int affect(string $sql, array $bindings = [])
 * @method static mixed transaction(\Closure $callback)
 */
final class DB extends Facade
{
    /**
     * {@inheritDoc}
     */
    protected static function accessor(): string
    {
        return Connection::class;
    }
}
