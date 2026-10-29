<?php

declare(strict_types=1);

/*
 * 认证配置。
 *
 * 每个守卫独立密钥：不同 API 域使用不同 secret，Token 无法跨域复用。
 */

return [
    'default' => env('AUTH_GUARD', 'jwt'),

    'guards' => [
        'jwt' => [
            'driver' => 'jwt',
            // 至少 32 字节；用 sr jwt:secret 生成
            'secret' => env('JWT_SECRET', ''),
            // Token 有效期（秒）
            'ttl' => (int) env('JWT_TTL', 7200),
            // 时间校验容差（秒），容忍客户端与服务端的时钟偏差
            'leeway' => 30,
            // 主体标识所在的声明名
            'claim_key' => 'sub',
        ],
    ],
];
