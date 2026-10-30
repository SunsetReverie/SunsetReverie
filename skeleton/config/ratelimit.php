<?php

declare(strict_types=1);

/*
 * 限流配置。
 *
 * 计数复用缓存驱动（默认 file）；跨机部署时请把 store 指向 redis 等共享驱动。
 */

return [
    'enabled' => env('RATELIMIT_ENABLED', true),

    // 使用的缓存驱动名；留空表示用 cache.default
    'store' => env('RATELIMIT_STORE', ''),

    // 键前缀（多项目共用缓存时用于隔离）
    'prefix' => 'rl:',

    // 默认限流维度：ip（IP + 路由）/ route（仅路由，全局共享）/ global
    'by' => 'ip',

    // 默认规则
    'default' => [
        'max_attempts' => 60,
        'decay_seconds' => 60,
    ],

    // 按路由名覆盖规则
    // - 精确匹配优先：'admin.v1.auth.login'
    // - 支持前缀通配：'admin.*'、'admin.v1.auth.*'（多个命中时取最长）
    'routes' => [
        // 'admin.v1.auth.login' => ['max_attempts' => 5, 'decay_seconds' => 60],
        // 'admin.*' => ['max_attempts' => 120, 'decay_seconds' => 60],
    ],
];
