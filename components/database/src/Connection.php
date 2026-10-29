<?php

declare(strict_types=1);

namespace SunsetReverie\Database;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use SunsetReverie\Database\Exception\QueryException;
use SunsetReverie\Database\Query\Builder;

/**
 * 单条数据库连接：PDO 薄封装。
 *
 * 设计边界：
 * - 不做连接池、不做读写分离（v1.x 再议）；
 * - 所有值一律参数绑定，标识符走白名单校验（见 Builder::wrap）；
 * - 异常统一包装为 QueryException，保留原始 SQL 便于排错。
 */
final class Connection
{
    /** @var int 事务嵌套层级（只有最外层真正 begin/commit） */
    private int $transactionLevel = 0;

    /**
     * @param PDO $pdo 已建立的连接
     * @param array<string, mixed> $config 连接配置
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config = [],
    ) {
    }

    /**
     * 获取底层 PDO（少数需要原生能力的场景使用）。
     *
     * @return PDO PDO 实例
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * 驱动名（mysql / pgsql / sqlite）。
     *
     * @return string 驱动名
     */
    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    /**
     * 查询多行。
     *
     * @param string $sql SQL
     * @param array<int|string, mixed> $bindings 绑定值
     * @return list<array<string, mixed>> 结果集
     * @throws QueryException 执行失败时抛出
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll();
    }

    /**
     * 查询单行。
     *
     * @param string $sql SQL
     * @param array<int|string, mixed> $bindings 绑定值
     * @return array<string, mixed>|null 单行结果
     * @throws QueryException 执行失败时抛出
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * 查询单个标量值。
     *
     * @param string $sql SQL
     * @param array<int|string, mixed> $bindings 绑定值
     * @return mixed 首行首列，无结果返回 null
     * @throws QueryException 执行失败时抛出
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * 执行写语句（INSERT / UPDATE / DELETE）。
     *
     * @param string $sql SQL
     * @param array<int|string, mixed> $bindings 绑定值
     * @return int 受影响行数
     * @throws QueryException 执行失败时抛出
     */
    public function affect(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * 执行任意语句并返回原始 PDOStatement。
     *
     * @param string $sql SQL
     * @param array<int|string, mixed> $bindings 绑定值
     * @return PDOStatement 语句对象
     * @throws QueryException 执行失败时抛出
     */
    public function run(string $sql, array $bindings = []): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);

            foreach (array_values($bindings) as $index => $value) {
                $statement->bindValue($index + 1, $value, $this->typeOf($value));
            }

            $statement->execute();

            return $statement;
        } catch (PDOException $e) {
            throw new QueryException($sql, $bindings, $e);
        }
    }

    /**
     * 开启事务（嵌套时使用保存点）。
     *
     * @return void
     * @throws QueryException 开启失败时抛出
     */
    public function beginTransaction(): void
    {
        try {
            if ($this->transactionLevel === 0) {
                $this->pdo->beginTransaction();
            } else {
                $this->pdo->exec('SAVEPOINT trans' . $this->transactionLevel);
            }

            $this->transactionLevel++;
        } catch (PDOException $e) {
            throw new QueryException('BEGIN TRANSACTION', [], $e);
        }
    }

    /**
     * 提交事务。
     *
     * @return void
     * @throws QueryException 提交失败时抛出
     */
    public function commit(): void
    {
        $this->transactionLevel = max(0, $this->transactionLevel - 1);

        try {
            if ($this->transactionLevel === 0) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT trans' . $this->transactionLevel);
            }
        } catch (PDOException $e) {
            throw new QueryException('COMMIT', [], $e);
        }
    }

    /**
     * 回滚事务。
     *
     * @return void
     * @throws QueryException 回滚失败时抛出
     */
    public function rollBack(): void
    {
        $this->transactionLevel = max(0, $this->transactionLevel - 1);

        try {
            if ($this->transactionLevel === 0) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactionLevel);
            }
        } catch (PDOException $e) {
            throw new QueryException('ROLLBACK', [], $e);
        }
    }

    /**
     * 在事务中执行回调（异常自动回滚）。
     *
     * @param callable(Connection): mixed $callback 业务闭包
     * @return mixed 回调返回值
     * @throws Throwable 回调抛出时原样抛出
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    /**
     * 获取自增主键值。
     *
     * @param string|null $sequence PostgreSQL 序列名（其他驱动忽略）
     * @return string 主键值
     * @throws QueryException 获取失败时抛出
     */
    public function lastInsertId(?string $sequence = null): string
    {
        try {
            return $this->pdo->lastInsertId($sequence);
        } catch (PDOException $e) {
            throw new QueryException('LAST INSERT ID', [], $e);
        }
    }

    /**
     * 获取当前事务层级。
     *
     * @return int 层级（0 表示未开启事务）
     */
    public function transactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * 获取查询构造器。
     *
     * @param string $table 表名
     * @return Builder 构造器
     */
    public function table(string $table): Builder
    {
        return new Builder($this, $table);
    }

    /**
     * 根据值类型推断 PDO 参数类型。
     *
     * @param mixed $value 绑定值
     * @return int PDO::PARAM_* 常量
     */
    private function typeOf(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }
}
