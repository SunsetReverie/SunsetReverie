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
}
