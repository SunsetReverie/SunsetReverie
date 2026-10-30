<?php

declare(strict_types=1);

namespace SunsetReverie\Console;

use Closure;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * 定时任务表。
 *
 * 只负责「注册任务」与「判断是否到期」两件事——**不执行、不加锁**。
 * 执行与锁留在 ConsoleKernel（组装层），因为那需要缓存组件，
 * 而 core/Console 不允许依赖组件（见 ARCH-4）。
 *
 * 业务侧用法（`app/Console/Schedule.php`）：
 *
 *   return function (Schedule $schedule): void {
 *       $schedule->call('publish-scheduled-posts', 'everyMinute', function (): void {
 *           // 到点要执行的逻辑
 *       });
 *   };
 *
 * 关于「执行命令」类任务：不提供 `->command(...)`。
 * 执行命令需要 ConsoleKernel（组装层），本类在 core/Console 下不能反向依赖。
 * 需要时用 call() 包一层，逻辑更显式。
 */
final class Schedule
{
    /** @var list<array{name: string, cron: string, callback: Closure}> 已注册任务 */
    private array $tasks = [];

    /**
     * 注册闭包任务。
     *
     * @param string $name 任务名（同时用于日志与分布式锁键）
     * @param string $frequency 频率（everyMinute / hourly / daily 或 5 段 cron）
     * @param Closure $callback 任务体
     * @return self 当前实例
     * @throws InvalidArgumentException 名字为空或重复、频率无法识别时抛出
     */
    public function call(string $name, string $frequency, Closure $callback): self
    {
        if ($name === '') {
            throw new InvalidArgumentException('定时任务必须有名字（用于日志与锁）');
        }

        foreach ($this->tasks as $task) {
            if ($task['name'] === $name) {
                throw new InvalidArgumentException(sprintf('定时任务名重复：[%s]', $name));
            }
        }

        $this->tasks[] = [
            'name' => $name,
            'cron' => $this->normalize($frequency),
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * 全部任务。
     *
     * @return list<array{name: string, cron: string, callback: Closure}> 任务列表
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * 判断任务在这一分钟是否应当执行。
     *
     * @param array{name: string, cron: string, callback: Closure} $task 任务
     * @param DateTimeInterface $now 当前时间
     * @return bool 应执行返回 true
     */
    public static function isDue(array $task, DateTimeInterface $now): bool
    {
        $fields = preg_split('/\s+/', trim($task['cron'])) ?: [];

        if (count($fields) !== 5) {
            return false;
        }

        [$minute, $hour, $day, $month, $weekday] = $fields;

        return self::matches($minute, (int) $now->format('i'))
            && self::matches($hour, (int) $now->format('G'))
            && self::matches($day, (int) $now->format('j'))
            && self::matches($month, (int) $now->format('n'))
            && self::matches($weekday, (int) $now->format('w'));
    }

    /**
     * 把常用频率翻译成 cron 表达式。
     *
     * @param string $frequency 频率
     * @return string 5 段 cron
     * @throws InvalidArgumentException 无法识别时抛出
     */
    public function normalize(string $frequency): string
    {
        $map = [
            'everyMinute' => '* * * * *',
            'everyFiveMinutes' => '*/5 * * * *',
            'everyTenMinutes' => '*/10 * * * *',
            'everyFifteenMinutes' => '*/15 * * * *',
            'everyThirtyMinutes' => '0,30 * * * *',
            'hourly' => '0 * * * *',
            'daily' => '0 0 * * *',
            'weekly' => '0 0 * * 0',
            'monthly' => '0 0 1 * *',
        ];

        if (isset($map[$frequency])) {
            return $map[$frequency];
        }

        // dailyAt('03:30')
        if (preg_match("/^dailyAt\\(['\"]?(\\d{1,2}):(\\d{2})['\"]?\\)$/", $frequency, $m) === 1) {
            return sprintf('%d %d * * *', (int) $m[2], (int) $m[1]);
        }

        // hourlyAt(15)
        if (preg_match('/^hourlyAt\((\d{1,2})\)$/', $frequency, $m) === 1) {
            return sprintf('%d * * * *', (int) $m[1]);
        }

        // 直接给 5 段 cron
        if (count(preg_split('/\s+/', trim($frequency)) ?: []) === 5) {
            return trim($frequency);
        }

        throw new InvalidArgumentException(sprintf(
            '无法识别的频率 [%s]，可用：everyMinute / everyFiveMinutes / everyTenMinutes / '
            . 'everyFifteenMinutes / everyThirtyMinutes / hourly / daily / weekly / monthly / '
            . "dailyAt('03:30') / hourlyAt(15) / 5 段 cron 表达式",
            $frequency
        ));
    }

    /**
     * 单个字段是否匹配。
     *
     * 支持：通配、具体值、范围（1-5）、列表（1,3,5）、步长（星号斜杠 N）、
     * 以及范围加步长（2-20/3）。
     *
     * @param string $field 字段表达式
     * @param int $value 待匹配值
     * @return bool 匹配返回 true
     */
    private static function matches(string $field, int $value): bool
    {
        if ($field === '*') {
            return true;
        }

        foreach (explode(',', $field) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            // 步长：*/5 或 2-20/3
            if (str_contains($part, '/')) {
                [$range, $step] = explode('/', $part, 2);
                $step = (int) $step;

                if ($step <= 0) {
                    continue;
                }

                $start = 0;
                $end = PHP_INT_MAX;

                if ($range !== '*') {
                    if (str_contains($range, '-')) {
                        [$from, $to] = explode('-', $range, 2);
                        $start = (int) $from;
                        $end = (int) $to;
                    } else {
                        $start = (int) $range;
                    }
                }

                if ($value >= $start && $value <= $end && ($value - $start) % $step === 0) {
                    return true;
                }

                continue;
            }

            // 范围：1-5
            if (str_contains($part, '-')) {
                [$from, $to] = explode('-', $part, 2);

                if ($value >= (int) $from && $value <= (int) $to) {
                    return true;
                }

                continue;
            }

            if ((int) $part === $value) {
                return true;
            }
        }

        return false;
    }
}
