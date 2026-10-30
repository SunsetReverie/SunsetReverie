<?php

declare(strict_types=1);

namespace SunsetReverie\Queue\Exception;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 队列异常。
 *
 * 继承 HttpException：队列故障在 HTTP 请求期（入队失败）应渲染为 500，
 * 而不是被当成未捕获异常泄露堆栈。
 */
final class QueueException extends HttpException
{
    /**
     * @param string $message 异常消息
     */
    public function __construct(string $message)
    {
        parent::__construct(500, ErrorCode::InternalError->value, $message);
    }
}
