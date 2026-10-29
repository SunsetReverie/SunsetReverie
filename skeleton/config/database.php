<?php

declare(strict_types=1);

/*
 * 数据库配置。
 *
 * 支持 mysql / pgsql / sqlite 三种驱动，连接为懒建立（首次使用才连）。
 */

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'app'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'app'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            // 独立变量：避免与 MySQL 的 DB_DATABASE 冲突（后者是库名，这里是文件路径）
            // 相对路径基于项目根解析
            'database' => env('DB_SQLITE_DATABASE', 'storage/database.sqlite'),
        ],
    ],
];
