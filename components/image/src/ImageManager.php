<?php

declare(strict_types=1);

namespace SunsetReverie\Image;

use SunsetReverie\Image\Driver\GdDriver;
use SunsetReverie\Image\Driver\ImageDriverInterface;
use SunsetReverie\Image\Driver\ImagickDriver;
use SunsetReverie\Image\Exception\ImageException;

/**
 * 图片管理器。
 *
 * 驱动选择由配置决定（config/image.php 的 driver），
 * 也可以在运行时通过 `use()` 临时切换。
 *
 * 选择建议：
 * - **gd**：几乎所有 PHP 环境都有，够用；不支持动图，重采样质量略差
 * - **imagick**：质量更好、支持动图与更多格式；需要额外安装 ImageMagick
 *
 * 默认是 `auto`：装了 Imagick 用 Imagick，否则用 GD，
 * 两者都没有时明确报错（而不是静默降级成"原样返回"）。
 */
final class ImageManager
{
    /** @var array<string, ImageDriverInterface> 已创建的驱动 */
    private array $drivers = [];

    /**
     * @param array<string, mixed> $config 配置
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * 打开图片。
     *
     * @param string $source 源文件路径
     * @param string|null $driver 驱动名（null 表示配置的默认）
     * @return Image 图片对象
     * @throws ImageException 文件不存在时抛出
     */
    public function open(string $source, ?string $driver = null): Image
    {
        if (!is_file($source)) {
            throw new ImageException(sprintf('图片不存在：%s', $source), 422);
        }

        return new Image($this->driver($driver), $source);
    }

    /**
     * 一次性生成多个尺寸（缩略图场景）。
     *
     * 每个尺寸独立解码一次是浪费，但驱动没有提供"一次解码多处输出"的接口。
     * 这里保持简单：多次调用 open()，由调用方决定是否值得优化。
     *
     * @param string $source 源文件
     * @param array<string, array{width: int, height: int, mode: string}> $sizes 尺寸表
     * @param string $directory 输出目录
     * @param string $format 输出格式（空表示沿用原格式）
     * @param int $quality 质量
     * @return array<string, ImageResult> 尺寸名 => 结果
     */
    public function thumbnails(
        string $source,
        array $sizes,
        string $directory,
        string $format = '',
        int $quality = 82,
    ): array {
        $results = [];
        $base = pathinfo($source, PATHINFO_FILENAME);

        foreach ($sizes as $name => $size) {
            $image = $this->open($source);

            $image = ($size['mode'] ?? 'cover') === 'cover'
                ? $image->cover($size['width'], $size['height'])
                : $image->resize($size['width'], $size['height'], (string) ($size['mode'] ?? 'fit'));

            if ($format !== '') {
                $image = $image->format($format);
            }

            $extension = $format !== '' ? $format : pathinfo($source, PATHINFO_EXTENSION);
            $extension = $extension === 'jpeg' ? 'jpg' : $extension;

            $results[$name] = $image
                ->quality($quality)
                ->save(sprintf('%s/%s-%s.%s', rtrim($directory, '/'), $base, $name, $extension));
        }

        return $results;
    }

    /**
     * 取驱动。
     *
     * @param string|null $name 驱动名
     * @return ImageDriverInterface 驱动
     * @throws ImageException 驱动不可用时抛出
     */
    public function driver(?string $name = null): ImageDriverInterface
    {
        $name ??= (string) ($this->config['driver'] ?? 'auto');

        if ($name === 'auto') {
            $name = ImagickDriver::available() ? 'imagick' : 'gd';
        }

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        $quality = (int) ($this->config['jpeg_quality'] ?? 82);
        $memoryLimit = $this->memoryLimit();

        $this->drivers[$name] = match ($name) {
            'gd' => GdDriver::available()
                ? new GdDriver($memoryLimit, $quality)
                : throw new ImageException('GD 驱动不可用：请安装 ext-gd'),
            'imagick' => ImagickDriver::available()
                ? new ImagickDriver($quality, $memoryLimit)
                : throw new ImageException('Imagick 驱动不可用：请安装 ImageMagick 与 ext-imagick'),
            default => throw new ImageException(sprintf(
                '不支持的图片驱动 [%s]，可用：auto / gd / imagick',
                $name
            )),
        };

        return $this->drivers[$name];
    }

    /**
     * 替换驱动（测试或特殊环境）。
     *
     * @param string $name 驱动名
     * @param ImageDriverInterface $driver 驱动实例
     * @return self 当前实例
     */
    public function extend(string $name, ImageDriverInterface $driver): self
    {
        $this->drivers[$name] = $driver;

        return $this;
    }

    /**
     * 从配置读取内存上限（字节）。
     *
     * @return int 上限（0 表示交给驱动按 php.ini 判断）
     */
    private function memoryLimit(): int
    {
        $megabytes = (int) ($this->config['memory_limit_mb'] ?? 0);

        return $megabytes > 0 ? $megabytes * 1024 * 1024 : 0;
    }
}
