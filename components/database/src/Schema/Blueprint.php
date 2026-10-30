<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Schema;

/**
 * 表结构定义（最小可用集）。
 *
 * 只覆盖建表时最常用的列与索引；复杂需求（外键级联、分区、生成列）
 * 直接在迁移里写原生 SQL，不硬塞进 DSL。
 */
final class Blueprint
{
    /** @var list<string> 列定义片段 */
    private array $columns = [];

    /** @var list<string> 表级约束（主键 / 唯一） */
    private array $constraints = [];

    /** @var list<array{name: string, columns: list<string>, unique: bool}> 索引 */
    private array $indexes = [];

    /**
     * @param string $table 表名
     */
    public function __construct(private readonly string $table)
    {
    }

    /**
     * 自增主键。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function id(string $name = 'id'): self
    {
        $this->columns[] = sprintf('__ID__:%s', $name);

        return $this;
    }

    /**
     * 字符串列。
     *
     * @param string $name 列名
     * @param int $length 长度
     * @return self 当前实例
     */
    public function string(string $name, int $length = 255): self
    {
        return $this->addColumn($name, sprintf('VARCHAR(%d)', $length));
    }

    /**
     * 长文本列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function text(string $name): self
    {
        return $this->addColumn($name, 'TEXT');
    }

    /**
     * 整数列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function integer(string $name): self
    {
        return $this->addColumn($name, 'INTEGER');
    }

    /**
     * 大整数列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function bigInteger(string $name): self
    {
        return $this->addColumn($name, 'BIGINT');
    }

    /**
     * 布尔列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function boolean(string $name): self
    {
        return $this->addColumn($name, '__BOOL__');
    }

    /**
     * 时间戳列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function timestamp(string $name): self
    {
        return $this->addColumn($name, '__TIMESTAMP__');
    }

    /**
     * 日期列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function date(string $name): self
    {
        return $this->addColumn($name, 'DATE');
    }

    /**
     * 数值列。
     *
     * @param string $name 列名
     * @param int $precision 总位数
     * @param int $scale 小数位
     * @return self 当前实例
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): self
    {
        return $this->addColumn($name, sprintf('DECIMAL(%d,%d)', $precision, $scale));
    }

    /**
     * 创建时间与更新时间列。
     *
     * @return self 当前实例
     */
    public function timestamps(): self
    {
        return $this->timestamp('created_at')->timestamp('updated_at');
    }

    /**
     * 允许为空。
     *
     * @return self 当前实例
     */
    public function nullable(): self
    {
        return $this->modifyLast(' NULL');
    }

    /**
     * 默认值。
     *
     * @param mixed $value 默认值
     * @return self 当前实例
     */
    public function default(mixed $value): self
    {
        $literal = match (true) {
            // TRUE / FALSE 是三种数据库都能接受的写法；
            // 用 1 / 0 会让 PostgreSQL 的 BOOLEAN 列报类型不匹配
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value), is_float($value) => (string) $value,
            $value === null => 'NULL',
            default => "'" . str_replace("'", "''", (string) $value) . "'",
        };

        return $this->modifyLast(' DEFAULT ' . $literal);
    }

    /**
     * 唯一约束（单列）。
     *
     * @return self 当前实例
     */
    public function unique(): self
    {
        return $this->modifyLast('__UNIQUE__');
    }

    /**
     * 添加复合唯一索引（如 `(post_id, user_id)` 保证同一用户对同一文章只点一次）。
     *
     * @param list<string>|string $columns 列（或多列）
     * @param string|null $name 索引名
     * @return self 当前实例
     */
    public function uniqueIndex(array|string $columns, ?string $name = null): self
    {
        $columns = array_values((array) $columns);
        $this->indexes[] = [
            'name' => $name ?? $this->table . '_' . implode('_', $columns) . '_unique',
            'columns' => $columns,
            'unique' => true,
        ];

        return $this;
    }

    /**
     * 添加普通索引。
     *
     * @param list<string>|string $columns 列（或多列）
     * @param string|null $name 索引名
     * @return self 当前实例
     */
    public function index(array|string $columns, ?string $name = null): self
    {
        $columns = array_values((array) $columns);
        $this->indexes[] = [
            'name' => $name ?? $this->table . '_' . implode('_', $columns) . '_index',
            'columns' => $columns,
            'unique' => false,
        ];

        return $this;
    }

    /**
     * 生成建表 SQL。
     *
     * @param string $driver 数据库驱动
     * @return list<string> SQL 语句列表（建表 + 索引）
     */
    public function toSql(string $driver): array
    {
        $definitions = [];
        $uniqueColumns = [];

        foreach ($this->columns as $column) {
            if (str_starts_with($column, '__ID__:')) {
                $name = substr($column, 7);
                $definitions[] = $this->idDefinition($driver, $name);

                continue;
            }

            $isUnique = str_ends_with($column, '__UNIQUE__');
            $isNullable = str_contains($column, ' NULL');
            $column = str_replace('__UNIQUE__', '', $column);

            $column = match ($driver) {
                'pgsql' => str_replace(['__BOOL__', '__TIMESTAMP__'], ['BOOLEAN', 'TIMESTAMP'], $column),
                'sqlite' => str_replace(['__BOOL__', '__TIMESTAMP__'], ['INTEGER', 'DATETIME'], $column),
                default => str_replace(['__BOOL__', '__TIMESTAMP__'], ['TINYINT(1)', 'DATETIME'], $column),
            };

            if (!$isNullable) {
                $column .= ' NOT NULL';
            }

            $definitions[] = $column;

            if ($isUnique) {
                $uniqueColumns[] = strtok(trim($column), ' ');
            }
        }

        foreach ($uniqueColumns as $columnName) {
            $definitions[] = sprintf('UNIQUE (%s)', $columnName);
        }

        $definitions = [...$definitions, ...$this->constraints];

        $sql = sprintf(
            'CREATE TABLE %s (%s)',
            $this->table,
            implode(', ', $definitions)
        );

        if ($driver === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }

        $statements = [$sql];

        foreach ($this->indexes as $index) {
            $statements[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $index['unique'] ? 'UNIQUE ' : '',
                $index['name'],
                $this->table,
                implode(', ', $index['columns'])
            );
        }

        return $statements;
    }

    /**
     * 主键定义（按方言生成）。
     *
     * @param string $driver 驱动
     * @param string $name 列名
     * @return string 列定义
     */
    private function idDefinition(string $driver, string $name): string
    {
        return match ($driver) {
            'pgsql' => sprintf('%s BIGSERIAL PRIMARY KEY', $name),
            'sqlite' => sprintf('%s INTEGER PRIMARY KEY AUTOINCREMENT', $name),
            default => sprintf('%s BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', $name),
        };
    }

    /**
     * 追加列定义。
     *
     * @param string $name 列名
     * @param string $type 类型片段
     * @return self 当前实例
     */
    private function addColumn(string $name, string $type): self
    {
        $this->columns[] = $name . ' ' . $type;

        return $this;
    }

    /**
     * 修改最后一个列定义（nullable / default / unique）。
     *
     * @param string $suffix 追加片段
     * @return self 当前实例
     */
    private function modifyLast(string $suffix): self
    {
        $index = count($this->columns) - 1;

        if ($index >= 0) {
            $this->columns[$index] .= $suffix;
        }

        return $this;
    }
}
