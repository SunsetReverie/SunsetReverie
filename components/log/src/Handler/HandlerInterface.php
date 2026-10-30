<?php

declare(strict_types=1);

namespace SunsetReverie\Log\Handler;

use SunsetReverie\Log\Level;

/**
 * 日志处理器契约：决定"写到哪、要不要写"。
 */
interface HandlerInterface
{
    /**
     * 判断是否处理该级别。
     *
     * @param Level $level 级别
     * @return bool 处理返回 true
     */
    public function isHandling(Level $level): bool;

    /**
     * 写出一条日志。
     *
     * @param Level $level 级别
     * @param string $message 消息
     * @param array<string, mixed> $context 上下文
     * @return void
     */
    public function handle(Level $level, string $message, array $context): void;
}
