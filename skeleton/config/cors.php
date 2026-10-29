<?php

declare(strict_types=1);

/*
 * 跨域配置。
 *
 * 默认 allowed_origins 为空数组 = 不启用 CORS（不外发任何 CORS 头）。
 * 这是安全默认值：需要跨域时必须显式列出前端域名，不要直接写 '*'。
 */

return [
    // 允许的前端来源，如 ['https://admin.example.com', 'https://app.example.com']
    'allowed_origins' => [],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Request-Id', 'Idempotency-Key'],

    // 允许前端 JS 读取的响应头
    'exposed_headers' => ['X-Request-Id', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After'],

    // 预检结果缓存秒数
    'max_age' => 86400,

    // 是否允许携带 Cookie；为 true 时 allowed_origins 不能包含 '*'
    'supports_credentials' => false,
];
