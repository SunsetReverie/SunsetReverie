<?php

declare(strict_types=1);

/*
 * 邮件配置。
 *
 * MAIL_MAILER=log    → 只写日志，不发真邮件（本地开发默认）
 * MAIL_MAILER=smtp   → 真实发送（明文 SMTP，需内网 relay 或 mailpit）
 *
 * 注意：框架的 SMTP 实现**不支持 TLS**。需要加密时先部署本地 relay
 * （如 postfix / mailpit）由它转发，或自行实现 MailerInterface。
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
        ],
    ],
];
