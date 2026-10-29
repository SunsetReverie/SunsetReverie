<?php

declare(strict_types=1);

/*
 * 路由定义。
 *
 * 文件返回一个闭包，由框架在启动时调用并注入 Router 实例——
 * 不使用全局门面，避免隐式全局状态。
 */

use App\Controller\HomeController;
use SunsetReverie\Routing\Router;

return function (Router $router): void {
    $router->get('/', [HomeController::class, 'index'], 'home');
};
