<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Http\UploadedFile;
use SunsetReverie\Upload\Exception\UploadException;
use SunsetReverie\Upload\Storage\LocalStorage;
use SunsetReverie\Upload\Storage\MemoryStorage;
use SunsetReverie\Upload\UploadManager;
use Tests\TestCase;

/**
 * 上传组件测试（内存存储，无需真实 HTTP 上传）。
 */
final class UploadTest extends TestCase
{
    /** @var string 临时目录 */
    private string $directory;

    /** @var list<string> 待清理的临时文件 */
    private array $temporaryFiles = [];

    /**
     * 准备临时目录。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sr-upload-' . uniqid('', true);
        mkdir($this->directory, 0755, true);
    }

    /**
     * 清理。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        array_map(static fn (string $file): bool => @unlink($file), $this->temporaryFiles);

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            is_dir($file) ? @rmdir($file) : @unlink($file);
        }

        @rmdir($this->directory);
    }

    /**
     * 合法图片应保存成功并返回 URL。
     *
     * @return void
     */
    public function testStoresValidImage(): void
    {
        $storage = new MemoryStorage();
        $manager = $this->manager($storage);

        $stored = $manager->store($this->pngFile('avatar.png'), 'avatars');

        $this->assertTrue($storage->exists($stored->path));
        $this->assertSame('image/png', $stored->mime);
        $this->assertStringContains('/uploads/avatars/', $stored->url);
        $this->assertStringContains('.png', $stored->path);
        $this->assertSame('avatar.png', $stored->originalName);
        $this->assertTrue($stored->size > 0);
        $this->assertSame(64, strlen($stored->hash), 'hash 应在落盘前计算（64 位十六进制）');
    }

    /**
     * 文件名由服务端生成，不含客户端原名（避免路径注入与信息泄露）。
     *
     * @return void
     */
    public function testGeneratedNameIgnoresClientName(): void
    {
        $manager = $this->manager(new MemoryStorage());
        $stored = $manager->store($this->pngFile('../../etc/passwd.png'));

        $this->assertFalse(str_contains($stored->path, 'passwd'));
        $this->assertFalse(str_contains($stored->path, '..'));
        $this->assertSame(1, preg_match('#^[0-9]{4}/[0-9]{2}/[0-9]{2}/[0-9a-f]{32}\.png$#', $stored->path), '路径应为 日期目录 + UUID.扩展名');
    }

    /**
     * 超过大小上限应抛 413。
     *
     * @return void
     */
    public function testRejectsOversizedFile(): void
    {
        $manager = new UploadManager(
            ['max_size' => 10, 'allowed_mimes' => ['image/png']],
            new MemoryStorage()
        );

        $e = $this->assertThrows(
            UploadException::class,
            fn () => $manager->store($this->pngFile('big.png'))
        );

        $this->assertSame(413, $e->status);
    }

    /**
     * MIME 不在白名单应抛 415。
     *
     * @return void
     */
    public function testRejectsDisallowedMime(): void
    {
        $manager = new UploadManager(
            ['max_size' => 1024 * 1024, 'allowed_mimes' => ['image/png']],
            new MemoryStorage()
        );

        $e = $this->assertThrows(
            UploadException::class,
            fn () => $manager->store($this->textFile('note.txt'))
        );

        $this->assertSame(415, $e->status);
    }

    /**
     * 危险扩展名即便 MIME 合法也应拒绝。
     *
     * @return void
     */
    public function testRejectsDangerousExtension(): void
    {
        $manager = $this->manager(new MemoryStorage());

        $e = $this->assertThrows(
            UploadException::class,
            fn () => $manager->store($this->pngFile('shell.php'))
        );

        $this->assertSame(415, $e->status);
    }

    /**
     * 伪装成图片的文本文件应被图片结构校验拦下。
     *
     * @return void
     */
    public function testRejectsFakeImage(): void
    {
        $manager = new UploadManager(
            ['max_size' => 1024 * 1024, 'allowed_mimes' => ['image/png']],
            new MemoryStorage()
        );

        // 内容是纯文本，但扩展名与客户端 MIME 都伪装成 png
        $path = $this->write('fake.png', '<?php echo "hacked";');
        $file = UploadedFile::fake($path, 'fake.png', 'image/png');

        $this->assertThrows(UploadException::class, fn () => $manager->store($file));
    }

    /**
     * 签名 URL 应可验证，篡改或过期应失败。
     *
     * @return void
     */
    public function testSignedUrl(): void
    {
        $manager = $this->manager(new MemoryStorage(), ['sign_secret' => str_repeat('k', 32)]);

        $url = $manager->signedUrl('app/2026/10/30/x.pdf', 60);

        $this->assertStringContains('expires=', $url);
        $this->assertStringContains('sign=', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expires = (int) ($query['expires'] ?? 0);
        $sign = is_string($query['sign'] ?? null) ? $query['sign'] : '';

        $this->assertTrue($manager->verifySignature('app/2026/10/30/x.pdf', $expires, $sign));
        $this->assertFalse($manager->verifySignature('app/2026/10/30/y.pdf', $expires, $sign));
        $this->assertFalse($manager->verifySignature('app/2026/10/30/x.pdf', $expires - 1000, $sign));
    }

    /**
     * 未配置签名密钥时应明确报错，而不是生成可预测的签名。
     *
     * @return void
     */
    public function testSignedUrlRequiresSecret(): void
    {
        $manager = $this->manager(new MemoryStorage());

        $this->assertThrows(
            UploadException::class,
            fn () => $manager->signedUrl('x.pdf', 60)
        );
    }

    /**
     * 本地存储应阻断目录穿越。
     *
     * @return void
     */
    public function testLocalStorageBlocksTraversal(): void
    {
        $storage = new LocalStorage($this->directory);

        $this->assertThrows(
            UploadException::class,
            static fn () => $storage->put('../../etc/evil.txt', '/tmp/x')
        );
    }

    /**
     * 本地存储应真实落盘并可删除。
     *
     * @return void
     */
    public function testLocalStorageWritesAndDeletes(): void
    {
        $storage = new LocalStorage($this->directory, '/uploads');
        $source = $this->write('source.bin', 'binary-content');

        $storage->put('app/2026/10/30/a.bin', $source);

        $this->assertTrue($storage->exists('app/2026/10/30/a.bin'));
        $this->assertSame('binary-content', (string) file_get_contents($storage->path('app/2026/10/30/a.bin')));
        $this->assertSame('/uploads/app/2026/10/30/a.bin', $storage->url('app/2026/10/30/a.bin'));

        $storage->delete('app/2026/10/30/a.bin');
        $this->assertFalse($storage->exists('app/2026/10/30/a.bin'));
    }

    /**
     * 构造上传管理器。
     *
     * @param MemoryStorage|LocalStorage $storage 存储
     * @param array<string, mixed> $overrides 配置覆盖
     * @return UploadManager 管理器
     */
    private function manager(object $storage, array $overrides = []): UploadManager
    {
        return new UploadManager(
            array_merge([
                'max_size' => 1024 * 1024,
                'allowed_mimes' => ['image/png', 'image/jpeg', 'application/pdf', 'text/plain'],
            ], $overrides),
            $storage
        );
    }

    /**
     * 生成一张真实的 PNG（1x1）。
     *
     * @param string $name 客户端文件名
     * @return UploadedFile 上传文件
     */
    /**
     * 生成指定尺寸的真实 PNG（缩略图测试要判断「比原图宽则跳过」）。
     *
     * @param string $name 客户端文件名
     * @param int<1, max> $width 宽
     * @param int<1, max> $height 高
     * @return UploadedFile 上传文件
     */
    private function pngFileOfSize(string $name, int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 30, 120, 200);

        if ($color === false) {
            throw new \RuntimeException('无法分配颜色');
        }

        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);

        $path = $this->directory . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '.bin';
        imagepng($image, $path);
        $this->temporaryFiles[] = $path;

        return UploadedFile::fake($path, $name, 'image/png');
    }

    private function pngFile(string $name): UploadedFile
    {
        $path = $this->write($name . '.bin', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        ) ?: '');

        return UploadedFile::fake($path, $name, 'image/png');
    }

    /**
     * 生成文本文件。
     *
     * @param string $name 客户端文件名
     * @return UploadedFile 上传文件
     */
    private function textFile(string $name): UploadedFile
    {
        return UploadedFile::fake($this->write($name . '.bin', 'plain text'), $name, 'text/plain');
    }

    /**
     * 写入临时文件。
     *
     * @param string $name 文件名
     * @param string $contents 内容
     * @return string 绝对路径
     */
    private function write(string $name, string $contents): string
    {
        $path = $this->directory . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * extend() 应替换存储驱动（文档 7.10 的扩展点）。
     *
     * @return void
     */
    public function testExtendSwapsStorage(): void
    {
        $manager = new UploadManager(
            ['max_size' => 1024 * 1024, 'allowed_mimes' => ['image/png']],
            new MemoryStorage()
        );

        $replacement = new MemoryStorage();
        $this->assertSame($manager, $manager->extend($replacement), 'extend 应返回自身以便链式调用');

        $stored = $manager->store($this->pngFile('cover.png'), 'avatars');

        $this->assertTrue($replacement->exists($stored->path), '文件应落在替换后的驱动里');
    }

    /**
     * content_addressed 开启后，同内容落同一路径（去重 + 秒传）。
     *
     * 用路径承载哈希而不是额外索引表：`exists()` 就是去重依据，
     * 不依赖数据库、不依赖缓存，换机器也不会产生孤儿副本。
     *
     * @return void
     */
    public function testContentAddressedDeduplicates(): void
    {
        $storage = new MemoryStorage();
        $manager = $this->manager($storage, ['content_addressed' => true]);

        $first = $manager->store($this->pngFile('a.png'), 'gallery');
        $second = $manager->store($this->pngFile('b.png'), 'gallery');

        $this->assertSame($first->path, $second->path, '同内容的路径必须相同');
        $this->assertSame($first->hash, $second->hash);

        // 路径形如 {dir}/{hash前2}/{hash后2}/{hash}.png
        $this->assertSame(
            1,
            preg_match('#^gallery/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}\.png$#', $first->path),
            '路径应为 hash 分层：' . $first->path
        );
    }

    /**
     * 默认不开启 content_addressed 时保持旧行为（随机名，不去重）。
     *
     * @return void
     */
    public function testContentAddressedOffByDefault(): void
    {
        $manager = $this->manager(new MemoryStorage());

        $first = $manager->store($this->pngFile('a.png'), 'gallery');
        $second = $manager->store($this->pngFile('b.png'), 'gallery');

        $this->assertFalse($first->path === $second->path, '默认应产生两个副本（旧行为）');
        $this->assertSame($first->hash, $second->hash, '内容相同但路径不同');
    }

    /**
     * 缩略图生成器是 callable，Upload 组件不依赖 Image。
     *
     * 这条守的是架构约束 ARCH-3（组件之间不得互相引用）——
     * 如果哪天有人为了图省事直接 `use ImageManager`，会在这里之外的地方先炸。
     *
     * @return void
     */
    public function testStoreWithThumbnailsInvokesCallable(): void
    {
        $root = $this->directory . '/store';
        $manager = $this->manager(new LocalStorage($root, '/uploads'));

        $calls = [];

        $result = $manager->storeWithThumbnails(
            $this->pngFileOfSize('big.png', 400, 300),
            'gallery',
            [50, 5000],
            static function (string $src, string $dest, int $width) use (&$calls): void {
                $calls[] = $width;

                // 简化版「生成」：复制一份，测试只关心调用与落盘
                @mkdir(dirname($dest), 0755, true);
                copy($src, $dest);
            }
        );

        $this->assertSame([50], $calls, '只有比原图小的宽度才应触发（5000 会被跳过）');
        $this->assertTrue(isset($result['thumbs'][50]), 'thumbs 应包含成功生成的宽度');
        $this->assertFalse(isset($result['thumbs'][5000]), '超过原图宽度的不应出现');
        $this->assertNull($result['note'], '正常情况 note 为 null');

        // 缩略图文件真的存在
        $base = preg_replace('/\.[a-z0-9]+$/i', '', $result['file']->path);
        $this->assertTrue(is_file($root . '/' . $base . '_50.jpg'), '缩略图应落在原图同目录');
    }

    /**
     * 缩略图不能互相覆盖。
     *
     * 曾经写成 `{目录}/thumbs/{宽度}.jpg` —— 那个目录是同目录所有图共用的，
     * 第二张图会直接把第一张的缩略图盖掉。随机名模式下更糟：
     * 同一天上传的所有图都在同一个日期目录下。
     *
     * @return void
     */
    public function testThumbnailsDoNotOverwriteEachOther(): void
    {
        $root = $this->directory . '/multi';
        $manager = $this->manager(new LocalStorage($root, '/uploads'), ['content_addressed' => true]);

        $generate = static function (string $src, string $dest, int $width): void {
            @mkdir(dirname($dest), 0755, true);
            copy($src, $dest);
        };

        // 两张内容不同的图
        $a = $manager->storeWithThumbnails($this->pngFileOfSize('one.png', 400, 300), 'gallery', [50], $generate);
        $b = $manager->storeWithThumbnails($this->pngFileOfSize('two.png', 500, 400), 'gallery', [50], $generate);

        $this->assertFalse($a['file']->path === $b['file']->path, '两张图路径应不同');

        $baseA = preg_replace('/\.[a-z0-9]+$/i', '', $a['file']->path);
        $baseB = preg_replace('/\.[a-z0-9]+$/i', '', $b['file']->path);

        $this->assertTrue(is_file($root . '/' . $baseA . '_50.jpg'), '第一张的缩略图应在');
        $this->assertTrue(is_file($root . '/' . $baseB . '_50.jpg'), '第二张的缩略图也应在（没被覆盖）');
    }

    /**
     * 存储不提供本地路径时（云存储），跳过缩略图并给出原因。
     *
     * @return void
     */
    public function testThumbnailsSkippedWhenNoLocalPath(): void
    {
        $manager = $this->manager(new MemoryStorage());

        $result = $manager->storeWithThumbnails(
            $this->pngFile('cloud.png'),
            'gallery',
            [50],
            static function (string $src, string $dest, int $width): void {
                // 不该被调用
                throw new \RuntimeException('云存储下不应尝试生成缩略图');
            }
        );

        $this->assertSame([], $result['thumbs'], 'thumbs 应为空');
        $this->assertTrue(is_string($result['note']), '应说明跳过原因');
        $this->assertStringContains('不提供本地路径', (string) $result['note']);
    }
}
