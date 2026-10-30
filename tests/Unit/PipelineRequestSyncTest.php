<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use SunsetReverie\Container\Container;
use SunsetReverie\Http\Middleware\MiddlewareInterface;
use SunsetReverie\Http\Pipeline;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use Tests\TestCase;

/**
 * 管道内 Request 同步测试。
 *
 * 背景：`Request` 是不可变的，中间件用 `withAttribute()` 会产生 clone。
 * 如果不同步回容器，认证中间件写入的 `auth.user` 永远不会出现在容器里，
 * `Auth::user()` / `Auth::id()` 就会一直返回 null——
 * 而这类问题在控制器里表现为"逻辑上应该拿到用户，实际拿到 null"，
 * 极难从错误信息推断原因。
 */
final class PipelineRequestSyncTest extends TestCase
{
    /**
     * 中间件产生的新 Request 应同步进容器。
     *
     * @return void
     */
    public function testMiddlewareRequestIsSyncedIntoContainer(): void
    {
        $container = new Container();
        $request = Request::create('GET', '/demo');
        $container->instance(Request::class, $request);

        $pipeline = new Pipeline($container);

        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request, Closure $next): Response
            {
                // 模拟认证中间件：写入当前用户后继续
                return $next($request->withAttribute('auth.user', 'user-42'));
            }
        };

        $seen = null;

        $pipeline->send($request)->through([$middleware])->then(
            static function (Request $request) use ($container, &$seen): Response {
                // 控制器/服务从容器取当前请求（AuthManager::user() 就是这么做的）
                $current = $container->get(Request::class);
                $seen = $current instanceof Request ? $current->attribute('auth.user') : null;

                return Response::json([]);
            }
        );

        $this->assertSame('user-42', $seen, '中间件写入的属性必须能被下游从容器读到');
    }

    /**
     * 多层中间件应逐层同步（最后一次写入生效）。
     *
     * @return void
     */
    public function testLaterMiddlewareOverridesEarlier(): void
    {
        $container = new Container();
        $request = Request::create('GET', '/demo');
        $container->instance(Request::class, $request);

        $pipeline = new Pipeline($container);

        $makeMiddleware = static fn (string $value): MiddlewareInterface => new class ($value) implements MiddlewareInterface {
            public function __construct(private readonly string $value)
            {
            }

            public function handle(Request $request, Closure $next): Response
            {
                return $next($request->withAttribute('trace', $this->value));
            }
        };

        $seen = null;

        $pipeline->send($request)->through([
            $makeMiddleware('outer'),
            $makeMiddleware('inner'),
        ])->then(static function () use ($container, &$seen): Response {
            $current = $container->get(Request::class);
            $seen = $current instanceof Request ? $current->attribute('trace') : null;

            return Response::json([]);
        });

        $this->assertSame('inner', $seen, '内层中间件写入的值应覆盖外层');
    }
}
