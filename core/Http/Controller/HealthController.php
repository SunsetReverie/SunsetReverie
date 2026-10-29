<?php

declare(strict_types=1);

namespace SunsetReverie\Http\Controller;

use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;

/**
 * 健康检查控制器。
 *
 * 使用控制器方法（而非闭包）以便进入路由缓存（见规划文档 7.3.5）。
 */
final class HealthController
{
    /**
     * 返回服务健康状态。
     *
     * @param Request $request 当前请求
     * @return array<string, mixed> 健康信息
     */
    public function index(Request $request): array
    {
        return [
            'status' => 'healthy',
            'version' => Application::VERSION,
            'time' => date(DATE_ATOM),
        ];
    }
}
