<?php

declare(strict_types=1);

return [
    /*
     * 驱动：auto / gd / imagick
     *
     * auto（默认）：GD 优先，没有 GD 才用 Imagick；两者都没有时明确报错。
     * gd：几乎无处不在；速度快（实测比 Imagick 快 2–3 倍），缩略图首选。
     * imagick：重采样质量更好（Lanczos）、支持动图与更多格式，
     *          但需要额外安装 ImageMagick，且速度慢（进程外编解码）。
     *
     * 选 auto 还是 imagick：缩略图 / 列表图用 auto（快）；
     * 大图精修、需要保留动图时显式配 imagick。
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
