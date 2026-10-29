<?php

declare(strict_types=1);

namespace SunsetReverie\Routing\Exception;

use RuntimeException;

/**
 * 重复路由异常：同一「方法 + 路径」被注册两次。
 *
 * 注册期即失败，避免运行期静默覆盖（见规划文档 7.3.1）。
 */
final class DuplicateRouteException extends RuntimeException
{
    /**
     * @param string $method HTTP 方法
     * @param string $uri 路由路径
     */
    public function __construct(string $method, string $uri)
    {
        parent::__construct(sprintf('路由重复注册：%s %s', $method, $uri));
    }
}
