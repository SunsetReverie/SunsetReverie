<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use SunsetReverie\Api\ApiDomain;
use SunsetReverie\Api\DomainContext;
use SunsetReverie\Cache\CacheManager;
use SunsetReverie\Http\UploadedFile;
use SunsetReverie\Upload\Storage\MemoryStorage;
use SunsetReverie\Upload\UploadManager;
use Tests\TestCase;

/**
 * 域级隔离：错误码段位 / 缓存前缀 / 上传目录。
 */
final class DomainIsolationTest extends TestCase
{
    /** @var string 临时目录 */
    private string $directory;

    /**
     * 准备临时目录。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sr-iso-' . uniqid('', true);
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
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    /**
     * 未配置域时业务码原样返回。
     *
     * @return void
     */
    public function testBusinessCodeWithoutDomain(): void
    {
        $context = new DomainContext();

        $this->assertSame(7, $context->businessCode(7));
    }

    /**
     * 配置段位后业务码自动拼接。
     *
     * @return void
     */
    public function testBusinessCodeWithSegment(): void
    {
        $context = new DomainContext();
        $context->set(new ApiDomain('admin', errorSegment: 51));

        $this->assertSame(51007, $context->businessCode(7));
    }

    /**
     * 序号越界应报错（段位最多支持 999 个业务码）。
     *
     * @return void
     */
    public function testBusinessCodeRejectsOutOfRange(): void
    {
        $context = new DomainContext();
        $context->set(new ApiDomain('admin', errorSegment: 51));

        $this->assertThrows(InvalidArgumentException::class, static fn () => $context->businessCode(0));
        $this->assertThrows(InvalidArgumentException::class, static fn () => $context->businessCode(1000));
    }

    /**
     * 不同域的同名缓存键应相互隔离。
     *
     * @return void
     */
    public function testCacheIsIsolatedByDomain(): void
    {
        $context = new DomainContext();
        $manager = $this->cacheManager($context);

        $context->set(new ApiDomain('admin'));
        $manager->store()->put('token', 'admin-token');

        $context->set(new ApiDomain('web'));
        $manager->purge();

        $this->assertNull($manager->store()->get('token'), 'web 域不应读到 admin 域的缓存');
    }

    /**
     * 关闭隔离后共用前缀。
     *
     * @return void
     */
    public function testCacheIsolationCanBeDisabled(): void
    {
        $context = new DomainContext();
        $manager = $this->cacheManager($context, ['isolate_by_domain' => false]);

        $context->set(new ApiDomain('admin'));
        $manager->store()->put('shared', 'value');

        $context->set(new ApiDomain('web'));
        $manager->purge();

        $this->assertSame('value', $manager->store()->get('shared'));
    }

    /**
     * 上传路径应带上域名目录。
     *
     * @return void
     */
    public function testUploadPathIncludesDomain(): void
    {
        $context = new DomainContext();
        $context->set(new ApiDomain('admin'));

        $manager = new UploadManager(
            ['max_size' => 1024 * 1024, 'allowed_mimes' => ['image/png']],
            new MemoryStorage(),
            $context
        );

        $stored = $manager->store($this->pngFile('avatar.png'), 'avatars');

        $this->assertStringContains('admin/avatars/', $stored->path);
    }

    /**
     * 未配置域时上传路径不带域前缀（单域项目保持简洁）。
     *
     * @return void
     */
    public function testUploadPathWithoutDomain(): void
    {
        $manager = new UploadManager(
            ['max_size' => 1024 * 1024, 'allowed_mimes' => ['image/png']],
            new MemoryStorage(),
            new DomainContext()
        );

        $stored = $manager->store($this->pngFile('avatar.png'), 'avatars');

        $this->assertSame(1, preg_match('#^avatars/\d{4}/\d{2}/\d{2}/[0-9a-f]{32}\.png$#', $stored->path));
    }

    /**
     * 构造缓存管理器。
     *
     * @param DomainContext $context 域上下文
     * @param array<string, mixed> $overrides 配置覆盖
     * @return CacheManager 管理器
     */
    private function cacheManager(DomainContext $context, array $overrides = []): CacheManager
    {
        return new CacheManager(
            array_merge([
                'default' => 'array',
                'prefix' => 'sr:',
                'stores' => ['array' => ['driver' => 'array']],
            ], $overrides),
            '',
            $context
        );
    }

    /**
     * 生成真实 PNG。
     *
     * @param string $name 文件名
     * @return UploadedFile 上传文件
     */
    private function pngFile(string $name): UploadedFile
    {
        $path = $this->directory . '/' . uniqid('png', true) . '.bin';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        ) ?: '');

        return UploadedFile::fake($path, $name, 'image/png');
    }
}
