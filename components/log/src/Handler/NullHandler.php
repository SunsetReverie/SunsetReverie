<?php

declare(strict_types=1);

namespace SunsetReverie\Log\Handler;

use SunsetReverie\Log\Level;

/**
 * 空处理器：丢弃所有日志（测试或明确关闭日志时使用）。
 */
final class NullHandler implements HandlerInterface
{
    /**
     * {@inheritDoc}
     */
    public function isHandling(Level $level): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Level $level, string $message, array $context): void
    {
    }
}
