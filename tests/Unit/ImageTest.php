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
        $source = $this->makeImage(400, 200);

        foreach ($this->managers() as $name => $manager) {
            $info = $manager->open($source)->info();

            $this->assertSame(400, $info->width, "[{$name}] 宽");
            $this->assertSame(200, $info->height, "[{$name}] 高");
            $this->assertSame('image/png', $info->mime, "[{$name}] MIME");
            $this->assertSame('png', $info->format, "[{$name}] 格式标识");
            $this->assertTrue($info->isLandscape(), "[{$name}] 应为横向");
        }
    }

    /**
     * resize 只给宽度时，高度按比例。
     *
     * @return void
     */
    public function testResizeByWidthKeepsRatio(): void
    {
        $source = $this->makeImage(400, 200);

        foreach ($this->managers() as $name => $manager) {
            $result = $manager->open($source)->resize(100, 0)->save($this->path("w-{$name}.png"));

            $this->assertSame(100, $result->width, "[{$name}] 宽应为 100");
            $this->assertSame(50, $result->height, "[{$name}] 高应按比例缩为 50");
        }
    }

    /**
     * fit 模式不放大，也不改变比例。
     *
     * @return void
     */
    public function testResizeFitDoesNotUpscale(): void
    {
        $big = $this->makeImage(400, 200);
        $small = $this->makeImage(100, 100);

        foreach ($this->managers() as $name => $manager) {
            // 400x200 装进 100x100 的框 → 100x50（受宽度限制）
            $result = $manager->open($big)->resize(100, 100, 'fit')->save($this->path("fit-{$name}.png"));

            $this->assertSame(100, $result->width, "[{$name}] 宽");
            $this->assertSame(50, $result->height, "[{$name}] 高");

            // 100x100 装进 400x400 → 保持 100x100（不放大）
            $same = $manager->open($small)->resize(400, 400, 'fit')->save($this->path("fit2-{$name}.png"));

            $this->assertSame(100, $same->width, "[{$name}] fit 不应放大");
            $this->assertSame(100, $same->height, "[{$name}] fit 不应放大");
        }
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
        foreach ([[400, 200], [200, 400], [500, 500]] as $index => [$w, $h]) {
            $source = $this->makeImage($w, $h);

            foreach ($this->managers() as $name => $manager) {
                $result = $manager->open($source)->cover(300, 300)->save($this->path("cover-{$name}-{$index}.png"));

                $this->assertSame(300, $result->width, sprintf('[%s] %dx%d cover 后宽应为 300', $name, $w, $h));
                $this->assertSame(300, $result->height, sprintf('[%s] %dx%d cover 后高应为 300', $name, $w, $h));
            }
        }
    }

    /**
     * crop 默认居中裁切。
     *
     * @return void
     */
    public function testCropCentersByDefault(): void
    {
        $source = $this->makeImage(400, 300);

        foreach ($this->managers() as $name => $manager) {
            $result = $manager->open($source)->crop(100, 100)->save($this->path("crop-{$name}.png"));

            $this->assertSame(100, $result->width, "[{$name}] 裁切宽");
            $this->assertSame(100, $result->height, "[{$name}] 裁切高");
        }
    }

    /**
     * 格式转换：PNG → JPEG 时 MIME 与扩展名一致。
     *
     * @return void
     */
    public function testFormatConversion(): void
    {
        $source = $this->makeImage(200, 200);

        foreach ($this->managers() as $name => $manager) {
            $result = $manager->open($source)->format('jpg')->save($this->path("converted-{$name}.jpg"));

            $this->assertSame('image/jpeg', $result->mime, "[{$name}] MIME 应为 image/jpeg");
            $this->assertSame(200, $result->width, "[{$name}] 尺寸不应变化");
            $this->assertTrue(is_file($result->path), "[{$name}] 文件应存在");
        }
    }

    /**
     * PNG 转 JPEG 时透明区应填白，而不是变黑。
     *
     * @return void
     */
    public function testTransparentBecomesWhiteOnJpeg(): void
    {
        $source = $this->makeImage(100, 100, alpha: true);

        foreach ($this->managers() as $name => $manager) {
            $target = $this->path("white-{$name}.jpg");
            $manager->open($source)->format('jpg')->save($target);

            $decoded = imagecreatefromjpeg($target);
            $this->assertTrue($decoded !== false, "[{$name}] 输出应可解码");
            $rgb = imagecolorsforindex($decoded, imagecolorat($decoded, 5, 5));

            $this->assertTrue(
                $rgb['red'] > 240 && $rgb['green'] > 240 && $rgb['blue'] > 240,
                sprintf(
                    '[%s] 透明区应填白，实际 rgb(%d,%d,%d)——填黑说明 flatten 没生效',
                    $name,
                    $rgb['red'],
                    $rgb['green'],
                    $rgb['blue']
                )
            );
        }
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
     * 构造管理器（GD）。
     *
     * @return ImageManager 管理器
     */
    private function manager(): ImageManager
    {
        return new ImageManager(['driver' => 'gd', 'jpeg_quality' => 82]);
    }

    /**
     * 返回所有可用驱动。
     *
     * 尺寸与格式语义是**跨驱动契约**：同样的输入 + 同样的操作，
     * GD 与 Imagick 必须得到同样的宽高与 MIME。
     * 允许像素细节（重采样锐度、压缩误差）不同，但不允许尺寸漂移。
     *
     * @return array<string, ImageManager> 驱动名 => 管理器
     */
    private function managers(): array
    {
        $managers = ['gd' => new ImageManager(['driver' => 'gd', 'jpeg_quality' => 82])];

        if (\SunsetReverie\Image\Driver\ImagickDriver::available()) {
            $managers['imagick'] = new ImageManager(['driver' => 'imagick', 'jpeg_quality' => 82]);
        }

        return $managers;
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

    /**
     * EXIF 方向校正：Orientation=6/8 的照片应旋转 90°。
     *
     * 为什么必须测：手机竖拍的照片带 Orientation 元数据，但像素其实是横的。
     * 不校正的话，用户上传后会看到「照片躺倒了」——而这个 bug 用普通测试图
     * （无 EXIF）永远发现不了，因为 Orientation 恒为 1。
     *
     * @return void
     */
    public function testExifOrientationRotates(): void
    {
        // EXIF 只能注入 JPEG，先把测试图转成 jpg
        $base = $this->path('exif-base.jpg');
        $decoded = imagecreatefrompng($this->makeImage(400, 300));
        $this->assertTrue($decoded !== false);
        imagejpeg($decoded, $base, 92);

        foreach ($this->managers() as $name => $manager) {
            foreach ([6, 8] as $orientation) {
                $file = $this->path("exif-{$orientation}-{$name}.jpg");
                $this->injectExifOrientation($base, $file, $orientation);

                $info = $manager->open($file)->info();
                $this->assertSame($orientation, $info->orientation, "[{$name}] 应读到 Orientation={$orientation}");

                $result = $manager->open($file)->save($this->path("oriented-{$orientation}-{$name}.jpg"));

                $this->assertSame(300, $result->width, "[{$name}] Orientation={$orientation} 旋转后宽应为 300");
                $this->assertSame(400, $result->height, "[{$name}] Orientation={$orientation} 旋转后高应为 400");
            }

            // Orientation=1 不应旋转
            $plain = $this->path("exif-1-{$name}.jpg");
            $this->injectExifOrientation($base, $plain, 1);
            $result = $manager->open($plain)->save($this->path("oriented-1-{$name}.jpg"));

            $this->assertSame(400, $result->width, "[{$name}] Orientation=1 不应旋转");
            $this->assertSame(300, $result->height, "[{$name}] Orientation=1 不应旋转");
        }
    }

    /**
     * 在 JPEG 的 SOI 之后插入最小 EXIF APP1 段（只含 Orientation）。
     *
     * 手工构造是因为：`Imagick::setImageOrientation()` 只改内存属性，
     * 不会写进文件，用它造测试数据会得到「注入成功但读回来是 1」的假象。
     *
     * @param string $source 源文件
     * @param string $target 目标文件
     * @param int $orientation 方向值（1/3/6/8）
     * @return void
     */
    private function injectExifOrientation(string $source, string $target, int $orientation): void
    {
        $data = (string) file_get_contents($source);

        if (substr($data, 0, 2) !== "\xFF\xD8") {
            return;
        }

        // TIFF little-endian 头：字节序 + magic 42 + IFD0 偏移
        $tiff = 'II' . pack('v', 42) . pack('V', 8);

        // IFD0：1 个条目 —— tag=0x0112(Orientation), type=3(SHORT), count=1
        $ifd = pack('v', 1)
            . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $orientation) . pack('v', 0)
            . pack('V', 0);

        $payload = "Exif\x00\x00" . $tiff . $ifd;
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        file_put_contents($target, "\xFF\xD8" . $app1 . substr($data, 2));
    }

    /**
     * auto 驱动应优先选 GD。
     *
     * 依据实测：同一张 1600×1200 图生成 300×300 缩略图，
     * GD 15ms / Imagick 44ms，体积差 6%——缩略图尺寸上质量差异肉眼不可见，
     * 但耗时差 3 倍。
     *
     * 这条测试的作用是**防止有人"顺理成章"地把优先级改回 Imagick**，
     * 改回去会立刻红，fail 信息里说明原因。
     *
     * @return void
     */
    public function testAutoPrefersGd(): void
    {
        $manager = new ImageManager(['driver' => 'auto']);
        $expected = \SunsetReverie\Image\Driver\GdDriver::available() ? 'gd' : 'imagick';

        $this->assertSame(
            $expected,
            $manager->driver()->name(),
            'auto 应优先 GD（快 3 倍）；要 Imagick 请显式配 driver => imagick'
        );
    }

    /**
     * 显式指定 imagick 时不应被 auto 逻辑覆盖。
     *
     * @return void
     */
    public function testExplicitImagickIsRespected(): void
    {
        if (!\SunsetReverie\Image\Driver\ImagickDriver::available()) {
            return;
        }

        $manager = new ImageManager(['driver' => 'imagick']);

        $this->assertSame('imagick', $manager->driver()->name());
    }
}
