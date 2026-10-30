<?php

declare(strict_types=1);

/*
 * 上传配置。
 *
 * 安全默认值：
 * - 只允许图片与 PDF；
 * - 落盘目录在 public 之外（storage/uploads），由 Web 服务器 alias 暴露；
 * - 文件名由服务端生成，客户端文件名不参与路径。
 */

return [
    'disk' => env('UPLOAD_DISK', 'local'),

    // 单文件最大字节数（默认 10 MB）
    'max_size' => (int) env('UPLOAD_MAX_SIZE', 10485760),

    // 真实 MIME 白名单（基于文件内容检测，不看客户端声明）
    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ],

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => 'storage/uploads',
            'url_prefix' => '/uploads',
        ],
    ],

    // 私有文件签名 URL 的密钥；为空时签名功能不可用
    'sign_secret' => env('UPLOAD_SIGN_SECRET', ''),
];
