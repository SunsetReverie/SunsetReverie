<?php

declare(strict_types=1);

namespace SunsetReverie\Cache\Exception;

use RuntimeException;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 缓存异常：目录不可写、驱动缺失、序列化失败。
 */
final class CacheException extends HttpException
{
    /**
     * @param string $message 异常消息
     */
    public function __construct(string $message)
    {
        parent::__construct(500, ErrorCode::CacheError->value, $message);
    }

}
