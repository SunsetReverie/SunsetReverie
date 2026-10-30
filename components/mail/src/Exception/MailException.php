<?php

declare(strict_types=1);

namespace SunsetReverie\Mail\Exception;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 邮件异常。
 *
 * 继承 HttpException：同步发送失败时渲染为 500；
 * 队列里抛出时由 Worker 捕获并重试。
 */
final class MailException extends HttpException
{
    /**
     * @param string $message 异常消息
     */
    public function __construct(string $message)
    {
        parent::__construct(500, ErrorCode::InternalError->value, $message);
    }
}
