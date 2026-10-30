<?php

declare(strict_types=1);

namespace SunsetReverie\Api\Exception;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;

/**
 * 域配置异常：域未定义、默认域非法、前缀重复。
 *
 * 属于启动期错误，直接阻断引导，不做静默回退。
 */
final class DomainNotFoundException extends HttpException
{
    /**
     * @param string $message 异常消息
     */
    public function __construct(string $message)
    {
        // 启动期抛出时被 CLI/引导层捕获；若在 HTTP 请求期（如按域取配置）则渲染为 404
        parent::__construct(404, ErrorCode::DomainNotFound->value, $message);
    }
}
