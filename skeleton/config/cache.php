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

        // Redis：多机部署首选；键前缀自动隔离，flush 不会影响同库其他应用
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', ''),
            'database' => (int) env('REDIS_DB', 0),
            // 连接超时（秒），避免 Redis 不可达时无界等待
            'timeout' => (float) env('REDIS_TIMEOUT', 1.5),
        ],

        // APCu：单机共享内存，零外部依赖；多机部署不适用
        'apcu' => [
            'driver' => 'apcu',
        ],

        'file' => [
            'driver' => 'file',
            'path' => 'storage/cache',
        ],
    ],
];
