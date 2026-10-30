<?php

declare(strict_types=1);

namespace SunsetReverie\Queue\Driver;

use Throwable;

/**
 * 同步驱动：入队即刻执行。
 *
 * 用途只有两个：
 * 1. 测试——不想为每个用例起 worker
 * 2. 本地调试——想立刻看到效果
 *
 * **不要在生产环境用它**：请求会被任务阻塞，
 * 而队列存在的意义恰恰是"不阻塞"。
 */
final class SyncDriver implements QueueDriverInterface
{
    /** @var callable(array{class: string, data: array<string, mixed>}): void 执行器 */
    private $runner;

    /** @var list<array{id: string, queue: string, payload: array<string, mixed>}> 失败记录 */
    private array $failed = [];

    /**
     * @param callable(array{class: string, data: array<string, mixed>}): void $runner 执行回调
     */
    public function __construct(callable $runner)
    {
        $this->runner = $runner;
    }

    /**
     * {@inheritDoc}
     */
    public function push(string $queue, array $payload, int $delaySeconds = 0): string
    {
        $id = bin2hex(random_bytes(8));

        // 延迟语义在同步驱动下无法表达，直接执行（测试里应避免依赖延迟）
        try {
            ($this->runner)($payload);
        } catch (Throwable $e) {
            $this->failed[] = ['id' => $id, 'queue' => $queue, 'payload' => $payload];

            throw $e;
        }

        return $id;
    }

    /**
     * {@inheritDoc}
     */
    public function pop(string $queue): ?array
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function release(string $id, int $delaySeconds): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $id): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function fail(string $id, string $reason, string $queue, array $payload): void
    {
        $this->failed[] = ['id' => $id, 'queue' => $queue, 'payload' => $payload];
    }

    /**
     * {@inheritDoc}
     */
    public function size(string $queue): int
    {
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(string $queue): int
    {
        return 0;
    }

    /**
     * 失败记录（测试断言用）。
     *
     * @return list<array{id: string, queue: string, payload: array<string, mixed>}> 失败列表
     */
    public function failures(): array
    {
        return $this->failed;
    }
}
