<?php

declare(strict_types=1);

namespace SunsetReverie\Database;

use Closure;
use PDO;
use SunsetReverie\Database\Exception\ConnectionException;

/**
 * 连接管理器：按名称创建并复用连接。
 *
 * 设计要点：
 * - 懒连接：首次 `connection()` 时才建立 PDO；
 * - 可扩展：`extend('sqlsrv', factory)` 注册自定义驱动，不改本类；
 * - 无环依赖：本类不依赖容器，测试可直接 new。
 */
final class ConnectionManager
{
    /** @var array<string, Connection> 已建立的连接 */
    private array $connections = [];

    /** @var array<string, Closure(array<string, mixed>): Connection> 自定义驱动工厂 */
    private array $extensions = [];

    /**
     * @param array<string, mixed> $config 数据库配置（default + connections）
     * @param string $basePath 项目根目录（SQLite 相对路径解析用）
     */
    public function __construct(
        private readonly array $config,
        private readonly string $basePath = '',
    ) {
    }

    /**
     * 获取连接（默认连接取 config.default）。
     *
     * @param string|null $name 连接名
     * @return Connection 连接实例
     * @throws ConnectionException 配置缺失或驱动不支持时抛出
     */
    public function connection(?string $name = null): Connection
    {
        $name ??= (string) ($this->config['default'] ?? 'mysql');

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $connection = $this->createConnection($name);
        $this->connections[$name] = $connection;

        return $connection;
    }

    /**
     * 注册自定义驱动。
     *
     * @param string $driver 驱动名（对应 config.driver）
     * @param Closure(array<string, mixed>): Connection $factory 工厂
     * @return self 当前实例
     * @throws ConnectionException 同名驱动已注册时抛出
     */
    public function extend(string $driver, Closure $factory): self
    {
        if (isset($this->extensions[$driver])) {
            throw new ConnectionException(sprintf('驱动 [%s] 已注册，禁止静默覆盖', $driver));
        }

        $this->extensions[$driver] = $factory;

        return $this;
    }

    /**
     * 关闭连接（null 表示全部）。
     *
     * @param string|null $name 连接名
     * @return void
     */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->connections = [];

            return;
        }

        unset($this->connections[$name]);
    }

    /**
     * 创建连接。
     *
     * @param string $name 连接名
     * @return Connection 连接实例
     * @throws ConnectionException 配置或驱动缺失时抛出
     */
    private function createConnection(string $name): Connection
    {
        $config = $this->config['connections'][$name] ?? null;

        if (!is_array($config)) {
            throw new ConnectionException(sprintf(
                '未找到数据库连接配置 [%s]，请在 config/database.php 中定义',
                $name
            ));
        }

        $driver = (string) ($config['driver'] ?? $name);

        if (isset($this->extensions[$driver])) {
            return ($this->extensions[$driver])($config);
        }

        $pdo = $this->createPdo($driver, $config);

        return new Connection($pdo, $config + ['name' => $name]);
    }

    /**
     * 创建 PDO。
     *
     * @param string $driver 驱动名
     * @param array<string, mixed> $config 连接配置
     * @return PDO PDO 实例
     * @throws ConnectionException 驱动不支持或连接失败时抛出
     */
    private function createPdo(string $driver, array $config): PDO
    {
        $dsn = $this->buildDsn($driver, $config);
        $options = $this->buildOptions($config);

        try {
            return new PDO(
                $dsn,
                isset($config['username']) ? (string) $config['username'] : null,
                isset($config['password']) ? (string) $config['password'] : null,
                $options,
            );
        } catch (\PDOException $e) {
            throw new ConnectionException(
                sprintf('数据库连接失败 [%s]：%s', $driver, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * 构建 DSN。
     *
     * @param string $driver 驱动名
     * @param array<string, mixed> $config 连接配置
     * @return string DSN
     * @throws ConnectionException 不支持的驱动时抛出
     */
    private function buildDsn(string $driver, array $config): string
    {
        return match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'] ?? '127.0.0.1',
                (int) ($config['port'] ?? 3306),
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4',
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $config['host'] ?? '127.0.0.1',
                (int) ($config['port'] ?? 5432),
                $config['database'] ?? '',
            ),
            'sqlite' => 'sqlite:' . $this->resolveSqlitePath((string) ($config['database'] ?? ':memory:')),
            default => throw new ConnectionException(sprintf(
                '不支持的数据库驱动 [%s]，可用：mysql / pgsql / sqlite，或通过 extend() 注册',
                $driver
            )),
        };
    }

    /**
     * 解析 SQLite 路径（相对路径基于项目根）。
     *
     * @param string $database 数据库路径
     * @return string 绝对路径或 :memory:
     */
    private function resolveSqlitePath(string $database): string
    {
        if ($database === ':memory:' || str_starts_with($database, '/') || preg_match('/^[A-Za-z]:/', $database) === 1) {
            return $database;
        }

        return $this->basePath === '' ? $database : $this->basePath . '/' . ltrim($database, '/');
    }

    /**
     * 构建 PDO 选项。
     *
     * @param array<string, mixed> $config 连接配置
     * @return array<int, mixed> PDO 选项
     */
    private function buildOptions(array $config): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        foreach ((array) ($config['options'] ?? []) as $key => $value) {
            $options[(int) $key] = $value;
        }

        return $options;
    }
}
