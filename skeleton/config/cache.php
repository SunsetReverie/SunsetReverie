<?php

declare(strict_types=1);

/*
 * 缓存配置。
 *
 * array 驱动仅进程内有效（测试用）；file 驱动单机可用；跨机共享需扩展 redis 等驱动。
 */

return [
    'default' => env('CACHE_DRIVER', 'file'),

    // 键前缀：同一台机器上跑多个项目时避免互相覆盖
    'prefix' => env('CACHE_PREFIX', 'sr:'),

    // 默认存活秒数（0 表示永久）
    'ttl' => (int) env('CACHE_TTL', 3600),

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],

        'file' => [
            'driver' => 'file',
            'path' => 'storage/cache',
        ],
    ],
];
