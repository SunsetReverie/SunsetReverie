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
     */
    public function __construct(string $message)
    {
        parent::__construct(500, ErrorCode::DatabaseError->value, $message);
    }

}
