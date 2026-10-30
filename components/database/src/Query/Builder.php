<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Query;

use Closure;
use InvalidArgumentException;
use SunsetReverie\Database\Connection;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;
use SunsetReverie\Database\Exception\QueryException;

/**
 * 查询构造器（v0.3 范围：单表 CRUD）。
 *
 * 安全设计：
 * - 所有**值**一律参数绑定；
 * - 所有**标识符**（表名 / 字段 / 排序方向）走白名单校验，非法立即抛异常；
 * - `update()` / `delete()` 无 where 条件时直接拒绝，避免误伤全表。
 *
 * 暂不提供：join、子查询、union、批量插入（按需再加，避免过度设计）。
 */
final class Builder
{
    /** @var list<string> 查询字段 */
    private array $columns = ['*'];

    /** @var list<array{sql: string, bindings: list<mixed>, boolean: string}> where 片段 */
    private array $wheres = [];

    /** @var list<array{column: string, direction: string}> 排序 */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /**
     * @param Connection $connection 连接
     * @param string $table 表名
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {
        $this->assertIdentifier($table);
    }

    /**
     * 指定查询字段。
     *
     * @param string ...$columns 字段名
     * @return self 当前构造器
     */
    public function select(string ...$columns): self
    {
        if ($columns !== []) {
            foreach ($columns as $column) {
                $this->assertIdentifier($column);
            }

            $this->columns = array_values($columns);
        }

        return $this;
    }

    /**
     * 添加 where 条件。
     *
     * @param string $column 字段
     * @param mixed $operator 运算符（两参数调用时作为值）
     * @param mixed $value 值
     * @return self 当前构造器
     * @throws InvalidArgumentException 运算符不支持时抛出
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        $argumentCount = func_num_args();

        if ($argumentCount === 2) {
            $value = $operator;
            $operator = '=';
        }

        $operator = strtolower(trim((string) $operator));

        if (!in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like'], true)) {
            throw new InvalidArgumentException(sprintf(
                '不支持的运算符 [%s]，可用：= != <> < > <= >= like not like',
                $operator
            ));
        }

        $this->assertIdentifier($column);
        $this->wheres[] = [
            'sql' => sprintf('%s %s ?', $column, $operator),
            'bindings' => [$value],
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * 添加 or where 条件。
     *
     * @param string $column 字段
     * @param mixed $operator 运算符
     * @param mixed $value 值
     * @return self 当前构造器
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        $before = count($this->wheres);
        $this->where($column, $operator, $value);

        if (count($this->wheres) > $before) {
            $this->wheres[$before]['boolean'] = 'or';
        }

        return $this;
    }

    /**
     * where in 条件。
     *
     * @param string $column 字段
     * @param list<mixed> $values 值列表
     * @return self 当前构造器
     * @throws InvalidArgumentException 列表为空时抛出
     */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('whereIn 的值列表不能为空');
        }

        $this->assertIdentifier($column);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = [
            'sql' => sprintf('%s IN (%s)', $column, $placeholders),
            'bindings' => array_values($values),
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * where null 条件。
     *
     * @param string $column 字段
     * @return self 当前构造器
     */
    public function whereNull(string $column): self
    {
        $this->assertIdentifier($column);
        $this->wheres[] = ['sql' => sprintf('%s IS NULL', $column), 'bindings' => [], 'boolean' => 'and'];

        return $this;
    }

    /**
     * 排序。
     *
     * @param string $column 字段
     * @param string $direction 方向（asc / desc）
     * @return self 当前构造器
     * @throws InvalidArgumentException 方向非法时抛出
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->assertIdentifier($column);
        $direction = strtolower($direction);

        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException(sprintf('排序方向只能是 asc 或 desc，收到 [%s]', $direction));
        }

        $this->orders[] = ['column' => $column, 'direction' => strtoupper($direction)];

        return $this;
    }

    /**
     * 限制条数。
     *
     * @param int $value 条数
     * @return self 当前构造器
     * @throws InvalidArgumentException 负数时抛出
     */
    public function limit(int $value): self
    {
        if ($value < 0) {
            throw new InvalidArgumentException('limit 不能为负数');
        }

        $this->limit = $value;

        return $this;
    }

    /**
     * 跳过条数（需配合 limit）。
     *
     * @param int $value 偏移量
     * @return self 当前构造器
     * @throws InvalidArgumentException 负数时抛出
     */
    public function offset(int $value): self
    {
        if ($value < 0) {
            throw new InvalidArgumentException('offset 不能为负数');
        }

        $this->offset = $value;

        return $this;
    }

    /**
     * 执行查询。
     *
     * @return list<array<string, mixed>> 结果集
     * @throws QueryException 执行失败时抛出
     */
    public function get(): array
    {
        return $this->connection->select($this->toSelectSql(), $this->bindings());
    }

    /**
     * 获取首行。
     *
     * @return array<string, mixed>|null 首行或 null
     * @throws QueryException 执行失败时抛出
     */
    public function first(): ?array
    {
        $this->limit(1);

        return $this->connection->selectOne($this->toSelectSql(), $this->bindings());
    }

    /**
     * 获取单个字段值。
     *
     * @param string $column 字段名
     * @return mixed 字段值，无结果返回 null
     * @throws QueryException 执行失败时抛出
     */
    public function value(string $column): mixed
    {
        $this->assertIdentifier($column);
        $this->columns = [$column];
        $this->limit(1);

        return $this->connection->scalar($this->toSelectSql(), $this->bindings());
    }

    /**
     * 统计行数。
     *
     * @return int 行数
     * @throws QueryException 执行失败时抛出
     */
    public function count(): int
    {
        $sql = sprintf('SELECT COUNT(*) AS aggregate FROM %s%s', $this->table, $this->toWhereSql());

        return (int) $this->connection->scalar($sql, $this->bindings());
    }

    /**
     * 分页查询（一次返回数据与分页元信息）。
     *
     * @param int $perPage 每页条数（上限 100）
     * @param int $page 页码（从 1 开始）
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);

        $total = $this->count();
        $items = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $total === 0 ? 1 : (int) ceil($total / $perPage),
        ];
    }

    /**
     * 按主键查询，不存在时抛 404。
     *
     * @param int|string $id 主键值
     * @param string $primaryKey 主键字段
     * @return array<string, mixed> 记录
     * @throws HttpException 记录不存在时抛出
     */
    public function findOrFail(int|string $id, string $primaryKey = 'id'): array
    {
        $row = $this->where($primaryKey, $id)->first();

        if ($row === null) {
            throw new HttpException(404, ErrorCode::NotFound->value, sprintf(
                '记录不存在（%s = %s）',
                $primaryKey,
                (string) $id
            ));
        }

        return $row;
    }

    /**
     * 条件成立时才应用额外约束。
     *
     * @param bool $condition 条件
     * @param Closure(self): void $callback 约束回调
     * @return self 当前构造器
     */
    public function when(bool $condition, Closure $callback): self
    {
        if ($condition) {
            $callback($this);
        }

        return $this;
    }

    /**
     * BETWEEN 条件（闭区间）。
     *
     * @param string $column 字段
     * @param array{0: mixed, 1: mixed} $range 两个边界
     * @return self 当前构造器
     * @throws InvalidArgumentException 边界数量不对时抛出
     */
    public function whereBetween(string $column, array $range): self
    {
        $values = array_values($range);

        if (count($values) !== 2) {
            throw new InvalidArgumentException('whereBetween 需要恰好两个边界值');
        }

        $this->assertIdentifier($column);
        $this->wheres[] = [
            'sql' => sprintf('%s BETWEEN ? AND ?', $column),
            'bindings' => [$values[0], $values[1]],
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * 原子自增（`SET col = col + ?`）。
     *
     * 为什么必须走 SQL 而不是"先读再写"：并发下读改写会丢更新
     * （两个请求都读到 10，各自写 11，最终 11 而非 12）。
     *
     * @param string $column 列名
     * @param int $step 步长
     * @param array<string, mixed> $extra 同时更新的其他列
     * @return int 受影响行数
     */
    public function increment(string $column, int $step = 1, array $extra = []): int
    {
        return $this->applyDelta($column, $step, $extra);
    }

    /**
     * 原子自减。
     *
     * @param string $column 列名
     * @param int $step 步长
     * @param array<string, mixed> $extra 同时更新的其他列
     * @return int 受影响行数
     */
    public function decrement(string $column, int $step = 1, array $extra = []): int
    {
        return $this->applyDelta($column, -$step, $extra);
    }

    /**
     * 执行 `SET col = col ± ?`。
     *
     * @param string $column 列名
     * @param int $delta 增量（负数为减）
     * @param array<string, mixed> $extra 同时更新的其他列
     * @return int 受影响行数
     */
    private function applyDelta(string $column, int $delta, array $extra): int
    {
        $this->assertIdentifier($column);

        $sets = [sprintf('%s = %s + ?', $column, $column)];
        $bindings = [$delta];

        foreach ($extra as $name => $value) {
            $this->assertIdentifier((string) $name);
            $sets[] = $name . ' = ?';
            $bindings[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s%s',
            $this->table,
            implode(', ', $sets),
            $this->toWhereSql()
        );

        // SET 的绑定在前，WHERE 的绑定在后
        return $this->connection->affect($sql, [...$bindings, ...$this->bindings()]);
    }

    /**
     * 是否存在匹配行。
     *
     * @return bool 存在返回 true
     * @throws QueryException 执行失败时抛出
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * 插入一行。
     *
     * @param array<string, mixed> $data 字段 => 值
     * @return int 受影响行数
     * @throws InvalidArgumentException 数据为空时抛出
     * @throws QueryException 执行失败时抛出
     */
    public function insert(array $data): int
    {
        return $this->connection->affect($this->toInsertSql($data), array_values($data));
    }

    /**
     * 插入并返回自增主键。
     *
     * @param array<string, mixed> $data 字段 => 值
     * @param string $primaryKey 主键字段（PostgreSQL 使用 RETURNING）
     * @return string 主键值
     * @throws QueryException 执行失败时抛出
     */
    public function insertGetId(array $data, string $primaryKey = 'id'): int
    {
        if ($this->connection->driver() === 'pgsql') {
            $this->assertIdentifier($primaryKey);
            $sql = $this->toInsertSql($data) . sprintf(' RETURNING %s', $primaryKey);
            $value = $this->connection->scalar($sql, array_values($data));

            return (int) $value;
        }

        $this->connection->affect($this->toInsertSql($data), array_values($data));

        return (int) $this->connection->lastInsertId();
    }

    /**
     * 更新满足条件的行。
     *
     * @param array<string, mixed> $data 字段 => 新值
     * @return int 受影响行数
     * @throws InvalidArgumentException 无 where 条件或数据为空时抛出
     * @throws QueryException 执行失败时抛出
     */
    public function update(array $data): int
    {
        if ($this->wheres === []) {
            throw new InvalidArgumentException('update() 缺少 where 条件，已拒绝执行（防止全表更新）');
        }

        if ($data === []) {
            throw new InvalidArgumentException('update() 的更新数据不能为空');
        }

        $assignments = [];

        foreach (array_keys($data) as $column) {
            $this->assertIdentifier((string) $column);
            $assignments[] = sprintf('%s = ?', $column);
        }

        $sql = sprintf(
            'UPDATE %s SET %s%s',
            $this->table,
            implode(', ', $assignments),
            $this->toWhereSql()
        );

        return $this->connection->affect($sql, [...array_values($data), ...$this->bindings()]);
    }

    /**
     * 删除满足条件的行。
     *
     * @return int 受影响行数
     * @throws InvalidArgumentException 无 where 条件时抛出
     * @throws QueryException 执行失败时抛出
     */
    public function delete(): int
    {
        if ($this->wheres === []) {
            throw new InvalidArgumentException('delete() 缺少 where 条件，已拒绝执行（防止清空表）');
        }

        $sql = sprintf('DELETE FROM %s%s', $this->table, $this->toWhereSql());

        return $this->connection->affect($sql, $this->bindings());
    }

    /**
     * 生成查询 SQL（调试用）。
     *
     * @return string SQL
     */
    public function toSql(): string
    {
        return $this->toSelectSql();
    }

    /**
     * 获取全部绑定值（调试用）。
     *
     * @return list<mixed> 绑定值
     */
    public function getBindings(): array
    {
        return $this->bindings();
    }

    /**
     * 生成 SELECT 语句。
     *
     * @return string SQL
     */
    private function toSelectSql(): string
    {
        $sql = sprintf(
            'SELECT %s FROM %s%s%s',
            implode(', ', $this->columns),
            $this->table,
            $this->toWhereSql(),
            $this->toOrderSql()
        );

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;

            if ($this->offset !== null) {
                $sql .= ' OFFSET ' . $this->offset;
            }
        } elseif ($this->offset !== null) {
            throw new InvalidArgumentException('offset 必须配合 limit 使用（不同数据库默认语义不一致）');
        }

        return $sql;
    }

    /**
     * 生成 INSERT 语句。
     *
     * @param array<string, mixed> $data 数据
     * @return string SQL
     * @throws InvalidArgumentException 数据为空时抛出
     */
    private function toInsertSql(array $data): string
    {
        if ($data === []) {
            throw new InvalidArgumentException('insert() 的数据不能为空');
        }

        foreach (array_keys($data) as $column) {
            $this->assertIdentifier((string) $column);
        }

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_keys($data)),
            implode(', ', array_fill(0, count($data), '?'))
        );
    }

    /**
     * 生成 WHERE 子句。
     *
     * @return string 形如 " WHERE a = ? AND b = ?"
     */
    private function toWhereSql(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        $parts = [];

        foreach ($this->wheres as $index => $where) {
            $parts[] = ($index === 0 ? '' : strtoupper($where['boolean']) . ' ') . $where['sql'];
        }

        return ' WHERE ' . implode(' ', $parts);
    }

    /**
     * 生成 ORDER BY 子句。
     *
     * @return string 形如 " ORDER BY id DESC"
     */
    private function toOrderSql(): string
    {
        if ($this->orders === []) {
            return '';
        }

        $parts = array_map(
            static fn (array $order): string => $order['column'] . ' ' . $order['direction'],
            $this->orders
        );

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * 汇总绑定值。
     *
     * @return list<mixed> 绑定值
     */
    private function bindings(): array
    {
        $bindings = [];

        foreach ($this->wheres as $where) {
            $bindings = [...$bindings, ...$where['bindings']];
        }

        return $bindings;
    }

    /**
     * 标识符白名单校验（表名、字段名、排序方向）。
     *
     * @param string $identifier 标识符
     * @return void
     * @throws InvalidArgumentException 非法标识符时抛出
     */
    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '非法标识符 [%s]：只允许字母、数字、下划线与单个点（防注入）',
                $identifier
            ));
        }
    }
}
