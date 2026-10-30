<?php

declare(strict_types=1);

namespace SunsetReverie\Log\Exception;

use RuntimeException;

/**
 * 日志异常：目录不可写、文件写入失败、通道配置错误。
 */
final class LogException extends RuntimeException
{
}
