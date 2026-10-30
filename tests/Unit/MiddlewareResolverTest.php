<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Http\Middleware\MiddlewareInterface;
use SunsetReverie\Http\MiddlewareResolver;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use Closure;
use Tests\TestCase;

/**
 * 中间件解析：别名、参数、域级覆盖。
 */
final class MiddlewareResolverTest extends TestCase
{
    /**
     * 别名应解析成实例。
     *
     * @return void
     */
    public function testResolvesAlias(): void
    {
        $resolver = new MiddlewareResolver(
            $this->app(),
            ['demo' => StubMiddleware::class],
        );

        $resolved = $resolver->resolve(['demo']);

        $this->assertTrue($resolved[0] instanceof StubMiddleware);
    }

    /**
     * 域级别名应覆盖全局。
     *
     * @return void
     */
    public function testDomainAliasOverridesGlobal(): void
    {
        $resolver = new MiddlewareResolver(
            $this->app(),
            ['auth' => StubMiddleware::class],
        );

        $resolved = $resolver->resolve(['auth'], ['auth' => ParameterizedMiddleware::class]);

        $this->assertTrue($resolved[0] instanceof ParameterizedMiddleware, '域级别名应优先');
    }

    /**
     * 带参数的别名应把参数交给 HasParameters。
     *
     * @return void
     */
    public function testResolvesParameters(): void
    {
        $resolver = new MiddlewareResolver($this->app(), ['throttle' => ParameterizedMiddleware::class]);

        $resolved = $resolver->resolve(['throttle:300,60']);

        // 用 early-return 而不是 assertTrue：assertTrue 不会让静态分析收窄类型，
        // 后续访问 ->parameters 会被判为「在 callable|object 上访问属性」
        if (!$resolved[0] instanceof ParameterizedMiddleware) {
            $this->fail('应解析成 ParameterizedMiddleware 实例');
        }

        $this->assertSame(['300', '60'], $resolved[0]->parameters);
    }

    /**
     * 类名可直接使用（不需要别名）。
     *
     * @return void
     */
    public function testResolvesClassNameDirectly(): void
    {
        $resolver = new MiddlewareResolver($this->app());

        $resolved = $resolver->resolve([StubMiddleware::class]);

        $this->assertTrue($resolved[0] instanceof StubMiddleware);
    }

    /**
     * 闭包与实例原样透传。
     *
     * @return void
     */
    public function testPassesThroughNonStrings(): void
    {
        $closure = static fn (): null => null;
        $instance = new StubMiddleware();
        $resolver = new MiddlewareResolver($this->app());

        $resolved = $resolver->resolve([$closure, $instance]);

        $this->assertSame($closure, $resolved[0]);
        $this->assertSame($instance, $resolved[1]);
    }

    /**
     * 未知别名应报错并列出可用别名。
     *
     * @return void
     */
    public function testUnknownAliasThrows(): void
    {
        $resolver = new MiddlewareResolver($this->app(), ['demo' => StubMiddleware::class]);

        $e = $this->assertThrows(\InvalidArgumentException::class, static fn () => $resolver->resolve(['nope']));

        $this->assertStringContains('可用别名：demo', $e->getMessage());
    }

    /**
     * 给不支持参数的中间件传参应报错（而不是静默忽略）。
     *
     * @return void
     */
    public function testParametersOnUnsupportedMiddlewareThrows(): void
    {
        $resolver = new MiddlewareResolver($this->app(), ['demo' => StubMiddleware::class]);

        $e = $this->assertThrows(\InvalidArgumentException::class, static fn () => $resolver->resolve(['demo:1,2']));

        $this->assertStringContains('不接受参数', $e->getMessage());
    }

    /**
     * 构建最小容器（持有本测试用到的类）。
     *
     * @return \SunsetReverie\Container\Container 容器
     */
    private function app(): \SunsetReverie\Container\Container
    {
        $container = new \SunsetReverie\Container\Container();
        $container->singleton(StubMiddleware::class);
        $container->singleton(ParameterizedMiddleware::class);

        return $container;
    }
}

/**
 * 无参数中间件。
 */
final class StubMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}

/**
 * 支持参数的中间件。
 */
final class ParameterizedMiddleware implements MiddlewareInterface, \SunsetReverie\Http\Middleware\HasParameters
{
    /** @var list<string> */
    public array $parameters = [];

    public function withParameters(array $parameters): static
    {
        $clone = clone $this;
        $clone->parameters = $parameters;

        return $clone;
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
