<?php

declare(strict_types=1);

namespace SunsetReverie\Routing\Exception;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 路径存在但方法不被允许（405），需在响应头回写 Allow。
 */
final class MethodNotAllowedException extends HttpException
{
    /**
     * @param list<string> $allowed 该路径允许的方法
     */
    public function __construct(public readonly array $allowed)
    {
        parent::__construct(
            405,
            ErrorCode::MethodNotAllowed->value,
            '请求方法不被允许',
        );
    }
}
