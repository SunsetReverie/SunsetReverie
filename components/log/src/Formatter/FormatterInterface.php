<?php

declare(strict_types=1);

namespace SunsetReverie\Log\Formatter;

use SunsetReverie\Log\Level;

/**
 * 日志格式化契约：把一条记录转成一行文本（不含换行符）。
 */
interface FormatterInterface
{
    /**
     * 格式化一条日志。
     *
     * @param Level $level 级别
     * @param string $message 消息
     * @param array<string, mixed> $context 上下文
     * @param string $channel 通道名
     * @return string 单行文本
     */
    public function format(Level $level, string $message, array $context, string $channel): string;
}
