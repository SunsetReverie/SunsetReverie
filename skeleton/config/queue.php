<?php

declare(strict_types=1);

return [
    // 驱动：database（默认，零外部依赖）/ sync（同步执行，仅测试与本地调试用）
    'driver' => env('QUEUE_DRIVER', 'database'),

    // 默认队列名
    'default_queue' => env('QUEUE_NAME', 'default'),

    'drivers' => [
        'database' => [
            'connection' => env('QUEUE_DB_CONNECTION'),
            'jobs_table' => 'jobs',
            'failed_table' => 'failed_jobs',
        ],
        'sync' => [],
    ],
];
