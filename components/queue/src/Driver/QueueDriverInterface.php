<?php

declare(strict_types=1);

namespace SunsetReverie\Queue\Driver;

/**
 * 队列驱动接口。
 *
 * `pop()` 必须是**原子**的：多个 worker 并发取任务时，
 * 同一个任务只能被一个 worker 拿到，否则会重复执行。
 */
interface QueueDriverInterface
{
    /**
     * 入队。
     *
     * @param string $queue 队列名
     * @param array{class: string, data: array<string, mixed>} $payload 任务载荷
     * @param int $delaySeconds 延迟秒数（0 表示立即可用）
     * @return string 任务 ID
     */
    public function push(string $queue, array $payload, int $delaySeconds = 0): string;

    /**
     * 取出一个可执行任务（原子抢占）。
     *
     * @param string $queue 队列名
     * @return array{id: string, payload: array{class: string, data: array<string, mixed>}, attempts: int}|null 任务，无任务时 null
     */
    public function pop(string $queue): ?array;

    /**
     * 放回队列（等待重试）。
     *
     * @param string $id 任务 ID
     * @param int $delaySeconds 延迟秒数
     * @return void
     */
    public function release(string $id, int $delaySeconds): void;

    /**
     * 标记成功（删除任务）。
     *
     * @param string $id 任务 ID
     * @return void
     */
    public function delete(string $id): void;

    /**
     * 转入死信。
     *
     * @param string $id 任务 ID
     * @param string $reason 失败原因
     * @param string $queue 队列名
     * @param array<string, mixed> $payload 任务载荷
     * @return void
     */
    public function fail(string $id, string $reason, string $queue, array $payload): void;

    /**
     * 队列中待处理任务数。
     *
     * @param string $queue 队列名
     * @return int 数量
     */
    public function size(string $queue): int;

    /**
     * 清空队列（不含死信）。
     *
     * @param string $queue 队列名
     * @return int 删除数量
     */
    public function clear(string $queue): int;
}
