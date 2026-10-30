<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Migration;

use SunsetReverie\Database\Connection;

/**
 * 迁移记录表：记录哪些迁移已执行、属于哪个批次。
 */
final class Repository
{
    /** 记录表名 */
    public const TABLE = 'migrations';

    /**
     * @param Connection $connection 连接
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * 确保记录表存在。
     *
     * @return void
     */
    public function ensureTable(): void
    {
        try {
            $this->connection->scalar('SELECT 1 FROM ' . self::TABLE . ' WHERE 1 = 0');

            return;
        } catch (\Throwable) {
            // 表不存在，继续创建
        }

        $id = match ($this->connection->driver()) {
            'pgsql' => 'id BIGSERIAL PRIMARY KEY',
            'sqlite' => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
        };

        $this->connection->affect(sprintf(
            'CREATE TABLE %s (%s, migration VARCHAR(255) NOT NULL, batch INTEGER NOT NULL, executed_at %s)',
            self::TABLE,
            $id,
            // PostgreSQL 没有 DATETIME 类型，对应的是 TIMESTAMP
            $this->connection->driver() === 'pgsql' ? 'TIMESTAMP' : 'DATETIME'
        ));
    }

    /**
     * 获取已执行的迁移文件名。
     *
     * @return list<string> 迁移文件名
     */
    public function executed(): array
    {
        $rows = $this->connection->select('SELECT migration FROM ' . self::TABLE . ' ORDER BY id');

        return array_map(static fn (array $row): string => (string) $row['migration'], $rows);
    }

    /**
     * 获取当前最大批次号。
     *
     * @return int 批次号（无记录返回 0）
     */
    public function lastBatch(): int
    {
        $value = $this->connection->scalar('SELECT MAX(batch) FROM ' . self::TABLE);

        return (int) $value;
    }

    /**
     * 记录一次执行。
     *
     * @param string $migration 迁移文件名
     * @param int $batch 批次
     * @return void
     */
    public function log(string $migration, int $batch): void
    {
        $this->connection->affect(
            sprintf(
                'INSERT INTO %s (migration, batch, executed_at) VALUES (?, ?, ?)',
                self::TABLE
            ),
            [$migration, $batch, date('Y-m-d H:i:s')]
        );
    }

    /**
     * 删除一条执行记录。
     *
     * @param string $migration 迁移文件名
     * @return void
     */
    public function forget(string $migration): void
    {
        $this->connection->affect(
            sprintf('DELETE FROM %s WHERE migration = ?', self::TABLE),
            [$migration]
        );
    }

    /**
     * 获取某批次执行过的迁移（按执行顺序倒序，用于回滚）。
     *
     * @param int $batch 批次号
     * @return list<string> 迁移文件名
     */
    public function byBatch(int $batch): array
    {
        $rows = $this->connection->select(
            sprintf('SELECT migration FROM %s WHERE batch = ? ORDER BY id DESC', self::TABLE),
            [$batch]
        );

        return array_map(static fn (array $row): string => (string) $row['migration'], $rows);
    }

    /**
     * 获取每条迁移的批次映射（状态命令用）。
     *
     * @return array<string, int> 迁移文件名 => 批次
     */
    public function batchMap(): array
    {
        $rows = $this->connection->select('SELECT migration, batch FROM ' . self::TABLE . ' ORDER BY id');
        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['migration']] = (int) $row['batch'];
        }

        return $map;
    }
}
