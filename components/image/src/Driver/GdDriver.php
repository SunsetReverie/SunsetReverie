<?php

declare(strict_types=1);

namespace SunsetReverie\Image\Driver;

use GdImage;
use SunsetReverie\Image\Exception\ImageException;
use SunsetReverie\Image\ImageInfo;
use SunsetReverie\Image\ImageResult;

/**
 * GD 驱动。
 *
 * GD 是 PHP 内置扩展，几乎无处不在，代价是图像质量与功能不如 Imagick：
 * - 没有 EXIF 方向读取（需自己解析，这里用 exif 扩展读）
 * - 不支持动图（GIF 只取第一帧）
 * - 重采样质量依赖 imagecopyresampled，锐度略差
 *
 * 三个必须处理的坑：
 * 1. **透明通道**：PNG → JPEG 若直接转换，透明区会变成黑色，需要填白底
 * 2. **EXIF 方向**：手机照片 Orientation=6 会横躺，必须旋转
 * 3. **内存**：图片解码后的内存与文件大小无关，500KB 的图可能占 30MB
 */
final class GdDriver implements ImageDriverInterface
{
    /** @var array<string, string> 格式 → MIME */
    private const MIMES = [
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /**
     * @param int $memoryLimitBytes 允许的解码内存上限（0 表示按 php.ini 计算）
     * @param int $jpegQuality 默认 JPEG 质量（1-100）
     */
    public function __construct(
        private readonly int $memoryLimitBytes = 0,
        private readonly int $jpegQuality = 82,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return 'gd';
    }

    /**
     * {@inheritDoc}
     */
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * {@inheritDoc}
     */
    public function info(string $path): ImageInfo
    {
        $this->assertReadable($path);
        $size = @getimagesize($path);

        if ($size === false) {
            throw new ImageException(sprintf('无法识别图片：%s', basename($path)), 422);
        }

        return new ImageInfo(
            width: (int) $size[0],
            height: (int) $size[1],
            mime: (string) $size['mime'],
            format: $this->formatOf((string) $size['mime']),
            size: (int) (filesize($path) ?: 0),
            animated: false,
            orientation: $this->orientation($path),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function process(string $source, array $operations, array $output, ?string $target): ImageResult
    {
        $info = $this->info($source);
        $this->assertMemory($info);

        $image = $this->decode($source, $info);
        $image = $this->fixOrientation($image, $info->orientation);

        foreach ($operations as $operation) {
            $image = $this->apply($image, $operation);
        }

        $format = (string) ($output['format'] ?? $info->format);
        $quality = (int) ($output['quality'] ?? $this->jpegQuality);

        // 透明图转到不支持透明的格式时填白底
        if (in_array($format, ['jpeg'], true)) {
            $image = $this->flatten($image);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $target ??= tempnam(sys_get_temp_dir(), 'sr-img-');

        $this->encode($image, $target, $format, $quality);

        return new ImageResult(
            path: $target,
            width: $width,
            height: $height,
            mime: self::MIMES[$format] ?? 'application/octet-stream',
            size: (int) (filesize($target) ?: 0),
            quality: in_array($format, ['jpeg', 'webp'], true) ? $quality : -1,
        );
    }

    /**
     * 应用一个操作。
     *
     * @param GdImage $image 画布
     * @param array{op: string, args: array<string, mixed>} $operation 操作
     * @return GdImage 新画布
     */
    private function apply(GdImage $image, array $operation): GdImage
    {
        $args = $operation['args'];

        return match ($operation['op']) {
            'resize' => $this->resize(
                $image,
                (int) ($args['width'] ?? 0),
                (int) ($args['height'] ?? 0),
                (string) ($args['mode'] ?? 'fit')
            ),
            'crop' => $this->crop(
                $image,
                (int) ($args['width'] ?? 0),
                (int) ($args['height'] ?? 0),
                (int) ($args['x'] ?? 0),
                (int) ($args['y'] ?? 0)
            ),
            'cover' => $this->cover($image, (int) ($args['width'] ?? 0), (int) ($args['height'] ?? 0)),
            'watermark' => $this->watermark($image, $args),
            default => $image,
        };
    }

    /**
     * 缩放。
     *
     * @param GdImage $image 画布
     * @param int $width 目标宽（0 表示按高推算）
     * @param int $height 目标高（0 表示按宽推算）
     * @param string $mode fit（等比装入）/ exact（强制，可能变形）/ width / height
     * @return GdImage 新画布
     */
    private function resize(GdImage $image, int $width, int $height, string $mode): GdImage
    {
        $srcW = imagesx($image);
        $srcH = imagesy($image);

        [$dstW, $dstH] = $this->targetSize($srcW, $srcH, $width, $height, $mode);

        if ($dstW === $srcW && $dstH === $srcH) {
            return $image;
        }

        return $this->scale($image, $dstW, $dstH);
    }

    /**
     * 计算目标尺寸。
     *
     * @param int $srcW 源宽
     * @param int $srcH 源高
     * @param int $width 目标宽
     * @param int $height 目标高
     * @param string $mode 模式
     * @return array{0: int, 1: int} 目标宽高
     */
    private function targetSize(int $srcW, int $srcH, int $width, int $height, string $mode): array
    {
        if ($mode === 'exact' || ($width > 0 && $height > 0 && $mode === 'stretch')) {
            return [$width > 0 ? $width : $srcW, $height > 0 ? $height : $srcH];
        }

        if ($width > 0 && $height === 0) {
            return [$width, max(1, (int) round($srcH * $width / $srcW))];
        }

        if ($height > 0 && $width === 0) {
            return [max(1, (int) round($srcW * $height / $srcH)), $height];
        }

        if ($width <= 0 || $height <= 0) {
            return [$srcW, $srcH];
        }

        // fit：等比缩放到能装进目标框，且**不放大**
        // （不限制上限的话，100x100 的图装进 400x400 会被放大成 400x400）
        $ratio = min(1.0, min($width / $srcW, $height / $srcH));

        return [max(1, (int) round($srcW * $ratio)), max(1, (int) round($srcH * $ratio))];
    }

    /**
     * 等比缩放到指定尺寸。
     *
     * @param GdImage $image 画布
     * @param int $width 目标宽
     * @param int $height 目标高
     * @return GdImage 新画布
     */
    private function scale(GdImage $image, int $width, int $height): GdImage
    {
        $canvas = $this->canvas($width, $height);
        imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($image),
            imagesy($image)
        );

        return $canvas;
    }

    /**
     * 框内裁切。
     *
     * @param GdImage $image 画布
     * @param int $width 裁切宽
     * @param int $height 裁切高
     * @param int $x 起点 X（负数按居中处理）
     * @param int $y 起点 Y
     * @return GdImage 新画布
     */
    private function crop(GdImage $image, int $width, int $height, int $x, int $y): GdImage
    {
        $srcW = imagesx($image);
        $srcH = imagesy($image);

        $width = min($width > 0 ? $width : $srcW, $srcW);
        $height = min($height > 0 ? $height : $srcH, $srcH);

        if ($x < 0) {
            $x = (int) max(0, ($srcW - $width) / 2);
        }

        if ($y < 0) {
            $y = (int) max(0, ($srcH - $height) / 2);
        }

        $canvas = $this->canvas($width, $height);
        imagecopy($canvas, $image, 0, 0, $x, $y, $width, $height);

        return $canvas;
    }

    /**
     * 等比填满后居中裁切（缩略图最常用的做法）。
     *
     * @param GdImage $image 画布
     * @param int $width 目标宽
     * @param int $height 目标高
     * @return GdImage 新画布
     */
    private function cover(GdImage $image, int $width, int $height): GdImage
    {
        if ($width <= 0 || $height <= 0) {
            return $image;
        }

        $srcW = imagesx($image);
        $srcH = imagesy($image);

        // 先按「填满」比例放大，再居中裁切
        $ratio = max($width / $srcW, $height / $srcH);
        $scaledW = max($width, (int) round($srcW * $ratio));
        $scaledH = max($height, (int) round($srcH * $ratio));

        $scaled = $this->scale($image, $scaledW, $scaledH);

        return $this->crop(
            $scaled,
            $width,
            $height,
            (int) (($scaledW - $width) / 2),
            (int) (($scaledH - $height) / 2)
        );
    }

    /**
     * 叠加图片水印。
     *
     * @param GdImage $image 画布
     * @param array<string, mixed> $args 参数（path / position / opacity / offset）
     * @return GdImage 画布
     */
    private function watermark(GdImage $image, array $args): GdImage
    {
        $path = (string) ($args['path'] ?? '');

        if ($path === '') {
            throw new ImageException('水印需要提供图片路径', 422);
        }

        $this->assertReadable($path);
        $mark = $this->decode($path, $this->info($path));

        $markW = imagesx($mark);
        $markH = imagesy($mark);

        // 水印过大时按目标图宽的 1/4 缩放
        $limit = (int) (imagesx($image) / 4);

        if ($limit > 0 && $markW > $limit) {
            $mark = $this->scale($mark, $limit, max(1, (int) round($markH * $limit / $markW)));
            $markW = imagesx($mark);
            $markH = imagesy($mark);
        }

        $offset = (int) ($args['offset'] ?? 10);
        [$x, $y] = $this->watermarkPosition(
            (string) ($args['position'] ?? 'bottom-right'),
            imagesx($image),
            imagesy($image),
            $markW,
            $markH,
            $offset
        );

        // 透明度用 imagecopymerge（0=全透明，100=不透明）
        $opacity = max(0, min(100, (int) ($args['opacity'] ?? 80)));
        imagecopymerge($image, $mark, $x, $y, 0, 0, $markW, $markH, $opacity);

        return $image;
    }

    /**
     * 计算水印位置。
     *
     * @param string $position 位置标识
     * @param int $canvasW 画布宽
     * @param int $canvasH 画布高
     * @param int $markW 水印宽
     * @param int $markH 水印高
     * @param int $offset 边距
     * @return array{0: int, 1: int} 坐标
     */
    private function watermarkPosition(
        string $position,
        int $canvasW,
        int $canvasH,
        int $markW,
        int $markH,
        int $offset
    ): array {
        $left = $offset;
        $right = $canvasW - $markW - $offset;
        $top = $offset;
        $bottom = $canvasH - $markH - $offset;

        return match ($position) {
            'top-left' => [$left, $top],
            'top-right' => [$right, $top],
            'bottom-left' => [$left, $bottom],
            'center' => [(int) (($canvasW - $markW) / 2), (int) (($canvasH - $markH) / 2)],
            default => [$right, $bottom],
        };
    }

    /**
     * 创建支持透明通道的画布。
     *
     * @param int $width 宽
     * @param int $height 高
     * @return GdImage 画布
     */
    private function canvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            throw new ImageException(sprintf('无法创建画布 %dx%d', $width, $height));
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

        if ($transparent !== false) {
            imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
        }

        return $canvas;
    }

    /**
     * 解码源文件。
     *
     * @param string $path 路径
     * @param ImageInfo $info 信息
     * @return GdImage 画布
     */
    private function decode(string $path, ImageInfo $info): GdImage
    {
        $image = match ($info->format) {
            'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'webp' => @imagecreatefromwebp($path),
            'gif' => @imagecreatefromgif($path),
            default => false,
        };

        if (!$image instanceof GdImage) {
            throw new ImageException(sprintf('GD 无法解码该格式：%s', $info->mime), 422);
        }

        return $image;
    }

    /**
     * 按 EXIF 方向校正。
     *
     * 手机竖拍的照片常带 Orientation=6/8，不旋转就会横躺。
     *
     * @param GdImage $image 画布
     * @param int $orientation EXIF 方向值
     * @return GdImage 画布
     */
    private function fixOrientation(GdImage $image, int $orientation): GdImage
    {
        return match ($orientation) {
            3, 4 => imagerotate($image, 180, 0) ?: $image,
            5, 6 => imagerotate($image, -90, 0) ?: $image,
            7, 8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }

    /**
     * 把透明区填成白底（转 JPEG 前必须做）。
     *
     * @param GdImage $image 画布
     * @return GdImage 画布
     */
    private function flatten(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            return $image;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);

        if ($white !== false) {
            imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        }

        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        return $canvas;
    }

    /**
     * 编码输出。
     *
     * @param GdImage $image 画布
     * @param string $target 目标路径
     * @param string $format 格式
     * @param int $quality 质量
     * @return void
     */
    private function encode(GdImage $image, string $target, string $format, int $quality): void
    {
        $ok = match ($format) {
            'jpeg' => imagejpeg($image, $target, max(1, min(100, $quality))),
            'png' => imagepng($image, $target, $this->pngLevel($quality)),
            'webp' => imagewebp($image, $target, max(1, min(100, $quality))),
            'gif' => imagegif($image, $target),
            default => false,
        };

        if ($ok !== true) {
            throw new ImageException(sprintf('编码失败：%s', $format));
        }
    }

    /**
     * JPEG 质量（0-100）换算成 PNG 压缩级别（0-9）。
     *
     * @param int $quality JPEG 质量
     * @return int PNG 级别
     */
    private function pngLevel(int $quality): int
    {
        $level = (int) round((100 - max(1, min(100, $quality))) / 100 * 9);

        return max(0, min(9, $level));
    }

    /**
     * 读取 EXIF 方向。
     *
     * @param string $path 路径
     * @return int 方向（无 EXIF 时返回 1）
     */
    private function orientation(string $path): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path, 'IFD0');

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    /**
     * 校验文件可读。
     *
     * @param string $path 路径
     * @return void
     */
    private function assertReadable(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImageException(sprintf('文件不可读：%s', basename($path)), 422);
        }
    }

    /**
     * 解码前检查内存是否够用。
     *
     * @param ImageInfo $info 信息
     * @return void
     * @throws ImageException 内存不足时抛出
     */
    private function assertMemory(ImageInfo $info): void
    {
        $limit = $this->memoryLimitBytes > 0 ? $this->memoryLimitBytes : $this->phpMemoryLimit();
        $needed = $info->estimatedMemory();

        if ($limit > 0 && $needed > $limit) {
            throw new ImageException(sprintf(
                '图片过大：%dx%d 解码约需 %s，超出可用内存 %s',
                $info->width,
                $info->height,
                $this->human($needed),
                $this->human($limit)
            ));
        }
    }

    /**
     * 读取 php.ini 的内存上限。
     *
     * @return int 字节数（-1 表示不限制）
     */
    private function phpMemoryLimit(): int
    {
        $value = (string) ini_get('memory_limit');

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * 人类可读体积。
     *
     * @param int $bytes 字节
     * @return string 描述
     */
    private function human(int $bytes): string
    {
        return $bytes >= 1048576
            ? sprintf('%.1fMB', $bytes / 1048576)
            : sprintf('%dKB', (int) round($bytes / 1024));
    }

    /**
     * MIME 转格式标识。
     *
     * @param string $mime MIME
     * @return string 格式
     */
    private function formatOf(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => substr($mime, 6),
        };
    }
}
