<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Schema;

use SunsetReverie\Database\Connection;

/**
 * 表结构操作入口（迁移中使用）。
 *
 * 只提供 create / drop / hasTable 三个动作——迁移里最常见的需求。
 * 修改列、重命名、外键等场景请写原生 SQL，避免 DSL 无限膨胀。
 */
final class Schema
{
    /**
     * @param Connection $connection 数据库连接
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * 建表。
     *
     * @param string $table 表名
     * @param callable(Blueprint): void $definition 结构定义闭包
     * @return void
     */
    public function create(string $table, callable $definition): void
    {
        $blueprint = new Blueprint($table);
        $definition($blueprint);

        foreach ($blueprint->toSql($this->connection->driver()) as $sql) {
            $this->connection->affect($sql);
        }
    }

    /**
     * 删除表（存在才删）。
     *
     * @param string $table 表名
     * @return void
     */
    public function dropIfExists(string $table): void
    {
        $this->connection->affect('DROP TABLE IF EXISTS ' . $table);
    }

    /**
     * 表是否存在。
     *
     * @param string $table 表名
     * @return bool 存在返回 true
     */
    public function hasTable(string $table): bool
    {
        try {
            $this->connection->scalar('SELECT 1 FROM ' . $table . ' WHERE 1 = 0');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
