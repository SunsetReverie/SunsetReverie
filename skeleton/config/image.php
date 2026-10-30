<?php

declare(strict_types=1);

return [
    /*
     * 驱动：auto / gd / imagick
     *
     * auto：装了 Imagick 就用 Imagick，否则用 GD；两者都没有时明确报错。
     * gd：几乎无处不在，够用；不支持动图，重采样质量略差。
     * imagick：质量更好、支持动图与更多格式，需要额外安装 ImageMagick。
     */
    'driver' => env('IMAGE_DRIVER', 'auto'),

    // JPEG / WebP 默认质量（1-100）
    'jpeg_quality' => (int) env('IMAGE_QUALITY', 82),

    /*
     * 解码内存上限（MB），0 表示按 php.ini 的 memory_limit 判断。
     *
     * 图片解码后的内存是「宽 × 高 × 通道数」，与文件大小无关：
     * 一个 500KB 的 JPEG（6000×4000）解码后约需 96MB。
     * 超出上限时抛异常，而不是让进程 OOM 崩掉。
     */
    'memory_limit_mb' => (int) env('IMAGE_MEMORY_MB', 0),
];
