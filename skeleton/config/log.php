<?php

declare(strict_types=1);

/*
 * 日志配置。
 *
 * 生产建议：daily（按天切分）或 stderr（容器环境）；
 * 开发环境可把 formatter 改为 line，肉眼更易读。
 */

return [
    'default' => env('LOG_CHANNEL', 'daily'),

    // 全局最低级别（各通道可覆盖）
    'level' => env('LOG_LEVEL', 'debug'),

    // 是否记录访问日志（每个请求一条）
    'access_log' => env('LOG_ACCESS', true),

    'channels' => [
        // 单文件：所有级别写进同一个文件
        'single' => [
            'driver' => 'single',
            'path' => 'storage/logs/app.log',
            'level' => env('LOG_LEVEL', 'debug'),
            'formatter' => 'json',
        ],

        // 按天切分：storage/logs/app-2026-10-30.log
        'daily' => [
            'driver' => 'daily',
            'path' => 'storage/logs',
            'level' => env('LOG_LEVEL', 'debug'),
            'formatter' => 'json',
            'days' => (int) env('LOG_DAYS', 14),
        ],

        // 容器环境：交给运行时采集
        'stderr' => [
            'driver' => 'stderr',
            'level' => env('LOG_LEVEL', 'debug'),
            'formatter' => 'json',
        ],

        // 测试或明确关闭
        'null' => [
            'driver' => 'null',
        ],

        // 聚合：同时写文件与 stderr
        // 'stack' => [
        //     'driver' => 'stack',
        //     'channels' => ['daily', 'stderr'],
        // ],
    ],
];
