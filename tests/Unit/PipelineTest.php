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
 * 记录执行顺序的中间件（前置 / 后置）。
 */
final class OrderRecorder
{
    /** @var list<string> 调用记录 */
    public array $calls = [];
}

/**
 * 前置记录中间件。
 */
final class BeforeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly OrderRecorder $recorder)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->recorder->calls[] = 'before';

        return $next($request);
    }
}

/**
 * 后置记录中间件。
 */
final class AfterMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly OrderRecorder $recorder)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $this->recorder->calls[] = 'after';

        return $response;
    }
}

/**
 * 中间件管道测试（洋葱模型）。
 */
final class PipelineTest extends TestCase
{
    /**
     * 管道应遵循「先进入、后离开」的洋葱顺序。
     *
     * @return void
     */
    public function testExecutesInOnionOrder(): void
    {
        $recorder = new OrderRecorder();
        $container = new Container();
        $container->instance(OrderRecorder::class, $recorder);

        $response = (new Pipeline($container))
            ->send(Request::create('GET', '/'))
            ->through([BeforeMiddleware::class, AfterMiddleware::class])
            ->then(static function (Request $request) use ($recorder): Response {
                $recorder->calls[] = 'handler';

                return Response::text('ok');
            });

        $this->assertSame(200, $response->status());
        $this->assertSame(['before', 'handler', 'after'], $recorder->calls);
    }

    /**
     * 闭包中间件应可用。
     *
     * @return void
     */
    public function testSupportsClosureMiddleware(): void
    {
        $container = new Container();

        $response = (new Pipeline($container))
            ->send(Request::create('GET', '/'))
            ->through([
                static function (Request $request, Closure $next): Response {
                    return $next($request)->withHeader('X-Test', '1');
                },
            ])
            ->then(static fn (Request $request): Response => Response::json(['ok' => true]));

        $this->assertSame('1', $response->headers()['X-Test'] ?? null);
    }

    /**
     * 中间件可直接短路，不进入后续处理器。
     *
     * @return void
     */
    public function testMiddlewareCanShortCircuit(): void
    {
        $container = new Container();
        $reached = false;

        $response = (new Pipeline($container))
            ->send(Request::create('GET', '/'))
            ->through([
                static fn (Request $request, Closure $next): Response => Response::json(['blocked' => true], 403),
            ])
            ->then(static function (Request $request) use (&$reached): Response {
                $reached = true;

                return Response::text('unreachable');
            });

        $this->assertFalse($reached, '短路后不应进入终点处理器');
        $this->assertSame(403, $response->status());
    }
}
