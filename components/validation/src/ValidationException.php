<?php

declare(strict_types=1);

namespace SunsetReverie\Validation;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 校验失败异常：统一转成 422 + 字段级错误。
 *
 * 由统一异常处理（ErrorHandler）渲染为：
 * {"code":30001,"message":"参数校验失败","errors":{"field":["..."]}}
 */
final class ValidationException extends HttpException
{
    /**
     * @param array<string, list<string>> $errors 字段 => 错误消息列表
     */
    public function __construct(array $errors)
    {
        parent::__construct(
            422,
            ErrorCode::ValidationFailed->value,
            ErrorCode::ValidationFailed->message(),
            $errors,
        );
    }
}
