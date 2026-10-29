<?php

declare(strict_types=1);

namespace Tests\Feature;

use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;
use Tests\TestCase;

/**
 * CORS 中间件端到端测试。
 */
final class CorsTest extends TestCase
{
    /**
     * 每个测试前重置应用。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
    }

    /**
     * 未配置时应完全透传，不外发任何 CORS 头。
     *
     * @return void
     */
    public function testNoCorsWhenNotConfigured(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $response = $app->handleRequest(Request::create(
            'GET',
            '/health',
            [],
            '',
            ['Origin' => 'https://a.com']
        ));

        $this->assertSame(200, $response->status());
        $this->assertNull($response->headers()['Access-Control-Allow-Origin'] ?? null);
    }

    /**
     * 白名单内的预检应返回 204 并带 CORS 头。
     *
     * @return void
     */
    public function testPreflightReturns204WithHeaders(): void
    {
        $app = $this->bootWithCors(['https://a.com']);
        $response = $app->handleRequest(Request::create(
            'OPTIONS',
            '/health',
            [],
            '',
            [
                'Origin' => 'https://a.com',
                'Access-Control-Request-Method' => 'GET',
            ]
        ));

        $this->assertSame(204, $response->status());
        $this->assertSame('https://a.com', $response->headers()['Access-Control-Allow-Origin'] ?? null);
        $this->assertStringContains('GET', $response->headers()['Access-Control-Allow-Methods'] ?? '');
        $this->assertSame('86400', $response->headers()['Access-Control-Max-Age'] ?? null);
        $this->assertSame('Origin', $response->headers()['Vary'] ?? null);
        $this->assertSame('', $response->body());
    }

    /**
     * 预检也应带上追踪与安全头（说明中间件顺序正确）。
     *
     * @return void
     */
    public function testPreflightAlsoGetsTraceAndSecurityHeaders(): void
    {
        $app = $this->bootWithCors(['https://a.com']);
        $request = Request::create('OPTIONS', '/health', [], '', [
            'Origin' => 'https://a.com',
            'Access-Control-Request-Method' => 'GET',
        ]);
        $response = $app->handleRequest($request);

        $this->assertSame($request->requestId, $response->headers()['X-Request-Id'] ?? null);
        $this->assertSame('nosniff', $response->headers()['X-Content-Type-Options'] ?? null);
    }

    /**
     * 白名单内的实际请求应带 Allow-Origin。
     *
     * @return void
     */
    public function testSimpleRequestGetsAllowOrigin(): void
    {
        $app = $this->bootWithCors(['https://a.com']);
        $response = $app->handleRequest(Request::create(
            'GET',
            '/health',
            [],
            '',
            ['Origin' => 'https://a.com']
        ));

        $this->assertSame(200, $response->status());
        $this->assertSame('https://a.com', $response->headers()['Access-Control-Allow-Origin'] ?? null);
        $this->assertStringContains('X-Request-Id', $response->headers()['Access-Control-Expose-Headers'] ?? '');
    }

    /**
     * 非白名单来源不应获得 CORS 头（由浏览器拦截，服务端不报错）。
     *
     * @return void
     */
    public function testUnlistedOriginGetsNoAllowOrigin(): void
    {
        $app = $this->bootWithCors(['https://a.com']);
        $response = $app->handleRequest(Request::create(
            'GET',
            '/health',
            [],
            '',
            ['Origin' => 'https://evil.com']
        ));

        $this->assertSame(200, $response->status());
        $this->assertNull($response->headers()['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame('Origin', $response->headers()['Vary'] ?? null);
    }

    /**
     * 通配符 + 无凭证时应返回 `*`。
     *
     * @return void
     */
    public function testWildcardReturnsAsterisk(): void
    {
        $app = $this->bootWithCors(['*']);
        $response = $app->handleRequest(Request::create(
            'GET',
            '/health',
            [],
            '',
            ['Origin' => 'https://any.com']
        ));

        $this->assertSame('*', $response->headers()['Access-Control-Allow-Origin'] ?? null);
    }

    /**
     * 通配符 + 携带凭证时必须回显具体 Origin（不能用 `*`）。
     *
     * @return void
     */
    public function testWildcardWithCredentialsEchoesOrigin(): void
    {
        Application::reset();
        $app = Application::boot(dirname(__DIR__, 2));
        $app->config()->set('cors', [
            'allowed_origins' => ['*'],
            'supports_credentials' => true,
        ]);

        $response = $app->handleRequest(Request::create(
            'GET',
            '/health',
            [],
            '',
            ['Origin' => 'https://any.com']
        ));

        $this->assertSame('https://any.com', $response->headers()['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame('true', $response->headers()['Access-Control-Allow-Credentials'] ?? null);
    }

    /**
     * 构造启用了 CORS 的应用。
     *
     * @param list<string> $origins 允许来源
     * @return Application 应用实例
     */
    private function bootWithCors(array $origins): Application
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $app->config()->set('cors', [
            'allowed_origins' => $origins,
            'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization'],
            'exposed_headers' => ['X-Request-Id'],
            'max_age' => 86400,
            'supports_credentials' => false,
        ]);

        return $app;
    }
}
