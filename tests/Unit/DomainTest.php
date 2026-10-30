<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Api\DomainRegistry;
use SunsetReverie\Api\DomainResolver;
use SunsetReverie\Api\Exception\DomainNotFoundException;
use SunsetReverie\Http\Request;
use Tests\TestCase;

/**
 * 多 API 域：注册表与解析器测试。
 */
final class DomainTest extends TestCase
{
    /**
     * 按路径前缀解析。
     *
     * @return void
     */
    public function testResolvesByPrefix(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('admin', $resolver->resolve(Request::create('GET', '/admin/v1/articles'))->name);
        $this->assertSame('web', $resolver->resolve(Request::create('GET', '/web/v1/posts'))->name);
    }

    /**
     * 按域名解析优先于前缀。
     *
     * @return void
     */
    public function testHostTakesPrecedence(): void
    {
        $resolver = $this->resolver();

        $request = Request::create('GET', '/v1/articles', [], '', ['Host' => 'admin-api.example.com']);

        $this->assertSame('admin', $resolver->resolve($request)->name);
    }

    /**
     * 未匹配任何域时回退到默认域。
     *
     * @return void
     */
    public function testFallsBackToDefault(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('web', $resolver->resolve(Request::create('GET', '/health'))->name);
    }

    /**
     * 前缀匹配取最长（避免 /app 抢走 /app-admin）。
     *
     * @return void
     */
    public function testLongestPrefixWins(): void
    {
        $registry = new DomainRegistry([
            'default' => 'app',
            'domains' => [
                'app' => ['prefix' => 'app'],
                'app-admin' => ['prefix' => 'app-admin'],
            ],
        ]);

        $resolver = new DomainResolver($registry);

        $this->assertSame('app-admin', $resolver->resolve(Request::create('GET', '/app-admin/v1/x'))->name);
        $this->assertSame('app', $resolver->resolve(Request::create('GET', '/app/v1/x'))->name);
    }

    /**
     * 默认关闭时，请求头不参与解析。
     *
     * @return void
     */
    public function testHeaderDetectionIsOffByDefault(): void
    {
        $resolver = $this->resolver();

        $request = Request::create('GET', '/unknown', [], '', ['X-Api-Domain' => 'admin']);

        $this->assertSame('web', $resolver->resolve($request)->name);
    }

    /**
     * 显式开启后，请求头生效。
     *
     * @return void
     */
    public function testHeaderDetectionWhenEnabled(): void
    {
        $resolver = $this->resolver(true);

        $request = Request::create('GET', '/unknown', [], '', ['X-Api-Domain' => 'admin']);

        $this->assertSame('admin', $resolver->resolve($request)->name);
    }

    /**
     * 前缀重复应在启动期失败。
     *
     * @return void
     */
    public function testDuplicatePrefixIsRejected(): void
    {
        $this->assertThrows(DomainNotFoundException::class, static fn () => new DomainRegistry([
            'default' => 'a',
            'domains' => [
                'a' => ['prefix' => 'api'],
                'b' => ['prefix' => 'api'],
            ],
        ]));
    }

    /**
     * 默认域必须在 domains 中定义。
     *
     * @return void
     */
    public function testUnknownDefaultIsRejected(): void
    {
        $this->assertThrows(DomainNotFoundException::class, static fn () => new DomainRegistry([
            'default' => 'nope',
            'domains' => ['web' => ['prefix' => 'web']],
        ]));
    }

    /**
     * 未配置域时注册表为空（走单域模式）。
     *
     * @return void
     */
    public function testEmptyRegistryMeansSingleDomain(): void
    {
        $registry = new DomainRegistry([]);

        $this->assertTrue($registry->isEmpty());
        $this->assertSame('web', (new DomainResolver($registry))->resolve(Request::create('GET', '/'))->name);
    }

    /**
     * 构造解析器。
     *
     * @param bool $allowHeader 是否允许请求头解析
     * @return DomainResolver 解析器
     */
    private function resolver(bool $allowHeader = false): DomainResolver
    {
        return new DomainResolver(new DomainRegistry([
            'default' => 'web',
            'domains' => [
                'admin' => ['prefix' => 'admin', 'hosts' => ['admin-api.example.com']],
                'web' => ['prefix' => 'web'],
            ],
        ]), $allowHeader);
    }
}
