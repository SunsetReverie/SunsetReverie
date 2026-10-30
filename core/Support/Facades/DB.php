<?php

declare(strict_types=1);

namespace SunsetReverie\Support\Facades;

use SunsetReverie\Database\Connection;
use SunsetReverie\Support\Facade;

/**
 * 数据库门面（指向默认连接）。
 *
 * @method static \SunsetReverie\Database\Query\Builder table(string $table)
 * @method static list<array<string, mixed>> select(string $sql, list<mixed> $bindings = [])
 * @method static ?array<string, mixed> selectOne(string $sql, list<mixed> $bindings = [])
 * @method static mixed scalar(string $sql, list<mixed> $bindings = [])
 * @method static int affect(string $sql, list<mixed> $bindings = [])
 * @method static int statement(string $sql, list<mixed> $bindings = [])
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
