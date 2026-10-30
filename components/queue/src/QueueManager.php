<?php

declare(strict_types=1);

namespace SunsetReverie\Queue;

use Closure;
use SunsetReverie\Database\ConnectionManager;
use SunsetReverie\Queue\Driver\DatabaseDriver;
use SunsetReverie\Queue\Driver\QueueDriverInterface;
use SunsetReverie\Queue\Driver\SyncDriver;
use SunsetReverie\Queue\Exception\QueueException;

/**
 * 队列管理器。
 *
 * 默认队列名从 `queue.default` 读；驱动从 `queue.drivers.{name}` 读。
 * 未配置时用 `database` 驱动（项目里几乎一定有数据库，零额外依赖）。
 */
final class QueueManager
{
    /** @var array<string, QueueDriverInterface> 已创建的驱动 */
    private array $drivers = [];

    /**
     * @param array<string, mixed> $config 队列配置
     * @param ConnectionManager $connections 数据库连接（database 驱动使用）
     * @param Closure(array{class: string, data: array<string, mixed>}): void $syncRunner 同步驱动的执行器
     */
    public function __construct(
        private readonly array $config,
        private readonly ConnectionManager $connections,
        private readonly Closure $syncRunner,
    ) {
    }

    /**
     * 默认队列名。
     *
     * @return string 队列名
     */
    public function defaultQueue(): string
    {
        return (string) ($this->config['default_queue'] ?? 'default');
    }

    /**
     * 取驱动。
     *
     * @param string|null $name 驱动名（null 表示配置的默认）
     * @return QueueDriverInterface 驱动
     * @throws QueueException 驱动不存在时抛出
     */
    public function driver(?string $name = null): QueueDriverInterface
    {
        $name ??= (string) ($this->config['driver'] ?? 'database');

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        $driverConfig = (array) ($this->config['drivers'][$name] ?? []);

        $this->drivers[$name] = match ($name) {
            'database' => new DatabaseDriver(
                $this->connections->connection($driverConfig['connection'] ?? null),
                (string) ($driverConfig['jobs_table'] ?? 'jobs'),
                (string) ($driverConfig['failed_table'] ?? 'failed_jobs'),
            ),
            'sync' => new SyncDriver($this->syncRunner),
            default => throw new QueueException(sprintf(
                '不支持的队列驱动 [%s]，可用：database / sync，或通过 extend() 注册',
                $name
            )),
        };

        return $this->drivers[$name];
    }

    /**
     * 入队。
     *
     * @param Job $job 任务
     * @param int $delaySeconds 延迟秒数
     * @param string|null $queue 队列名（null 表示默认）
     * @return string 任务 ID
     */
    public function push(Job $job, int $delaySeconds = 0, ?string $queue = null): string
    {
        return $this->driver()->push(
            $queue ?? $this->defaultQueue(),
            // 序列化对象属性即可：任务类的 public 属性 + 构造函数参数（命名参数恢复）
            ['class' => $job::class, 'data' => get_object_vars($job)],
            $delaySeconds
        );
    }

    /**
     * 注册自定义驱动。
     *
     * @param string $name 驱动名
     * @param Closure(array<string, mixed>): QueueDriverInterface $factory 工厂
     * @return self 当前实例
     */
    public function extend(string $name, Closure $factory): self
    {
        $this->drivers[$name] = $factory((array) ($this->config['drivers'][$name] ?? []));

        return $this;
    }
}
