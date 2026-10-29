<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Routing\Exception\RouteNotFoundException;
use SunsetReverie\Routing\Router;
use Tests\TestCase;

/**
 * v0.2 路由增强：参数约束、路由组、路由级中间件、缓存导出。
 */
final class RouteEnhanceTest extends TestCase
{
    /**
     * 数字约束应拒绝非数字参数。
     *
     * @return void
     */
    public function testNumberConstraintRejectsNonNumeric(): void
    {
        $router = new Router();
        $router->get('/users/{id}', static fn (): array => [])->whereNumber('id');

        $matched = $router->dispatch('GET', '/users/42');
        $this->assertSame('42', $matched['params']['id']);

        $this->assertThrows(
            RouteNotFoundException::class,
            static fn () => $router->dispatch('GET', '/users/abc')
        );
    }

    /**
     * 枚举约束应只允许声明值。
     *
     * @return void
     */
    public function testInConstraintAllowsOnlyListedValues(): void
    {
        $router = new Router();
        $router->get('/posts/{status}', static fn (): array => [])
            ->whereIn('status', ['draft', 'published']);

        $this->assertSame(
            'draft',
            $router->dispatch('GET', '/posts/draft')['params']['status']
        );

        $this->assertThrows(
            RouteNotFoundException::class,
            static fn () => $router->dispatch('GET', '/posts/archived')
        );
    }

    /**
     * 路由组应合并前缀、名称与中间件。
     *
     * @return void
     */
    public function testGroupMergesPrefixNameAndMiddleware(): void
    {
        $router = new Router();

        $router->group(['prefix' => 'admin', 'as' => 'admin.', 'middleware' => ['auth']], function (Router $r): void {
            $r->get('/articles', static fn (): array => [], 'articles.index');

            $r->group(['prefix' => 'v1', 'as' => 'v1.'], function (Router $inner): void {
                $inner->get('/users', static fn (): array => [], 'users.index');
            });
        });

        $matched = $router->dispatch('GET', '/admin/v1/users');
        $this->assertSame('/admin/v1/users', $matched['route']->uri);
        $this->assertSame('admin.v1.users.index', $matched['route']->name);
        $this->assertSame(['auth'], $matched['route']->middlewareList());
    }

    /**
     * 路由级中间件应追加在组中间件之后。
     *
     * @return void
     */
    public function testRouteMiddlewareAppendsAfterGroup(): void
    {
        $router = new Router();

        $router->group(['middleware' => ['g1']], function (Router $r): void {
            $r->get('/a', static fn (): array => [])->middleware(['r1', 'r2']);
        });

        $route = $router->dispatch('GET', '/a')['route'];

        $this->assertSame(['g1', 'r1', 'r2'], $route->middlewareList());
    }

    /**
     * 路由缓存应能导出并还原。
     *
     * @return void
     */
    public function testExportAndRestoreFromCache(): void
    {
        $router = new Router();
        $router->get('/users/{id}', [RouteEnhanceTestController::class, 'show'], 'users.show')->whereNumber('id');

        $file = sys_get_temp_dir() . '/sr-routes-' . uniqid('', true) . '.php';
        file_put_contents($file, $router->export());

        $restored = Router::fromCacheFile($file);
        $matched = $restored->dispatch('GET', '/users/7');

        $this->assertSame('/users/{id}', $matched['route']->uri);
        $this->assertSame('7', $matched['params']['id']);
        $this->assertSame('users.show', $matched['route']->name);

        unlink($file);
    }

    /**
     * 闭包处理器不可进入路由缓存。
     *
     * @return void
     */
    public function testExportRejectsClosureHandler(): void
    {
        $router = new Router();
        $router->get('/closure', static fn (): array => []);

        $this->assertThrows(\RuntimeException::class, static fn () => $router->export());
    }
}

/**
 * 测试用控制器（可缓存处理器）。
 */
final class RouteEnhanceTestController
{
    /**
     * 展示单条记录。
     *
     * @param \SunsetReverie\Http\Request $request 请求
     * @param string $id 记录 ID
     * @return array<string, mixed> 结果
     */
    public function show(\SunsetReverie\Http\Request $request, string $id): array
    {
        return ['id' => $id];
    }
}
