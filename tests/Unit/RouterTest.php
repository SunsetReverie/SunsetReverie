<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Routing\Exception\DuplicateRouteException;
use SunsetReverie\Routing\Exception\MethodNotAllowedException;
use SunsetReverie\Routing\Exception\RouteNotFoundException;
use SunsetReverie\Routing\Router;
use Tests\TestCase;

/**
 * 路由注册与匹配测试。
 */
final class RouterTest extends TestCase
{
    /** @var Router 被测路由器 */
    private Router $router;

    /**
     * 每个测试前重建路由器。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/health', static fn (): array => ['ok' => true]);
        $this->router->get('/users/{id}', static fn (string $id): array => ['id' => $id]);
        $this->router->post('/users', static fn (): array => ['created' => true]);
    }

    /**
     * 静态路由应精确命中。
     *
     * @return void
     */
    public function testMatchesStaticRoute(): void
    {
        $matched = $this->router->dispatch('GET', '/health');

        $this->assertSame('/health', $matched['route']->uri);
        $this->assertSame([], $matched['params']);
    }

    /**
     * 动态路由应提取占位符参数。
     *
     * @return void
     */
    public function testMatchesDynamicRouteWithParams(): void
    {
        $matched = $this->router->dispatch('GET', '/users/42');

        $this->assertSame('/users/{id}', $matched['route']->uri);
        $this->assertSame('42', $matched['params']['id']);
    }

    /**
     * 路径不存在应抛 404。
     *
     * @return void
     */
    public function testThrowsNotFoundForUnknownPath(): void
    {
        $this->assertThrows(
            RouteNotFoundException::class,
            fn () => $this->router->dispatch('GET', '/missing')
        );
    }

    /**
     * 路径存在但方法不匹配应抛 405，并给出 Allow 列表。
     *
     * @return void
     */
    public function testThrowsMethodNotAllowedForWrongVerb(): void
    {
        $e = $this->assertThrows(
            MethodNotAllowedException::class,
            fn () => $this->router->dispatch('DELETE', '/users')
        );

        $this->assertSame(['POST'], $e->allowed);
    }

    /**
     * 重复注册「方法 + 路径」应在注册期抛异常。
     *
     * @return void
     */
    public function testRejectsDuplicateRoute(): void
    {
        $this->assertThrows(
            DuplicateRouteException::class,
            fn () => $this->router->get('/health', static fn (): array => [])
        );
    }

    /**
     * 路由参数应做 URL 解码（中文 slug 场景）。
     *
     * 路径保持编码形式参与匹配，取出的参数必须还原；
     * 否则前端 encodeURIComponent 之后，slugs 拿去查库永远匹配不到。
     *
     * @return void
     */
    public function testDecodesRouteParameters(): void
    {
        $router = new Router();
        $router->get('/posts/{slug}', static fn (): array => [], 'post.show');

        $encoded = rawurlencode('新写的文章 · 验证四端');
        $matched = $router->dispatch('GET', '/posts/' . $encoded);

        $this->assertSame('新写的文章 · 验证四端', $matched['params']['slug']);
    }

    /**
     * 路径里的 + 是字面加号，不应被当作空格。
     *
     * @return void
     */
    public function testKeepsPlusAsLiteral(): void
    {
        $router = new Router();
        $router->get('/tags/{name}', static fn (): array => [], 'tag.show');

        $matched = $router->dispatch('GET', '/tags/c%2B%2B');

        $this->assertSame('c++', $matched['params']['name']);
    }
}
