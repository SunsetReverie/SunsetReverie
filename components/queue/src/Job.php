<?php

declare(strict_types=1);

namespace SunsetReverie\Queue;

use Throwable;

/**
 * 队列任务接口。
 *
 * 任务实例会被序列化进存储（JSON），因此：
 * - **不能持有闭包、资源、连接**（序列化会失败或恢复后不可用）
 * - 构造函数只接受标量/数组
 * - 需要数据库连接时在 handle() 里从容器取
 */
interface Job
{
    /**
     * 执行任务。
     *
     * 抛异常即视为失败，由 Worker 决定重试还是进死信。
     *
     * @return void
     * @throws Throwable 任务失败时抛出
     */
    public function handle(): void;

    /**
     * 任务最终失败（重试耗尽）后的回调。
     *
     * 默认空实现；需要落审计、发告警时覆盖它。
     *
     * @param Throwable $exception 最后一次异常
     * @return void
     */
    public function failed(Throwable $exception): void;

    /**
     * 最大尝试次数（含首次）。
     *
     * @return int 尝试次数
     */
    public function tries(): int;

    /**
     * 重试退避秒数。
     *
     * @param int $attempt 第几次失败（从 1 开始）
     * @return int 等待秒数
     */
    public function backoff(int $attempt): int;
}
