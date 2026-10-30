<?php

declare(strict_types=1);

namespace SunsetReverie\Log\Formatter;

use SunsetReverie\Log\Level;

/**
 * 单行文本格式化器（开发环境更易读）。
 *
 * 输出形如：
 * [2026-10-30 10:00:00] app.INFO: 用户登录 {"user_id":42,"request_id":"01a1..."}
 */
final class LineFormatter implements FormatterInterface
{
    /**
     * {@inheritDoc}
     */
    public function format(Level $level, string $message, array $context, string $channel): string
    {
        $line = sprintf(
            '[%s] %s.%s: %s',
            date('Y-m-d H:i:s'),
            $channel,
            strtoupper($level->value),
            $message
        );

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($json !== false && $json !== '[]') {
                $line .= ' ' . $json;
            }
        }

        // 单行约束：消息里若有换行，压缩为空格，避免破坏日志行结构
        return str_replace(["\r\n", "\n", "\r"], ' ', $line);
    }
}
