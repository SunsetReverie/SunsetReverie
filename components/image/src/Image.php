<?php

declare(strict_types=1);

namespace SunsetReverie\Image;

use SunsetReverie\Image\Driver\ImageDriverInterface;
use SunsetReverie\Image\Exception\ImageException;

/**
 * 图片处理对象（不可变，链式）。
 *
 * 设计要点：**操作是延迟执行的**。
 * `resize()->crop()->save()` 只会解码一次图片、编码一次，
 * 而不是每步都读写文件——这对大图（解码占几十 MB）差别巨大。
 *
 * 用法：
 *
 *   $image = $imageManager->open('storage/uploads/a.jpg');
 *
 *   // 缩略图：等比填满 300x300，转 WebP，质量 80
 *   $thumb = $image->cover(300, 300)->format('webp')->quality(80)->save('thumb.webp');
 *
 *   // 不落盘，直接要二进制
 *   $binary = $image->resize(800, 0)->output();
 */
final class Image
{
    /** @var list<array{op: string, args: array<string, mixed>}> 待执行的操作 */
    private array $operations = [];

    /** @var string 输出格式（空表示沿用原格式） */
    private string $format = '';

    /** @var int 输出质量（-1 表示用驱动默认） */
    private int $quality = -1;

    /**
     * @param ImageDriverInterface $driver 驱动
     * @param string $source 源文件路径
     */
    public function __construct(
        private readonly ImageDriverInterface $driver,
        private readonly string $source,
    ) {
    }

    /**
     * 源文件路径。
     *
     * @return string 路径
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * 读取图片信息。
     *
     * @return ImageInfo 信息
     */
    public function info(): ImageInfo
    {
        return $this->driver->info($this->source);
    }

    /**
     * 缩放。
     *
     * @param int $width 目标宽（0 表示按高推算）
     * @param int $height 目标高（0 表示按宽推算）
     * @param string $mode fit（等比装入，不放大）/ exact（强制，可能变形）
     * @return self 新实例
     */
    public function resize(int $width, int $height = 0, string $mode = 'fit'): self
    {
        if ($width <= 0 && $height <= 0) {
            throw new ImageException('resize 至少需要一个正数宽或高', 422);
        }

        return $this->with(['op' => 'resize', 'args' => compact('width', 'height', 'mode')]);
    }

    /**
     * 裁切。
     *
     * @param int $width 裁切宽
     * @param int $height 裁切高
     * @param int $x 起点 X（-1 表示水平居中）
     * @param int $y 起点 Y（-1 表示垂直居中）
     * @return self 新实例
     */
    public function crop(int $width, int $height, int $x = -1, int $y = -1): self
    {
        if ($width <= 0 || $height <= 0) {
            throw new ImageException('crop 的宽高必须为正数', 422);
        }

        return $this->with(['op' => 'crop', 'args' => compact('width', 'height', 'x', 'y')]);
    }

    /**
     * 等比填满后居中裁切（缩略图最常用）。
     *
     * 与 `resize` 的区别：
     * - resize(300, 300) → 结果是 300x200（可能留白，不变形）
     * - cover(300, 300)  → 结果一定是 300x300（裁掉多余部分）
     *
     * @param int $width 目标宽
     * @param int $height 目标高
     * @return self 新实例
     */
    public function cover(int $width, int $height): self
    {
        if ($width <= 0 || $height <= 0) {
            throw new ImageException('cover 的宽高必须为正数', 422);
        }

        return $this->with(['op' => 'cover', 'args' => compact('width', 'height')]);
    }

    /**
     * 叠加图片水印。
     *
     * @param string $path 水印图片路径
     * @param string $position 位置（top-left / top-right / bottom-left / bottom-right / center）
     * @param int $opacity 不透明度（0-100）
     * @param int $offset 边距像素
     * @return self 新实例
     */
    public function watermark(string $path, string $position = 'bottom-right', int $opacity = 80, int $offset = 10): self
    {
        return $this->with([
            'op' => 'watermark',
            'args' => ['path' => $path, 'position' => $position, 'opacity' => $opacity, 'offset' => $offset],
        ]);
    }

    /**
     * 输出格式（转换）。
     *
     * @param string $format jpeg / png / webp / gif
     * @return self 新实例
     */
    public function format(string $format): self
    {
        $format = strtolower($format) === 'jpg' ? 'jpeg' : strtolower($format);

        if (!in_array($format, ['jpeg', 'png', 'webp', 'gif'], true)) {
            throw new ImageException(sprintf('不支持的输出格式：%s（可用 jpeg / png / webp / gif）', $format), 422);
        }

        $clone = clone $this;
        $clone->format = $format;

        return $clone;
    }

    /**
     * 质量（对 JPEG / WebP 生效）。
     *
     * @param int $quality 1-100
     * @return self 新实例
     */
    public function quality(int $quality): self
    {
        if ($quality < 1 || $quality > 100) {
            throw new ImageException('质量必须在 1-100 之间', 422);
        }

        $clone = clone $this;
        $clone->quality = $quality;

        return $clone;
    }

    /**
     * 处理并保存。
     *
     * @param string $target 目标路径（目录不存在会自动创建）
     * @return ImageResult 结果
     */
    public function save(string $target): ImageResult
    {
        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ImageException(sprintf('无法创建目录：%s', $directory));
        }

        return $this->driver->process($this->source, $this->operations, $this->outputOptions(), $target);
    }

    /**
     * 处理后返回二进制。
     *
     * @return string 图片内容
     */
    public function output(): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'sr-img-');

        if ($temp === false) {
            throw new ImageException('无法创建临时文件');
        }

        try {
            $this->driver->process($this->source, $this->operations, $this->outputOptions(), $temp);
            $content = file_get_contents($temp);

            return is_string($content) ? $content : '';
        } finally {
            @unlink($temp);
        }
    }

    /**
     * 输出参数。
     *
     * @return array{format: string, quality: int} 参数
     */
    private function outputOptions(): array
    {
        return [
            'format' => $this->format !== '' ? $this->format : $this->driver->info($this->source)->format,
            'quality' => $this->quality > 0 ? $this->quality : 82,
        ];
    }

    /**
     * 追加操作（返回新实例，保证链式调用不互相污染）。
     *
     * @param array{op: string, args: array<string, mixed>} $operation 操作
     * @return self 新实例
     */
    private function with(array $operation): self
    {
        $clone = clone $this;
        $clone->operations[] = $operation;

        return $clone;
    }
}
