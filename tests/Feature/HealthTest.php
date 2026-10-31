<?php

declare(strict_types=1);

namespace Tests\Feature;

use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use Tests\TestCase;

/**
 * 端到端：请求 → 路由 → 控制器 → 统一包络。
 */
final class HealthTest extends TestCase
{
    /**
     * 每个测试前重置应用单例，保证测试间互不影响。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
    }

    /**
     * GET /health 应返回统一包络与健康状态。
     *
     * @return void
     */
    public function testHealthEndpointReturnsEnvelope(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $response = $app->handleRequest(Request::create('GET', '/health'));
        $payload = $this->decode($response);

        $this->assertSame(200, $response->status());
        $this->assertSame(0, $payload['code']);
        $this->assertSame('success', $payload['message']);
        $this->assertSame('healthy', $payload['data']['status']);
        $this->assertArrayHasKey('request_id', $payload);
        $this->assertArrayHasKey('timestamp', $payload);
    }

    /**
     * 包络中的 request_id 应与响应头一致。
     *
     * @return void
     */
    public function testRequestIdIsConsistent(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $request = Request::create('GET', '/health');
        $response = $app->handleRequest($request);
        $payload = $this->decode($response);

        $this->assertSame($request->requestId, $payload['request_id']);
        $this->assertSame($request->requestId, $response->headers()['X-Request-Id'] ?? null);
    }

    /**
     * 未注册路径应返回 404 包络。
     *
     * @return void
     */
    public function testUnknownRouteReturns404Envelope(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $response = $app->handleRequest(Request::create('GET', '/nope'));
        $payload = $this->decode($response);

        $this->assertSame(404, $response->status());
        $this->assertSame(40002, $payload['code']);
        $this->assertNull($payload['data']);
    }

    /**
     * 方法不匹配应返回 405 且带 Allow 头。
     *
     * @return void
     */
    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $response = $app->handleRequest(Request::create('POST', '/health'));

        $this->assertSame(405, $response->status());
        $this->assertSame('GET', $response->headers()['Allow'] ?? null);
    }

    /**
     * 解码 JSON 响应体。
     *
     * @param Response $response 响应对象
     * @return array<string, mixed> 解码结果
     */
    private function decode(Response $response): array
    {
        return (array) json_decode($response->body(), true);
    }
}
