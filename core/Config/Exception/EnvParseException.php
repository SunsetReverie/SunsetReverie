<?php

declare(strict_types=1);

namespace SunsetReverie\Config\Exception;

/**
 * .env 解析失败：携带行号，便于定位。
 */
final class EnvParseException extends ConfigException
{
    /**
     * @param string $message 错误描述
     * @param int $line 出错行号（从 1 开始）
     * @param string $file 文件路径
     */
    public function __construct(string $message, public readonly int $line, public readonly string $file)
    {
        parent::__construct(sprintf('%s（%s 第 %d 行）', $message, $file, $line));
    }
}
