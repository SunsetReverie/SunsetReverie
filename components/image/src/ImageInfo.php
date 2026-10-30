<?php

declare(strict_types=1);

namespace SunsetReverie\Image;

/**
 * 图片基本信息。
 */
final class ImageInfo
{
    /**
     * @param int $width 宽
     * @param int $height 高
     * @param string $mime MIME
     * @param string $format 格式标识（jpeg / png / webp / gif）
     * @param int $size 字节数
     * @param bool $animated 是否动图（仅 Imagick 能识别）
     * @param int $orientation EXIF 方向（1 表示正常）
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $mime,
        public readonly string $format,
        public readonly int $size,
        public readonly bool $animated = false,
        public readonly int $orientation = 1,
    ) {
    }

    /**
     * 宽高比。
     *
     * @return float 比例
     */
    public function ratio(): float
    {
        return $this->height === 0 ? 0.0 : $this->width / $this->height;
    }

    /**
     * 是否为横向图。
     *
     * @return bool 横向返回 true
     */
    public function isLandscape(): bool
    {
        return $this->width >= $this->height;
    }

    /**
     * 估算解码所需内存（字节）。
     *
     * 图片解码后的内存是 `宽 × 高 × 每像素字节数`，与文件大小无关——
     * 一个 500KB 的 JPEG 解码后可能占 30MB。这是最常见的 OOM 来源。
     *
     * @param int $channels 通道数（RGB=3，RGBA=4）
     * @return int 字节数
     */
    public function estimatedMemory(int $channels = 4): int
    {
        // 乘以 1.8：解码缓冲、目标画布、临时数组的额外开销
        return (int) ($this->width * $this->height * $channels * 1.8);
    }
}
