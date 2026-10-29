<?php

declare(strict_types=1);

/*
 * 应用配置。
 *
 * 约定（见规划文档 5.3）：配置文件只做声明，禁止副作用（不连库、不读文件、不发请求）。
 */

return [
    'name' => env('APP_NAME', '{{APP_NAME}}'),
    'env' => env('APP_ENV', 'local'),
    'debug' => env('APP_DEBUG', false),
    'key' => env('APP_KEY', ''),
    'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),

    // 服务提供者：追加自定义 Provider 时写在这里（数组顺序即注册顺序）
    'providers' => [],

    // 全局中间件：null 表示使用框架默认（TraceRequest + SecurityHeaders）
    'middleware' => null,
];
