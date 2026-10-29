<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Cache\CacheManager;
use SunsetReverie\Cache\Driver\ArrayStore;
use SunsetReverie\Cache\Driver\FileStore;
use SunsetReverie\Cache\Exception\CacheException;
use SunsetReverie\Foundation\Application;
use Tests\TestCase;

/**
 * 缓存驱动与管理器测试。
 */
final class CacheTest extends TestCase
{
    /** @var string 文件缓存临时目录 */
    private string $cacheDirectory;

    /**
     * 准备临时目录。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/sr-cache-' . uniqid('', true);
    }

    /**
     * 清理临时目录。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (is_dir($this->cacheDirectory)) {
            foreach (glob($this->cacheDirectory . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->cacheDirectory);
        }
    }

    /**
     * 数组驱动的基本读写。
     *
     * @return void
     */
    public function testArrayStoreBasics(): void
    {
        $store = new ArrayStore('test:');

        $this->assertNull($store->get('missing'));
        $this->assertFalse($store->has('name'));

        $store->put('name', 'sunset');
        $this->assertTrue($store->has('name'));
        $this->assertSame('sunset', $store->get('name'));

        $store->forget('name');
        $this->assertFalse($store->has('name'));
    }

    /**
     * null 值应能正确存取（不能被当成未命中）。
     *
     * @return void
     */
    public function testArrayStoreHandlesNullValue(): void
    {
        $store = new ArrayStore('null:');
        $store->put('key', null);

        $this->assertTrue($store->has('key'));
        $this->assertNull($store->get('key', 'fallback'));
    }

    /**
     * remember 应只在未命中时执行回调。
     *
     * @return void
     */
    public function testRememberExecutesCallbackOnce(): void
    {
        $store = new ArrayStore('remember:');
        $calls = 0;
        $callback = static function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        $this->assertSame('computed', $store->remember('key', 60, $callback));
        $this->assertSame('computed', $store->remember('key', 60, $callback));
        $this->assertSame(1, $calls);
    }

    /**
     * increment 应累加。
     *
     * @return void
     */
    public function testIncrement(): void
    {
        $store = new ArrayStore('inc:');

        $this->assertSame(1, $store->increment('hits'));
        $this->assertSame(3, $store->increment('hits', 2));
        $this->assertSame(3, (int) $store->get('hits'));
    }

    /**
     * flush 应清空当前前缀。
     *
     * @return void
     */
    public function testFlush(): void
    {
        $store = new ArrayStore('flush:');
        $store->put('a', 1);
        $store->put('b', 2);

        $store->flush();

        $this->assertFalse($store->has('a'));
        $this->assertFalse($store->has('b'));
    }

    /**
     * 文件驱动应跨实例持久化。
     *
     * @return void
     */
    public function testFileStorePersistsAcrossInstances(): void
    {
        $first = new FileStore($this->cacheDirectory, 'file:');
        $first->put('token', ['id' => 7]);

        $second = new FileStore($this->cacheDirectory, 'file:');

        $this->assertSame(['id' => 7], $second->get('token'));
    }

    /**
     * 文件驱动应按 TTL 过期。
     *
     * @return void
     */
    public function testFileStoreExpiresAfterTtl(): void
    {
        $store = new FileStore($this->cacheDirectory, 'ttl:');
        $store->put('short', 'value', 1);

        $this->assertSame('value', $store->get('short'));

        sleep(2);

        $this->assertNull($store->get('short'), 'TTL 到期后应返回 null');
        $this->assertFalse($store->has('short'));
    }

    /**
     * 文件驱动 flush 应清空目录。
     *
     * @return void
     */
    public function testFileStoreFlush(): void
    {
        $store = new FileStore($this->cacheDirectory, 'file:');
        $store->put('a', 1);
        $store->put('b', 2);

        $store->flush();

        $this->assertFalse($store->has('a'));
        $this->assertFalse($store->has('b'));
    }

    /**
     * 管理器应拒绝未配置的驱动。
     *
     * @return void
     */
    public function testManagerRejectsUnknownStore(): void
    {
        $manager = new CacheManager([
            'default' => 'file',
            'stores' => ['file' => ['driver' => 'file', 'path' => $this->cacheDirectory]],
        ]);

        $this->assertThrows(CacheException::class, static fn () => $manager->store('redis'));
    }

    /**
     * 未注册驱动应给出可操作错误。
     *
     * @return void
     */
    public function testManagerRejectsUnknownDriver(): void
    {
        $manager = new CacheManager([
            'default' => 'x',
            'stores' => ['x' => ['driver' => 'memcached']],
        ]);

        $this->assertThrows(CacheException::class, static fn () => $manager->store());
    }

    /**
     * 通过 Application 获取缓存应可用。
     *
     * @return void
     */
    public function testApplicationIntegration(): void
    {
        Application::reset();
        $app = Application::boot(dirname(__DIR__, 2));

        $app->config()->set('cache', [
            'default' => 'array',
            'prefix' => 'app:',
            'stores' => ['array' => ['driver' => 'array']],
        ]);

        $cache = $app->cache();
        $cache->put('greeting', 'hello');

        $this->assertSame('hello', $cache->get('greeting'));
    }
}
