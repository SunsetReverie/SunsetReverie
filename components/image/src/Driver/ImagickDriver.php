<?php

declare(strict_types=1);

namespace SunsetReverie\Image\Driver;

use Imagick;
use ImagickException;
use SunsetReverie\Image\Exception\ImageException;
use SunsetReverie\Image\ImageInfo;
use SunsetReverie\Image\ImageResult;

/**
 * Imagick 驱动。
 *
 * 相比 GD 的优势：
 * - 重采样质量更好（Lanczos 滤波）
 * - 原生支持 EXIF 自动转正（autoOrient）
 * - 支持动图（保留动画帧）与更多格式
 * - 内存管理更好（可设置资源限制）
 *
 * 代价：需要安装 ImageMagick 库 + imagick 扩展，
 * 且不同版本的 API 存在差异（autoOrient 需要 3.4+）。
 */
final class ImagickDriver implements ImageDriverInterface
{
    /** @var array<string, string> 格式 → MIME */
    private const MIMES = [
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /**
     * @param int $jpegQuality 默认 JPEG 质量
     * @param int $memoryLimitBytes 解码内存上限（0 表示不检查）
     */
    public function __construct(
        private readonly int $jpegQuality = 82,
        private readonly int $memoryLimitBytes = 0,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return 'imagick';
    }

    /**
     * {@inheritDoc}
     */
    public static function available(): bool
    {
        return extension_loaded('imagick') && class_exists('Imagick');
    }

    /**
     * {@inheritDoc}
     */
    public function info(string $path): ImageInfo
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImageException(sprintf('文件不可读：%s', basename($path)), 422);
        }

        try {
            $image = new Imagick($path);
            $image->pingImage($path);
        } catch (ImagickException $e) {
            throw new ImageException('无法识别图片：' . $e->getMessage(), 422);
        }

        $info = new ImageInfo(
            width: (int) $image->getImageWidth(),
            height: (int) $image->getImageHeight(),
            mime: 'image/' . ($image->getImageFormat() === 'JPEG' ? 'jpeg' : strtolower($image->getImageFormat())),
            format: strtolower((string) $image->getImageFormat()),
            size: (int) (filesize($path) ?: 0),
            animated: $image->getNumberImages() > 1,
            orientation: (int) ($image->getImageOrientation() ?: 1),
        );

        $image->clear();

        return $info;
    }

    /**
     * {@inheritDoc}
     */
    public function process(string $source, array $operations, array $output, ?string $target): ImageResult
    {
        $info = $this->info($source);

        if ($this->memoryLimitBytes > 0 && $info->estimatedMemory() > $this->memoryLimitBytes) {
            throw new ImageException(sprintf(
                '图片过大：%dx%d 解码约需 %.1fMB',
                $info->width,
                $info->height,
                $info->estimatedMemory() / 1048576
            ));
        }

        try {
            $image = new Imagick($source);

            // 只处理第一帧：动图缩略图属于另一个量级的需求
            if ($image->getNumberImages() > 1) {
                $image = $image->coalesceImages();
                $image->setIteratorIndex(0);
            }

            // EXIF 方向校正（方法在 3.4+ 才有，缺失时手动）
            if ($image->getImageOrientation() !== Imagick::ORIENTATION_UNDEFINED) {
                if (method_exists($image, 'autoOrient')) {
                    $image->autoOrient();
                } else {
                    $this->manualOrient($image);
                }
            }

            foreach ($operations as $operation) {
                $this->apply($image, $operation);
            }

            $format = (string) ($output['format'] ?? $info->format);
            $quality = (int) ($output['quality'] ?? $this->jpegQuality);

            if ($format === 'jpeg') {
                // 透明通道压到白底，否则转 JPEG 会变黑
                $image->setImageBackgroundColor('white');
                $image = $image->flattenImages();
            }

            $image->setImageFormat($format === 'jpeg' ? 'jpeg' : $format);
            $image->setImageCompressionQuality(max(1, min(100, $quality)));

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            // tempnam 失败会返回 false，直接传给 ImageResult(string $path) 会 TypeError
            if ($target === null) {
                $temp = tempnam(sys_get_temp_dir(), 'sr-img-');

                if ($temp === false) {
                    throw new ImageException('无法创建临时文件');
                }

                $target = $temp;
            }

            $image->writeImage($target);
            $image->clear();

            return new ImageResult(
                path: $target,
                width: $width,
                height: $height,
                mime: self::MIMES[$format] ?? 'application/octet-stream',
                size: (int) (filesize($target) ?: 0),
                quality: in_array($format, ['jpeg', 'webp'], true) ? $quality : -1,
            );
        } catch (ImagickException $e) {
            throw new ImageException('图片处理失败：' . $e->getMessage(), 422);
        }
    }

    /**
     * 应用一个操作。
     *
     * @param Imagick $image 图像
     * @param array{op: string, args: array<string, mixed>} $operation 操作
     * @return void
     */
    private function apply(Imagick $image, array $operation): void
    {
        $args = $operation['args'];

        match ($operation['op']) {
            'resize' => $this->resize($image, $args),
            'crop' => $this->crop($image, $args),
            'cover' => $this->cover($image, $args),
            'watermark' => $this->watermark($image, $args),
            default => null,
        };
    }

    /**
     * 缩放。
     *
     * @param Imagick $image 图像
     * @param array<string, mixed> $args 参数
     * @return void
     */
    private function resize(Imagick $image, array $args): void
    {
        $width = (int) ($args['width'] ?? 0);
        $height = (int) ($args['height'] ?? 0);
        $mode = (string) ($args['mode'] ?? 'fit');
        $srcW = $image->getImageWidth();
        $srcH = $image->getImageHeight();

        if ($mode === 'exact' && $width > 0 && $height > 0) {
            $image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1, false);

            return;
        }

        if ($width > 0 && $height === 0) {
            $image->resizeImage($width, 0, Imagick::FILTER_LANCZOS, 1);

            return;
        }

        if ($height > 0 && $width === 0) {
            $image->resizeImage(0, $height, Imagick::FILTER_LANCZOS, 1);

            return;
        }

        if ($width > 0 && $height > 0) {
            // fit 不放大
            $ratio = min(1.0, min($width / $srcW, $height / $srcH));
            $w = max(1, (int) round($srcW * $ratio));
            $h = max(1, (int) round($srcH * $ratio));

            if ($w === $srcW && $h === $srcH) {
                return;
            }

            $image->resizeImage(
                max(1, (int) round($srcW * $ratio)),
                max(1, (int) round($srcH * $ratio)),
                Imagick::FILTER_LANCZOS,
                1
            );
        }
    }

    /**
     * 裁切。
     *
     * @param Imagick $image 图像
     * @param array<string, mixed> $args 参数
     * @return void
     */
    private function crop(Imagick $image, array $args): void
    {
        $width = (int) ($args['width'] ?? 0);
        $height = (int) ($args['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return;
        }

        $x = (int) ($args['x'] ?? 0);
        $y = (int) ($args['y'] ?? 0);

        if ($x < 0) {
            $x = (int) max(0, ($image->getImageWidth() - $width) / 2);
        }

        if ($y < 0) {
            $y = (int) max(0, ($image->getImageHeight() - $height) / 2);
        }

        $image->cropImage($width, $height, $x, $y);
        $image->setImagePage(0, 0, 0, 0);
    }

    /**
     * 等比填满后居中裁切。
     *
     * @param Imagick $image 图像
     * @param array<string, mixed> $args 参数
     * @return void
     */
    private function cover(Imagick $image, array $args): void
    {
        $width = (int) ($args['width'] ?? 0);
        $height = (int) ($args['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return;
        }

        $srcW = $image->getImageWidth();
        $srcH = $image->getImageHeight();
        $ratio = max($width / $srcW, $height / $srcH);

        $image->resizeImage(
            max($width, (int) round($srcW * $ratio)),
            max($height, (int) round($srcH * $ratio)),
            Imagick::FILTER_LANCZOS,
            1
        );

        $image->cropImage(
            $width,
            $height,
            (int) (($image->getImageWidth() - $width) / 2),
            (int) (($image->getImageHeight() - $height) / 2)
        );
        $image->setImagePage(0, 0, 0, 0);
    }

    /**
     * 叠加图片水印。
     *
     * @param Imagick $image 图像
     * @param array<string, mixed> $args 参数
     * @return void
     */
    private function watermark(Imagick $image, array $args): void
    {
        $path = (string) ($args['path'] ?? '');

        if ($path === '' || !is_file($path)) {
            throw new ImageException('水印图片不可读', 422);
        }

        $mark = new Imagick($path);

        // 水印过宽时缩到目标图宽的 1/4
        $limit = (int) ($image->getImageWidth() / 4);

        if ($limit > 0 && $mark->getImageWidth() > $limit) {
            $mark->resizeImage($limit, 0, Imagick::FILTER_LANCZOS, 1);
        }

        // 透明度：0-100 → 0-1
        $opacity = max(0, min(100, (int) ($args['opacity'] ?? 80))) / 100;

        /*
         * ImageMagick 6 有 setImageOpacity，7 移除了。
         *
         * 不能用 method_exists() 判断：PHP 的 imagick 扩展 stub 里两个版本都声明了
         * 这个方法，method_exists 恒为 true，但调用时 IM7 会抛
         * "Call to undefined method"。只能按 ImageMagick 主版本判断。
         */
        $isImageMagick7 = version_compare(self::imageMagickVersion(), '7.0.0', '>=');

        if ($isImageMagick7) {
            $mark->setImageAlpha(1.0);
            $mark->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);
        } else {
            $mark->setImageOpacity($opacity);
        }

        $offset = (int) ($args['offset'] ?? 10);
        $x = match ((string) ($args['position'] ?? 'bottom-right')) {
            'top-left', 'bottom-left' => $offset,
            'center' => (int) (($image->getImageWidth() - $mark->getImageWidth()) / 2),
            default => $image->getImageWidth() - $mark->getImageWidth() - $offset,
        };
        $y = match ((string) ($args['position'] ?? 'bottom-right')) {
            'top-left', 'top-right' => $offset,
            'center' => (int) (($image->getImageHeight() - $mark->getImageHeight()) / 2),
            default => $image->getImageHeight() - $mark->getImageHeight() - $offset,
        };

        $image->compositeImage($mark, Imagick::COMPOSITE_OVER, max(0, $x), max(0, $y));
        $mark->clear();
    }

    /**
     * 取 ImageMagick 主版本号。
     *
     * @return string 如 "7.1.2"
     */
    private static function imageMagickVersion(): string
    {
        $version = Imagick::getVersion()['versionString'] ?? '';

        // 形如 "ImageMagick 7.1.2-34 Q16-HDRI aarch64 ..."
        if (preg_match('/ImageMagick\s+(\d+\.\d+\.\d+)/i', $version, $m) === 1) {
            return $m[1];
        }

        return '6.0.0';
    }

    /**
     * 老版本 Imagick 的手动方向校正。
     *
     * @param Imagick $image 图像
     * @return void
     */
    private function manualOrient(Imagick $image): void
    {
        match ($image->getImageOrientation()) {
            Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage('#000', 180),
            Imagick::ORIENTATION_RIGHTTOP => $image->rotateImage('#000', 90),
            Imagick::ORIENTATION_LEFTBOTTOM => $image->rotateImage('#000', -90),
            default => null,
        };

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }
}
