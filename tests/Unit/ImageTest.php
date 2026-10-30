<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Image\Driver\ImagickDriver;
use SunsetReverie\Image\Exception\ImageException;
use SunsetReverie\Image\ImageManager;
use Tests\TestCase;

/**
 * 图片处理测试（GD 驱动实测，Imagick 可用时同套用例再跑一遍）。
 *
 * 重点覆盖三个容易出错的地方：
 * 1. **尺寸语义**：fit 不放大、exact 强制、cover 一定是目标尺寸
 * 2. **透明通道**：PNG 转 JPEG 必须填白，否则透明区变黑
 * 3. **内存预检**：解码内存与文件大小无关，大图要提前拒绝而不是 OOM
 */
final class ImageTest extends TestCase
{
    /** @var string 临时目录 */
    private string $directory;

    /**
     * 准备目录。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sr-image-' . uniqid('', true);
        mkdir($this->directory, 0755, true);
    }

    /**
     * 清理。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            is_file($file) && @unlink($file);
        }

        @rmdir($this->directory);
    }

    /**
     * 读取图片信息。
     *
     * @return void
     */
    public function testReadsImageInfo(): void
    {
        $manager = $this->manager();
        $source = $this->makeImage(400, 200);

        $info = $manager->open($source)->info();

        $this->assertSame(400, $info->width);
        $this->assertSame(200, $info->height);
        $this->assertSame('image/png', $info->mime);
        $this->assertSame('png', $info->format);
        $this->assertTrue($info->isLandscape());
    }

    /**
     * resize 只给宽度时，高度按比例。
     *
     * @return void
     */
    public function testResizeByWidthKeepsRatio(): void
    {
        $manager = $this->manager();
        $result = $manager->open($this->makeImage(400, 200))
            ->resize(100, 0)
            ->save($this->path('w.png'));

        $this->assertSame(100, $result->width);
        $this->assertSame(50, $result->height);
    }

    /**
     * fit 模式不放大，也不改变比例。
     *
     * @return void
     */
    public function testResizeFitDoesNotUpscale(): void
    {
        $manager = $this->manager();

        // 400x200 装进 100x100 的框 → 100x50（受宽度限制）
        $result = $manager->open($this->makeImage(400, 200))
            ->resize(100, 100, 'fit')
            ->save($this->path('fit.png'));

        $this->assertSame(100, $result->width);
        $this->assertSame(50, $result->height);

        // 100x100 装进 400x400 → 保持 100x100（不放大）
        $same = $manager->open($this->makeImage(100, 100))
            ->resize(400, 400, 'fit')
            ->save($this->path('fit2.png'));

        $this->assertSame(100, $same->width, 'fit 不应放大');
    }

    /**
     * exact 模式强制目标尺寸（可能变形）。
     *
     * @return void
     */
    public function testResizeExactForcesSize(): void
    {
        $manager = $this->manager();
        $result = $manager->open($this->makeImage(400, 200))
            ->resize(100, 100, 'exact')
            ->save($this->path('exact.png'));

        $this->assertSame(100, $result->width);
        $this->assertSame(100, $result->height);
    }

    /**
     * cover 的结果一定是目标尺寸（裁掉多余部分）。
     *
     * @return void
     */
    public function testCoverProducesExactSize(): void
    {
        $manager = $this->manager();

        foreach ([[400, 200], [200, 400], [500, 500]] as $index => [$w, $h]) {
            $result = $manager->open($this->makeImage($w, $h))
                ->cover(300, 300)
                ->save($this->path('cover-' . $index . '.png'));

            $this->assertSame(300, $result->width, sprintf('%dx%d cover 后宽应为 300', $w, $h));
            $this->assertSame(300, $result->height, sprintf('%dx%d cover 后高应为 300', $w, $h));
        }
    }

    /**
     * crop 默认居中裁切。
     *
     * @return void
     */
    public function testCropCentersByDefault(): void
    {
        $manager = $this->manager();
        $result = $manager->open($this->makeImage(400, 300))
            ->crop(100, 100)
            ->save($this->path('crop.png'));

        $this->assertSame(100, $result->width);
        $this->assertSame(100, $result->height);
    }

    /**
     * 格式转换：PNG → JPEG 时 MIME 与扩展名一致。
     *
     * @return void
     */
    public function testFormatConversion(): void
    {
        $manager = $this->manager();
        $result = $manager->open($this->makeImage(200, 200))
            ->format('jpg')
            ->save($this->path('converted.jpg'));

        $this->assertSame('image/jpeg', $result->mime);
        $this->assertSame(200, $result->width);
    }

    /**
     * PNG 转 JPEG 时透明区应填白，而不是变黑。
     *
     * @return void
     */
    public function testTransparentBecomesWhiteOnJpeg(): void
    {
        $manager = $this->manager();
        $source = $this->makeImage(100, 100, alpha: true);

        $manager->open($source)->format('jpg')->save($this->path('white.jpg'));

        $decoded = imagecreatefromjpeg($this->path('white.jpg'));
        $this->assertTrue($decoded !== false);
        $rgb = imagecolorsforindex($decoded, imagecolorat($decoded, 5, 5));
        imagedestroy($decoded);

        $this->assertTrue(
            $rgb['red'] > 240 && $rgb['green'] > 240 && $rgb['blue'] > 240,
            sprintf('透明区应填白，实际 rgb(%d,%d,%d)——填黑说明 flatten 没生效', $rgb['red'], $rgb['green'], $rgb['blue'])
        );
    }

    /**
     * 质量参数应影响文件体积。
     *
     * @return void
     */
    public function testQualityAffectsFileSize(): void
    {
        $manager = $this->manager();
        $source = $this->makeNoiseImage(300, 300);

        $low = $manager->open($source)->format('jpg')->quality(15)->save($this->path('low.jpg'));
        $high = $manager->open($source)->format('jpg')->quality(95)->save($this->path('high.jpg'));

        $this->assertTrue(
            $low->size < $high->size,
            sprintf('低质量(%d 字节)应小于高质量(%d 字节)', $low->size, $high->size)
        );
    }

    /**
     * 水印应改变像素。
     *
     * @return void
     */
    public function testWatermarkChangesPixels(): void
    {
        $manager = $this->manager();
        $source = $this->makeImage(200, 200);
        $mark = $this->makeSolidImage(20, 20, [255, 0, 0]);

        $plain = $manager->open($source)->save($this->path('plain.png'));
        $stamped = $manager->open($source)
            ->watermark($mark, 'center', 100)
            ->save($this->path('stamped.png'));

        $this->assertTrue(
            $plain->size !== $stamped->size,
            '水印后文件内容应发生变化'
        );
    }

    /**
     * 内存预检：超大图应明确报错，而不是让进程 OOM。
     *
     * @return void
     */
    public function testRejectsOversizedImage(): void
    {
        // 把上限设成 1MB，6000x4000 的图解码约需 96MB
        $manager = new ImageManager(['driver' => 'gd', 'memory_limit_mb' => 1]);
        $source = $this->makeImage(6000, 4000);

        $e = $this->assertThrows(
            ImageException::class,
            static fn () => $manager->open($source)->resize(100, 0)->save(sys_get_temp_dir() . '/sr-x.png')
        );

        $this->assertStringContains('图片过大', $e->getMessage());
    }

    /**
     * 不存在的文件应报 422 而不是 500。
     *
     * @return void
     */
    public function testMissingFileIsValidationError(): void
    {
        $e = $this->assertThrows(
            ImageException::class,
            fn () => $this->manager()->open($this->path('nope.png'))
        );

        $this->assertSame(422, $e->status);
    }

    /**
     * 一次生成多个尺寸。
     *
     * @return void
     */
    public function testThumbnails(): void
    {
        $manager = $this->manager();
        $results = $manager->thumbnails(
            $this->makeImage(1200, 800),
            ['thumb' => ['width' => 200, 'height' => 200, 'mode' => 'cover'],
             'medium' => ['width' => 600, 'height' => 600, 'mode' => 'cover']],
            $this->directory,
            'jpg',
            80
        );

        $this->assertSame(2, count($results));
        $this->assertSame(200, $results['thumb']->width);
        $this->assertSame(600, $results['medium']->width);
        $this->assertTrue(is_file($results['thumb']->path));
    }

    /**
     * 选用不存在的驱动时应明确报错。
     *
     * @return void
     */
    public function testUnknownDriverThrows(): void
    {
        $manager = new ImageManager(['driver' => 'nope']);

        $e = $this->assertThrows(ImageException::class, static fn () => $manager->driver());

        $this->assertStringContains('不支持的图片驱动', $e->getMessage());
    }

    /**
     * 构造管理器（当前环境有什么用什么）。
     *
     * @return ImageManager 管理器
     */
    private function manager(): ImageManager
    {
        return new ImageManager(['driver' => 'gd', 'jpeg_quality' => 82]);
    }

    /**
     * 生成纯色测试图。
     *
     * @param int $width 宽
     * @param int $height 高
     * @param bool $alpha 是否带透明通道
     * @param array{0: int, 1: int, 2: int} $color 颜色
     * @return string 路径
     */
    private function makeImage(int $width, int $height, bool $alpha = false, array $color = [200, 100, 50]): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertTrue($image !== false);

        if ($alpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        } else {
            imagefill($image, 0, 0, (int) imagecolorallocate($image, $color[0], $color[1], $color[2]));
        }

        $path = $this->path('src-' . bin2hex(random_bytes(4)) . '.png');
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * 生成带噪点的图（让质量参数有可见效果）。
     *
     * @param int $width 宽
     * @param int $height 高
     * @return string 路径
     */
    private function makeNoiseImage(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertTrue($image !== false);

        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, (int) $color);
            }
        }

        $path = $this->path('noise-' . bin2hex(random_bytes(4)) . '.png');
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * 生成纯色小图（水印用）。
     *
     * @param int $width 宽
     * @param int $height 高
     * @param array{0: int, 1: int, 2: int} $color 颜色
     * @return string 路径
     */
    private function makeSolidImage(int $width, int $height, array $color): string
    {
        return $this->makeImage($width, $height, false, $color);
    }

    /**
     * 临时文件路径。
     *
     * @param string $name 文件名
     * @return string 路径
     */
    private function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }
}
