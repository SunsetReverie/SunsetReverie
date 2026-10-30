<?php

declare(strict_types=1);

namespace SunsetReverie\Config\Exception;

/**
 * .env 解析失败：携带行号，便于定位。
 *
 * 属性名故意不叫 `file` / `line`：Exception 基类已用这两个名字
 * 存「抛出位置」，覆盖成 readonly 会让 getFile() / getLine()
 * 返回的是 .env 文件与行号，而不是异常真正抛出的位置——排查时会被误导。
 */
final class EnvParseException extends ConfigException
{
    /**
     * @param string $message 错误描述
     * @param int $envLine 出错行号（从 1 开始）
     * @param string $envFile 文件路径
     */
    public function __construct(string $message, public readonly int $envLine, public readonly string $envFile)
    {
        parent::__construct(sprintf('%s（%s 第 %d 行）', $message, $envFile, $envLine));
    }
}
