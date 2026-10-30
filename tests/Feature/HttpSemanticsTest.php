<?php

declare(strict_types=1);

namespace Tests\Feature;

use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;
use Tests\TestCase;

/**
 * HTTP 语义测试：HEAD 与 GET 的等价性、204 无体。
 *
 * 为什么单独测：HEAD 被监控探活普遍使用，如果返回 405，
 * 会让"服务是否存活"的判断直接失真——而且这种问题用 GET 测永远发现不了。
 */
final class HttpSemanticsTest extends TestCase
{
    /**
     * 每个用例前重置。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
    }

    /**
     * HEAD 应命中 GET 路由，且响应无 body。
     *
     * @return void
     */
    public function testHeadFallsBackToGet(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $get = $app->handleRequest(Request::create('GET', '/health'));
        $head = $app->handleRequest(Request::create('HEAD', '/health'));

        $this->assertSame(200, $get->status());
        $this->assertSame(200, $head->status(), 'HEAD 不应返回 405');
        $this->assertTrue($get->body() !== '', 'GET 应有响应体');
        $this->assertSame('', $head->body(), 'HEAD 不应有响应体');
    }

    /**
     * HEAD 应保留响应头（追踪 ID 与内容类型）。
     *
     * @return void
     */
    public function testHeadKeepsHeaders(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $head = $app->handleRequest(Request::create('HEAD', '/health'));
        $headers = $head->headers();

        $this->assertTrue(isset($headers['X-Request-Id']), 'HEAD 应保留 X-Request-Id');
        $this->assertTrue(isset($headers['Content-Type']), 'HEAD 应保留 Content-Type');
    }

    /**
     * 其他方法仍应返回 405，并带 Allow 头。
     *
     * @return void
     */
    public function testOtherMethodsStillRejected(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        foreach (['POST', 'DELETE', 'PUT'] as $method) {
            $response = $app->handleRequest(Request::create($method, '/health'));

            $this->assertSame(405, $response->status(), $method . ' 应 405');
            $this->assertTrue(isset($response->headers()['Allow']), $method . ' 应带 Allow 头');
        }
    }

    /**
     * HEAD 命中不存在的路径仍应 404（不能因为回退而放宽）。
     *
     * @return void
     */
    public function testHeadOnMissingPathIs404(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $this->assertSame(404, $app->handleRequest(Request::create('HEAD', '/nope'))->status());
    }
}
