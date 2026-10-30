<?php

declare(strict_types=1);

/*
 * API 域配置。
 *
 * 单域项目：保持 domains 为空数组，路由写 routes/api.php 即可。
 * 多域项目：在 domains 中声明，每个域一个入口文件 routes/{domain}.php。
 */

return [
    // 未匹配到具体域时使用
    'default' => env('API_DEFAULT_DOMAIN', 'web'),

    // 是否允许用 X-Api-Domain 请求头决定域（仅当有可信网关时开启）
    'allow_header_detection' => false,

    // 路由组织模式：single（每域一个文件）/ directory（每域入口 + 版本子目录）
    'routing_mode' => env('API_ROUTING_MODE', 'single'),

    // 域定义；留空表示单域模式（路由写在 routes/api.php）
    'domains' => [
        // 'admin' => [
        //     'hosts' => ['admin-api.example.com'],
        //     'prefix' => 'admin',
        //     'guard' => 'jwt',
        //     'versions' => ['v1'],
        //     'middleware' => [],
        //     'error_segment' => 51,
        // ],
        // 'web' => [
        //     'prefix' => 'web',
        //     'versions' => ['v1'],
        // ],
    ],
];
