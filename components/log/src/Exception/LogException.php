<?php

declare(strict_types=1);

namespace SunsetReverie\Log\Exception;

use RuntimeException;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 日志异常：目录不可写、文件写入失败、通道配置错误。
 */
final class LogException extends HttpException
{
    /**
     * @param string $message 异常消息
     */
    public function __construct(string $message)
    {
        parent::__construct(500, ErrorCode::InternalError->value, $message);
    }

}
