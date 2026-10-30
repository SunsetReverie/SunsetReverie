<?php

declare(strict_types=1);

namespace Tests\Feature;

use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;
use SunsetReverie\RateLimit\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * 限流中间件端到端测试。
 */
final class RateLimitTest extends TestCase
{
    /**
     * 配置限流并注册受保护路由。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
        $app = Application::boot(dirname(__DIR__, 2));

        $app->config()->set('cache', [
            'default' => 'array',
            'prefix' => 'test:',
            'stores' => ['array' => ['driver' => 'array']],
        ]);

        $app->config()->set('ratelimit', [
            'enabled' => true,
            'prefix' => 'rl:',
            'by' => 'ip',
            'default' => ['max_attempts' => 2, 'decay_seconds' => 60],
            'routes' => [
                'limited.strict' => ['max_attempts' => 1, 'decay_seconds' => 60],
            ],
        ]);

        $app->router()->get('/limited', static fn (): array => ['ok' => true], 'limited')
            ->middleware([ThrottleRequests::class]);

        $app->router()->get('/strict', static fn (): array => ['ok' => true], 'limited.strict')
            ->middleware([ThrottleRequests::class]);
    }

    /**
     * 未超限时应放行并回写限流头。
     *
     * @return void
     */
    public function testAllowsAndSetsHeaders(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $response = $app->handleRequest(Request::create('GET', '/limited'));

        $this->assertSame(200, $response->status());
        $this->assertSame('2', $response->headers()['X-RateLimit-Limit'] ?? null);
        $this->assertSame('1', $response->headers()['X-RateLimit-Remaining'] ?? null);
    }

    /**
     * 超过上限应返回 429 与 Retry-After。
     *
     * @return void
     */
    public function testRejectsWith429(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $app->handleRequest(Request::create('GET', '/limited'));
        $app->handleRequest(Request::create('GET', '/limited'));
        $response = $app->handleRequest(Request::create('GET', '/limited'));

        $this->assertSame(429, $response->status());

        $payload = (array) json_decode($response->body(), true);
        $this->assertSame(20005, $payload['code']);
        $this->assertSame('0', $response->headers()['X-RateLimit-Remaining'] ?? null);
        $this->assertTrue((int) ($response->headers()['Retry-After'] ?? 0) > 0);
    }

    /**
     * 按路由名覆盖的规则应生效（strict 只允许 1 次）。
     *
     * @return void
     */
    public function testPerRouteRuleOverridesDefault(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $first = $app->handleRequest(Request::create('GET', '/strict'));
        $second = $app->handleRequest(Request::create('GET', '/strict'));

        $this->assertSame(200, $first->status());
        $this->assertSame(429, $second->status());
    }

    /**
     * enabled=false 时应完全放行。
     *
     * @return void
     */
    public function testDisabledSkipsThrottling(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $app->config()->set('ratelimit.enabled', false);

        for ($i = 0; $i < 5; $i++) {
            $response = $app->handleRequest(Request::create('GET', '/limited'));
            $this->assertSame(200, $response->status());
        }
    }

    /**
     * 通配规则应按路由名前缀匹配。
     *
     * @return void
     */
    public function testWildcardRuleMatchesByPrefix(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $app->config()->set('ratelimit.routes', [
            'limited.*' => ['max_attempts' => 1, 'decay_seconds' => 60],
        ]);

        $app->router()->get('/wild', static fn (): array => ['ok' => true], 'limited.wild')
            ->middleware([ThrottleRequests::class]);

        $this->assertSame(200, $app->handleRequest(Request::create('GET', '/wild'))->status());
        $this->assertSame(429, $app->handleRequest(Request::create('GET', '/wild'))->status());
    }

    /**
     * 多个通配命中时取最长前缀（更具体的优先）。
     *
     * @return void
     */
    public function testLongestWildcardWins(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $app->config()->set('ratelimit.routes', [
            'limited.*' => ['max_attempts' => 10, 'decay_seconds' => 60],
            'limited.strict.*' => ['max_attempts' => 1, 'decay_seconds' => 60],
        ]);

        $app->router()->get('/wild2', static fn (): array => ['ok' => true], 'limited.strict.wild')
            ->middleware([ThrottleRequests::class]);

        $this->assertSame(200, $app->handleRequest(Request::create('GET', '/wild2'))->status());
        $this->assertSame(429, $app->handleRequest(Request::create('GET', '/wild2'))->status(), '应命中 1 次/分钟的更具体规则');
    }

    /**
     * 精确规则优先于通配规则。
     *
     * @return void
     */
    public function testExactRuleBeatsWildcard(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $app->config()->set('ratelimit.routes', [
            'limited.*' => ['max_attempts' => 1, 'decay_seconds' => 60],
            'limited.exact' => ['max_attempts' => 3, 'decay_seconds' => 60],
        ]);

        $app->router()->get('/exact', static fn (): array => ['ok' => true], 'limited.exact')
            ->middleware([ThrottleRequests::class]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $app->handleRequest(Request::create('GET', '/exact'))->status());
        }

        $this->assertSame(429, $app->handleRequest(Request::create('GET', '/exact'))->status());
    }
}
