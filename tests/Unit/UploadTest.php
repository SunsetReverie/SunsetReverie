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
        $expires = (int) $query['expires'];
        $sign = (string) $query['sign'];

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
}
