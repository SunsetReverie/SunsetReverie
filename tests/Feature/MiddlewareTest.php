<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Middleware\MiddlewareInterface;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use Tests\TestCase;

/**
 * 给响应打标记的测试中间件。
 */
final class StampMiddleware implements MiddlewareInterface
{
    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request)->withHeader('X-Stamp', 'route-level');
    }
}

/**
 * 端到端验证中间件链路。
 */
final class MiddlewareTest extends TestCase
{
    /**
     * 每个测试前重置应用单例。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
    }

    /**
     * 全局中间件应为所有响应补齐安全头。
     *
     * @return void
     */
    public function testSecurityHeadersAppliedToSuccessfulResponse(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $response = $app->handleRequest(Request::create('GET', '/health'));

        $this->assertSame('nosniff', $response->headers()['X-Content-Type-Options'] ?? null);
        $this->assertSame('DENY', $response->headers()['X-Frame-Options'] ?? null);
        $this->assertStringContains(
            "default-src 'none'",
            $response->headers()['Content-Security-Policy'] ?? ''
        );
    }

    /**
     * 404 响应同样应经过全局中间件（带安全头与请求 ID）。
     *
     * @return void
     */
    public function testSecurityHeadersAppliedToErrorResponse(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $request = Request::create('GET', '/not-exists');
        $response = $app->handleRequest($request);

        $this->assertSame(404, $response->status());
        $this->assertSame('nosniff', $response->headers()['X-Content-Type-Options'] ?? null);
        $this->assertSame($request->requestId, $response->headers()['X-Request-Id'] ?? null);
    }

    /**
     * 路由级中间件只对命中该路由的请求生效。
     *
     * @return void
     */
    public function testRouteLevelMiddlewareOnlyAffectsItsRoute(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $app->router()->get('/stamped', static fn (): array => ['ok' => true])
            ->middleware([StampMiddleware::class]);

        $stamped = $app->handleRequest(Request::create('GET', '/stamped'));
        $this->assertSame('route-level', $stamped->headers()['X-Stamp'] ?? null);

        $plain = $app->handleRequest(Request::create('GET', '/health'));
        $this->assertNull($plain->headers()['X-Stamp'] ?? null, '未声明的路由不应带该头');
    }
}
