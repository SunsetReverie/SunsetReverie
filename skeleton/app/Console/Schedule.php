<?php

declare(strict_types=1);

/*
 * 定时任务注册表。
 *
 * 由系统 cron 每分钟驱动一次：
 *
 *   * * * * * cd /path/to/project && php bin/sr schedule:run >> /dev/null 2>&1
 *
 * 频率写法：everyMinute / everyFiveMinutes / everyTenMinutes /
 *          everyFifteenMinutes / everyThirtyMinutes / hourly /
 *          daily / dailyAt('03:30') / weekly / monthly / hourlyAt(15) /
 *          或直接写 5 段 cron 表达式（如每 5 分钟）。
 */

use SunsetReverie\Console\Schedule;

return function (Schedule $schedule): void {
    // 示例：每分钟执行一次
    // $schedule->call('example-task', 'everyMinute', static function (): void {
    //     // 到点要执行的逻辑
    // });

    // 示例：每天凌晨 3:30
    // $schedule->call('daily-report', "dailyAt('03:30')", static function (): void {
    // });
};
