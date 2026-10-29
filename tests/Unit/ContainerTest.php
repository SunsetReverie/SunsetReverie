<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Container\Container;
use SunsetReverie\Container\Exception\CircularDependencyException;
use SunsetReverie\Container\Exception\ContainerException;
use Tests\TestCase;

/** 无依赖服务（容器应能自动实例化） */
final class StandaloneService
{
    public function ping(): string
    {
        return 'pong';
    }
}

/** 依赖 StandaloneService 的服务 */
final class DependentService
{
    public function __construct(public readonly StandaloneService $inner)
    {
    }
}

/** 循环依赖 A */
final class CircularA
{
    public function __construct(public readonly CircularB $b)
    {
    }
}

/** 循环依赖 B */
final class CircularB
{
    public function __construct(public readonly CircularA $a)
    {
    }
}

/** 需要标量参数的服务 */
final class NeedsScalar
{
    public function __construct(public readonly string $name)
    {
    }
}

/**
 * 容器行为测试。
 */
final class ContainerTest extends TestCase
{
    /**
     * 应自动注入构造函数中的类类型依赖。
     *
     * @return void
     */
    public function testAutoWiresConstructorDependencies(): void
    {
        $container = new Container();
        $service = $container->get(DependentService::class);

        $this->assertTrue($service instanceof DependentService);
        $this->assertSame('pong', $service->inner->ping());
    }

    /**
     * singleton 应返回同一实例，bind 每次返回新实例。
     *
     * @return void
     */
    public function testSingletonReturnsSameInstance(): void
    {
        $container = new Container();
        $container->singleton(StandaloneService::class);

        $this->assertSame(
            $container->get(StandaloneService::class),
            $container->get(StandaloneService::class)
        );

        $container->bind(DependentService::class);
        $this->assertFalse(
            $container->get(DependentService::class) === $container->get(DependentService::class),
            'bind 应每次返回新实例'
        );
    }

    /**
     * 循环依赖应抛出异常并包含解析链。
     *
     * @return void
     */
    public function testThrowsOnCircularDependency(): void
    {
        $container = new Container();

        $e = $this->assertThrows(
            CircularDependencyException::class,
            static fn () => $container->get(CircularA::class)
        );

        $this->assertStringContains('CircularA', $e->getMessage());
        $this->assertStringContains('CircularB', $e->getMessage());
    }

    /**
     * 标量参数未提供时应抛出可读异常。
     *
     * @return void
     */
    public function testThrowsWhenScalarMissing(): void
    {
        $container = new Container();

        $this->assertThrows(
            ContainerException::class,
            static fn () => $container->get(NeedsScalar::class)
        );

        $instance = $container->get(NeedsScalar::class, ['name' => 'sunset']);
        $this->assertSame('sunset', $instance->name);
    }
}
