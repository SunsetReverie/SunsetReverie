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

    /*
     * 用内容哈希做文件名（去重 + 秒传）。
     *
     * 开启后：同一内容永远落在同一路径，`store()` 会自动跳过重复写入——
     * 不需要额外的 hash → path 索引表。
     *
     * 好处：同内容同 URL，CDN 与浏览器缓存直接命中；换机器不产生孤儿副本。
     * 代价：删一个文件可能影响多个引用方（图床通常做引用计数，或者干脆不删）。
     *
     * 图床类项目建议开；普通业务（头像、附件）保持默认的随机文件名即可。
     */
    'content_addressed' => (bool) env('UPLOAD_CONTENT_ADDRESSED', false),

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

    // 分片上传（见 7.10.4）
    'chunk' => [
        'chunk_size' => (int) env('UPLOAD_CHUNK_SIZE', 5 * 1024 * 1024),
        'min_chunk_size' => 1024 * 1024,
        'max_chunk_size' => 16 * 1024 * 1024,
        // 未完成会话的保留时长（秒）
        'ttl' => 86400,
    ],
];
