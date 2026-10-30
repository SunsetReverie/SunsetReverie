<?php

declare(strict_types=1);

namespace SunsetReverie\Image\Driver;

use SunsetReverie\Image\ImageInfo;
use SunsetReverie\Image\ImageResult;

/**
 * 图片驱动接口。
 *
 * 两个实现（GD / Imagick）必须对这些操作给出一致的结果：
 * 同样的输入 + 同样的操作 → 同样的宽高与格式。
 * 允许像素细节（抖动、压缩误差）不同，但尺寸与行为必须一致。
 */
interface ImageDriverInterface
{
    /**
     * 驱动名。
     *
     * @return string 驱动标识（gd / imagick）
     */
    public function name(): string;

    /**
     * 读取图片信息（不解码全图）。
     *
     * @param string $path 文件路径
     * @return ImageInfo 信息
     */
    public function info(string $path): ImageInfo;

    /**
     * 执行处理。
     *
     * @param string $source 源文件
     * @param list<array{op: string, args: array<string, mixed>}> $operations 操作序列
     * @param array{format: string, quality: int} $output 输出参数
     * @param string|null $target 输出路径（null 表示返回二进制）
     * @return ImageResult 结果
     */
    public function process(string $source, array $operations, array $output, ?string $target): ImageResult;

    /**
     * 是否可用（扩展是否安装）。
     *
     * @return bool 可用返回 true
     */
    public static function available(): bool;
}
