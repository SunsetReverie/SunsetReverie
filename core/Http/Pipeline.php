<?php

declare(strict_types=1);

namespace SunsetReverie\Http;

use Closure;
use SunsetReverie\Container\Container;
use SunsetReverie\Http\Middleware\MiddlewareInterface;

/**
 * 中间件管道（洋葱模型）。
 *
 * 执行顺序：先注册的在外层（先进入、后离开）。
 * 中间件可以是：
 * 1. MiddlewareInterface 实现类的类名（由容器解析，支持构造注入）；
 * 2. Closure(Request, Closure): Response。
 */
final class Pipeline
{
    /** @var list<class-string<MiddlewareInterface>|Closure> */
    private array $pipes = [];

    private ?Request $passable = null;

    /**
     * @param Container $container 容器（用于解析中间件实例）
     */
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * 设置进入管道的请求。
     *
     * @param Request $request 请求
     * @return self 管道实例
     */
    public function send(Request $request): self
    {
        $this->passable = $request;

        return $this;
    }

    /**
     * 设置中间件列表。
     *
     * @param list<class-string<MiddlewareInterface>|Closure> $pipes 中间件
     * @return self 管道实例
     */
    public function through(array $pipes): self
    {
        $this->pipes = array_values($pipes);

        return $this;
    }

    /**
     * 执行管道。
     *
     * @param Closure(Request): Response $destination 最终处理器
     * @return Response 响应
     * @throws \RuntimeException 未设置请求时抛出
     */
    public function then(Closure $destination): Response
    {
        if (!$this->passable instanceof Request) {
            throw new \RuntimeException('管道未设置请求，请先调用 send()');
        }

        $pipeline = array_reduce(
            array_reverse($this->pipes),
            fn (Closure $carry, mixed $pipe): Closure => $this->wrap($pipe, $carry),
            $destination
        );

        return $pipeline($this->passable);
    }

    /**
     * 将单个中间件包装为可调用闭包。
     *
     * @param class-string<MiddlewareInterface>|MiddlewareInterface|Closure $pipe 中间件
     * @param Closure(Request): Response $next 下一层
     * @return Closure(Request): Response 包装后的闭包
     */
    private function wrap(mixed $pipe, Closure $next): Closure
    {
        if ($pipe instanceof Closure) {
            return static fn (Request $request): Response => $pipe($request, $next);
        }

        // 已是实例（MiddlewareResolver 解析过别名与参数）时直接使用，不再走容器
        $instance = is_object($pipe) ? $pipe : $this->container->get($pipe);

        if (!$instance instanceof MiddlewareInterface) {
            throw new \RuntimeException(sprintf(
                '中间件 [%s] 必须实现 %s',
                is_string($pipe) ? $pipe : get_debug_type($pipe),
                MiddlewareInterface::class
            ));
        }

        return static fn (Request $request): Response => $instance->handle($request, $next);
    }
}
