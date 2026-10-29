<?php

declare(strict_types=1);

namespace SunsetReverie\Auth\Exception;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 认证失败异常（401）。
 *
 * 默认使用 20001（未认证）；Token 过期场景传 20002。
 */
final class AuthenticationException extends HttpException
{
    /**
     * @param string $message 面向用户的消息
     * @param int $code 业务错误码（20001 / 20002 / 20010）
     */
    public function __construct(string $message = '请先登录', int $code = 20001)
    {
        parent::__construct(401, $code ?: ErrorCode::Unauthenticated->value, $message);
    }
}
