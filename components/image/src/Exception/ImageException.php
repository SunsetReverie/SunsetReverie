<?php

declare(strict_types=1);

namespace SunsetReverie\Image\Exception;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 图片处理异常。
 *
 * 继承 HttpException：用户传了坏图应得到 422，
 * 服务端处理失败（内存不足、扩展缺失）应得到 500。
 */
final class ImageException extends HttpException
{
    /**
     * @param string $message 异常消息
     * @param int $status HTTP 状态码
     */
    public function __construct(string $message, int $status = 500)
    {
        parent::__construct(
            $status,
            $status === 422 ? ErrorCode::ValidationFailed->value : ErrorCode::InternalError->value,
            $message
        );
    }
}
