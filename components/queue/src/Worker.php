<?php

declare(strict_types=1);

namespace SunsetReverie\Queue;

use SunsetReverie\Queue\Driver\QueueDriverInterface;
use Throwable;

/**
 * 队列消费者。
 *
 * 任务恢复方式：`new $class(...$data)`（PHP 8 命名参数）。
 * 因此任务类只需要「构造函数参数 + public readonly 属性 + handle()」，
 * 不必实现额外的序列化方法。
 */
final class Worker
{
    /** 任务无法实例化时的默认最大尝试次数 */
    private const DEFAULT_TRIES = 3;

    /**
     * @param QueueDriverInterface $driver 队列驱动
     * @param string $queue 队列名
     * @param int $sleepSeconds 空队列时的休眠秒数
     */
    public function __construct(
        private readonly QueueDriverInterface $driver,
        private readonly string $queue,
        private readonly int $sleepSeconds = 1,
    ) {
    }

    /**
     * 常驻消费（直到中断或达到 maxJobs）。
     *
     * 用 Generator 而不是内部打印：Worker 负责"节奏"（空队列休眠多久），
     * 输出形态交给调用方。之前的实现把 sleep(1) 硬编码在命令层，
     * 导致构造函数里的 $sleepSeconds 完全没生效——传了也没用。
     *
     * @param int $maxJobs 最多处理多少个任务（0 表示不限）
     * @return \Generator<int, string> 每处理完一个任务产出一条描述
     */
    public function work(int $maxJobs = 0): \Generator
    {
        $handled = 0;

        while (true) {
            $result = $this->processOne();

            if ($result === null) {
                sleep(max(1, $this->sleepSeconds));

                continue;
            }

            yield $result;

            $handled++;

            if ($maxJobs > 0 && $handled >= $maxJobs) {
                return;
            }
        }
    }

    /**
     * 处理一个任务。
     *
     * @return string|null 结果描述（无任务时 null）
     */
    public function processOne(): ?string
    {
        $job = $this->driver->pop($this->queue);

        if ($job === null) {
            return null;
        }

        $class = $job['payload']['class'];
        $data = $job['payload']['data'];
        $attempts = $job['attempts'];

        $instance = null;

        try {
            if (!class_exists($class)) {
                throw new \RuntimeException(sprintf('任务类不存在：%s', $class));
            }

            /** @var Job $instance */
            $instance = new $class(...$data);
            $instance->handle();

            $this->driver->delete($job['id']);

            return sprintf('%s 成功', $this->shortName($class));
        } catch (Throwable $e) {
            $maxTries = $instance instanceof Job ? max(1, $instance->tries()) : self::DEFAULT_TRIES;

            if ($attempts >= $maxTries) {
                $this->driver->fail($job['id'], $e::class . ': ' . $e->getMessage(), $this->queue, $job['payload']);

                // 最终失败回调：落审计、发告警
                if ($instance instanceof Job) {
                    try {
                        $instance->failed($e);
                    } catch (Throwable) {
                        // 失败回调自身出错不应影响死信记录
                    }
                }

                return sprintf('%s 最终失败（%d 次）：%s', $this->shortName($class), $attempts, $e->getMessage());
            }

            $delay = $instance instanceof Job ? max(0, $instance->backoff($attempts)) : 60;
            $this->driver->release($job['id'], $delay);

            return sprintf(
                '%s 第 %d 次失败，%d 秒后重试：%s',
                $this->shortName($class),
                $attempts,
                $delay,
                $e->getMessage()
            );
        }
    }

    /**
     * 处理到队列为空为止（适合 cron 驱动）。
     *
     * @param int $maxJobs 最多处理多少个（0 表示不限制）
     * @return list<string> 处理记录
     */
    public function workUntilEmpty(int $maxJobs = 0): array
    {
        $log = [];
        $handled = 0;

        while ($maxJobs === 0 || $handled < $maxJobs) {
            $result = $this->processOne();

            if ($result === null) {
                break;
            }

            $log[] = $result;
            $handled++;
        }

        return $log;
    }

    /**
     * 类名简写（日志用）。
     *
     * @param string $class 完整类名
     * @return string 简写
     */
    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
