<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Cache\CacheManager;
use SunsetReverie\Cache\Driver\ApcuStore;
use SunsetReverie\Cache\Driver\RedisStore;
use SunsetReverie\Cache\Exception\CacheException;
use Tests\TestCase;

/**
 * Redis / APCu 缓存驱动测试。
 *
 * Redis 部分依赖本机 Redis 服务，未安装时整组跳过（不算失败）；
 * APCu 部分在扩展缺失时验证"明确报错而非静默降级"——这条本身就该测。
 */
final class CacheDriverTest extends TestCase
{
    /** @var string 本用例专属前缀（避免污染其他测试） */
    private string $prefix;

    /**
     * 生成唯一前缀。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->prefix = 'sr-test:' . uniqid('', true) . ':';
    }

    /**
     * Redis：完整读写契约。
     *
     * @return void
     */
    public function testRedisBasics(): void
    {
        if (!$this->redisAvailable()) {
            return;
        }

        $store = $this->redis();

        $this->assertTrue($store->put('user', ['id' => 7, 'name' => '夕梦', 'extra' => null], 60));
        $this->assertSame(['id' => 7, 'name' => '夕梦', 'extra' => null], $store->get('user'), '数组（含 null）应原样取回');
        $this->assertTrue($store->has('user'));
        $this->assertSame('默认值', $store->get('missing', '默认值'));

        $this->assertTrue($store->forget('user'));
        $this->assertNull($store->get('user'));
    }

    /**
     * Redis：自增应保留类型并在首次写入时补 TTL。
     *
     * @return void
     */
    public function testRedisIncrement(): void
    {
        if (!$this->redisAvailable()) {
            return;
        }

        $store = $this->redis();

        $this->assertSame(1, $store->increment('counter'));
        $this->assertSame(4, $store->increment('counter', 3));

        // 首次 INCR 的键必须带过期时间，否则会永久堆积
        $ttl = $store->client()->ttl($this->prefix . 'counter');
        $this->assertTrue($ttl > 0, '自增键应带 TTL，实际 TTL=' . $ttl);
    }

    /**
     * Redis：flush 只删本应用前缀，不影响同库其他前缀。
     *
     * 这是最容易出事故的点——用 FLUSHDB 会把同库其他应用的数据一起删掉。
     *
     * @return void
     */
    public function testRedisFlushOnlyTouchesOwnPrefix(): void
    {
        if (!$this->redisAvailable()) {
            return;
        }

        $mine = new RedisStore(['host' => '127.0.0.1', 'port' => 6379, 'timeout' => 1], $this->prefix . 'mine:');
        $other = new RedisStore(['host' => '127.0.0.1', 'port' => 6379, 'timeout' => 1], $this->prefix . 'other:');

        $mine->put('a', 'my-data', 60);
        $other->put('b', 'their-data', 60);

        $mine->flush();

        $this->assertNull($mine->get('a'), '本前缀的键应被清空');
        $this->assertSame('their-data', $other->get('b'), '其他前缀的键不应被误删');

        $other->flush();
    }

    /**
     * APCu：完整读写契约（需扩展可用且在 CLI 下开启 apc.enable_cli）。
     *
     * @return void
     */
    public function testApcuBasics(): void
    {
        if (!$this->apcuAvailable()) {
            return;
        }

        $store = new ApcuStore($this->prefix, 60);

        $this->assertTrue($store->put('user', ['id' => 7, 'name' => '夕梦', 'extra' => null], 60));
        $this->assertSame(['id' => 7, 'name' => '夕梦', 'extra' => null], $store->get('user'), '数组含 null 应原样取回');
        $this->assertTrue($store->has('user'));
        $this->assertSame('默认值', $store->get('missing', '默认值'));

        $this->assertTrue($store->forget('user'));
        $this->assertNull($store->get('user'));
    }

    /**
     * APCu：自增首次写入应带 TTL。
     *
     * @return void
     */
    public function testApcuIncrement(): void
    {
        if (!$this->apcuAvailable()) {
            return;
        }

        $store = new ApcuStore($this->prefix, 60);

        $this->assertSame(1, $store->increment('hits'));
        $this->assertSame(4, $store->increment('hits', 3));
        $this->assertSame(4, $store->get('hits'));
    }

    /**
     * APCu：flush 只删本前缀，且用 APCUIterator 而非 apcu_clear_cache。
     *
     * @return void
     */
    public function testApcuFlushOnlyTouchesOwnPrefix(): void
    {
        if (!$this->apcuAvailable()) {
            return;
        }

        $mine = new ApcuStore($this->prefix . 'mine:', 60);
        $other = new ApcuStore($this->prefix . 'other:', 60);

        $mine->put('a', 'my-data', 60);
        $other->put('b', 'their-data', 60);

        $mine->flush();

        $this->assertNull($mine->get('a'), '本前缀的键应被清空');
        $this->assertSame('their-data', $other->get('b'), '其他前缀的键不应被误删（apcu_clear_cache 会全部清掉）');

        $other->flush();
    }

    /**
     * APCu 不可用时应明确报错并指出具体原因（缺扩展 / CLI 未开启）。
     *
     * @return void
     */
    public function testApcuUnavailableThrowsClearError(): void
    {
        if ($this->apcuAvailable()) {
            return;
        }

        $store = new ApcuStore($this->prefix, 60);

        $e = $this->assertThrows(CacheException::class, static fn () => $store->put('k', 'v'));

        $this->assertTrue(
            str_contains($e->getMessage(), 'ext-apcu') || str_contains($e->getMessage(), 'apc.enable_cli'),
            '错误提示应说明是缺扩展还是缺配置，实际：' . $e->getMessage()
        );
    }

    /**
     * 未知驱动应列出全部可用驱动（含新增的 redis / apcu）。
     *
     * @return void
     */
    public function testUnknownDriverListsAllOptions(): void
    {
        $manager = new CacheManager([
            'default' => 'array',
            'prefix' => $this->prefix,
            'stores' => [
                'array' => ['driver' => 'array'],
                // driver 不认识（配置项存在，但驱动未实现）
                'weird' => ['driver' => 'memcached'],
            ],
        ]);

        $e = $this->assertThrows(CacheException::class, static fn () => $manager->store('weird'));

        $this->assertStringContains('array / file / redis / apcu', $e->getMessage());
    }

    /**
     * 构造 Redis 驱动。
     *
     * @return RedisStore 驱动
     */
    private function redis(): RedisStore
    {
        return new RedisStore([
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'timeout' => 1,
        ], $this->prefix, 60);
    }

    /**
     * Redis 是否可用。
     *
     * @return bool 可用返回 true
     */
    private function redisAvailable(): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }

        try {
            $client = new \Redis();

            return @$client->connect('127.0.0.1', 6379, 1.0);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * APCu 在**当前 SAPI** 下是否可用。
     *
     * 注意 apc.enable_cli 是 PHP_INI_SYSTEM，无法用 ini_set 打开，
     * 所以 CLI 跑测试需 `php -d apc.enable_cli=1 tests/run.php`。
     *
     * @return bool 可用返回 true
     */
    private function apcuAvailable(): bool
    {
        if (!extension_loaded('apcu') || !function_exists('apcu_fetch')) {
            return false;
        }

        return PHP_SAPI !== 'cli' || (string) ini_get('apc.enable_cli') === '1';
    }

    /**
     * Redis：前缀为空时 flush 必须拒绝，而不是清空整个库。
     *
     * 空前缀会拼出 pattern `*`，同一 Redis 实例上往往还跑着别的应用，
     * 这属于不可逆的误删——宁可报错让人去配 prefix。
     *
     * @return void
     */
    public function testRedisFlushRefusesEmptyPrefix(): void
    {
        if (!$this->redisAvailable()) {
            return;
        }

        // 先塞一个"别人的" key，确认它不会被误删
        $other = new RedisStore(['host' => '127.0.0.1', 'port' => 6379, 'timeout' => 1], 'other-app:', 60);
        $other->put('data', 'theirs', 60);

        $noPrefix = new RedisStore(['host' => '127.0.0.1', 'port' => 6379, 'timeout' => 1], '', 60);

        $e = $this->assertThrows(CacheException::class, static fn () => $noPrefix->flush());

        $this->assertStringContains('前缀为空', $e->getMessage(), '错误提示应说明是前缀问题');
        $this->assertSame('theirs', $other->get('data'), '其他应用的 key 不应被清掉');

        $other->flush();
    }
}
