<?php

declare(strict_types=1);

namespace App\Controller;

use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;

/**
 * 示例控制器：演示返回数组自动包装为统一响应。
 */
final class HomeController
{
    /**
     * 首页信息。
     *
     * @param Request $request 当前请求
     * @return array<string, mixed> 响应数据
     */
    public function index(Request $request): array
    {
        return [
            'name' => '{{APP_NAME}}',
            'framework' => 'SunsetReverie ' . Application::VERSION,
            'time' => date(DATE_ATOM),
        ];
    }
}
