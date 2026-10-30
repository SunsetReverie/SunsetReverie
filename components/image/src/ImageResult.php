<?php

declare(strict_types=1);

namespace SunsetReverie\Image;

/**
 * 图片处理结果。
 */
final class ImageResult
{
    /**
     * @param string $path 输出路径
     * @param int $width 输出的宽
     * @param int $height 输出的高
     * @param string $mime 输出的 MIME
     * @param int $size 字节数
     * @param int $quality 质量（无损格式为 -1）
     */
    public function __construct(
        public readonly string $path,
        public readonly int $width,
        public readonly int $height,
        public readonly string $mime,
        public readonly int $size,
        public readonly int $quality = -1,
    ) {
    }

    /**
     * 转为数组（便于 API 返回）。
     *
     * @return array<string, mixed> 数据
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'width' => $this->width,
            'height' => $this->height,
            'mime' => $this->mime,
            'size' => $this->size,
        ];
    }
}
