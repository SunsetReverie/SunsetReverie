<?php

declare(strict_types=1);

/*
 * 邮件配置。
 *
 * MAIL_MAILER=log    → 只写日志，不发真邮件（本地开发默认）
 * MAIL_MAILER=smtp   → 真实发送
 *
 * MAIL_ENCRYPTION 决定连接方式：
 *
 *   （空）    明文，25 端口，或内网 relay / mailpit（1025）
 *   tls       STARTTLS，587 端口
 *   ssl       隐式 TLS（SMTPS），465 端口
 *
 * 主流服务商的端口对照：
 *
 *   QQ 邮箱        smtp.qq.com:465（ssl）或 :587（tls）
 *   163 邮箱       smtp.163.com:465（ssl）
 *   Gmail         smtp.gmail.com:465（ssl）或 :587（tls）
 *   阿里云邮件推送 smtpdm.aliyun.com:465（ssl）或 :80（HTTP API）
 *
 * 注意：云服务器（阿里云 / 腾讯云）默认封禁出站 25 端口，
 * 且 465/587 需要 MAIL_ENCRYPTION 与之匹配，否则会握手失败。
 *
 * 自签证书的内网 relay 才需要 MAIL_MAILER_VERIFY_PEER=false——
 * 关掉等于放弃中间人防护，公网服务绝对不要关。
 */
return [
    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [
        'log' => [
            'driver' => 'log',
        ],

        'smtp' => [
            'driver' => 'smtp',
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => (int) env('MAIL_PORT', 1025),
            'username' => env('MAIL_USERNAME', ''),
            'password' => env('MAIL_PASSWORD', ''),
            'from' => env('MAIL_FROM', 'noreply@xi.blog'),
            'from_name' => env('MAIL_FROM_NAME', '夕梦博客'),
            'timeout' => (int) env('MAIL_TIMEOUT', 10),

            // 空字符串会被归一成 null（明文）；可选 tls / ssl
            'encryption' => env('MAIL_ENCRYPTION', ''),

            // 只有内网自签证书才设为 false
            'verify_peer' => (bool) env('MAIL_VERIFY_PEER', true),
        ],
    ],
];
