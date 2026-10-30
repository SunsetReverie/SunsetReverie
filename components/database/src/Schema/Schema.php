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
     * 获取底层连接。
     *
     * 迁移工具目前只提供建表 / 删表，**改表（加字段、改索引）需要原生 SQL**：
     *
     *   $schema->connection()->affect('ALTER TABLE posts ADD COLUMN like_count INTEGER DEFAULT 0');
     *
     * 这是明确的功能边界：改表涉及大量方言差异（列位置、索引重建、默认值回填），
     * 在没有充分测试的情况下提供半成品 DSL 反而更危险。
     *
     * @return Connection 数据库连接
     */
    public function connection(): Connection
    {
        return $this->connection;
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
