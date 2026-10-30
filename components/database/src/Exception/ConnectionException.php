<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Exception;

use RuntimeException;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 连接异常：配置缺失、驱动不支持、连接失败。
 */
final class ConnectionException extends HttpException
{
    /**
     * @param string $message 异常消息
     * @param \Throwable|null $previous 原始异常（保留异常链，便于日志定位）
     */
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(500, ErrorCode::DatabaseError->value, $message, null, $previous);
    }

}
