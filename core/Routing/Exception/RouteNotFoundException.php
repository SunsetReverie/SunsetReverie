<?php

declare(strict_types=1);

namespace SunsetReverie\Routing\Exception;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 路由未命中（404）。
 */
final class RouteNotFoundException extends HttpException
{
    /**
     * @param string $message 面向用户的消息
     */
    public function __construct(string $message = '接口不存在')
    {
        parent::__construct(404, ErrorCode::RouteNotFound->value, $message);
    }
}
