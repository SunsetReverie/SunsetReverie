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

            /*
             * access token 有效期（秒）。
             *
             * 短一点更安全（泄露后窗口小），但要配合 refresh 才不会频繁登录。
             * 默认 2 小时。
             */
            'ttl' => (int) env('JWT_TTL', 7200),

            /*
             * refresh token 有效期（秒），默认 30 天。
             *
             * 这个值决定了「多久不活跃就要重新登录」。
             * 需要把它存进缓存，所以 cache 驱动不能是 array（进程结束就没了）。
             */
            'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 2592000),

            /*
             * 并发刷新的宽限期（秒），0 表示关闭。
             *
             * 前端多个请求同时带着同一个 refresh token 来换新时，
             * 第一个成功后旧的就作废了，第二个会失败——用户被莫名踢下线。
             * 宽限期内重放会返回**同一对**新 Token，问题消失。
             */
            'refresh_grace' => (int) env('JWT_REFRESH_GRACE', 30),

            // 时间校验容差（秒），容忍客户端与服务端的时钟偏差
            'leeway' => 30,

            // 主体标识所在的声明名
            'claim_key' => 'sub',

            /*
             * 是否启用 token 版本号校验。
             *
             * 开启后：Auth::jwt()->revokeAll($userId) 能让该用户所有旧 Token 失效
             * （改密码、封号、强制下线的场景）。
             *
             * 代价：每个带认证的请求多一次缓存读取。默认关闭。
             */
            'enforce_version' => (bool) env('JWT_ENFORCE_VERSION', false),

            // 是否强制校验 aud（多域项目建议开启，防止 Token 跨域复用）
            'enforce_audience' => (bool) env('JWT_ENFORCE_AUDIENCE', true),
        ],
    ],
];
