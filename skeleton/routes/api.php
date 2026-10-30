<?php

declare(strict_types=1);

/*
 * 路由定义。
 *
 * 两种写法等价，可混用：
 *   1. 门面：Route::get('/x', ...)         —— 与文档示例一致
 *   2. 注入：$router->get('/x', ...)       —— 依赖显式，便于单测
 */

use App\Controller\HomeController;
use SunsetReverie\Support\Facades\Route;

return function (): void {
    Route::get('/', [HomeController::class, 'index'], 'home');
};
