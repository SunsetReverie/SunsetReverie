<?php

declare(strict_types=1);

namespace Tests\Feature;

use SunsetReverie\Api\Middleware\ResolveDomain;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;
use Tests\TestCase;

/**
 * 单域项目（未配置 api.domains）的回归测试。
 *
 * 关键约束：不能因为"有默认域"就误开域隔离——否则
 * 缓存前缀、上传目录、JWT aud 全部会被加上本来不存在的域。
 */
final class SingleDomainTest extends TestCase
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
     * 单域模式下 api.domain 应为空，业务码不打段位。
     *
     * @return void
     */
    public function testSingleDomainLeavesContextEmpty(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $app->router()->get('/probe', static fn (Request $request): array => [
            'domain' => $request->attribute(ResolveDomain::ATTRIBUTE),
            'code' => Application::getInstance()->domain()->businessCode(7),
        ], 'probe');

        $response = $app->handleRequest(Request::create('GET', '/probe'));
        $payload = (array) json_decode($response->body(), true);

        $this->assertSame('', $payload['data']['domain'], '单域项目不应写入域名');
        $this->assertSame(7, $payload['data']['code'], '无段位时业务码原样返回');
    }

    /**
     * 单域模式下 JWT 不校验 aud（向后兼容）。
     *
     * @return void
     */
    public function testSingleDomainSkipsAudienceCheck(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $app->config()->set('auth', [
            'default' => 'jwt',
            'guards' => ['jwt' => ['driver' => 'jwt', 'secret' => str_repeat('k', 32), 'ttl' => 3600]],
        ]);

        $app->router()->get('/me', static fn (Request $request): array => [
            'id' => $request->attribute(\SunsetReverie\Auth\Middleware\Authenticate::ATTRIBUTE)->id,
        ], 'me')->middleware([\SunsetReverie\Auth\Middleware\Authenticate::class]);

        $token = $app->auth()->jwt()->issue('42');

        $response = $app->handleRequest(Request::create('GET', '/me', [], '', [
            'Authorization' => 'Bearer ' . $token,
        ]));

        $this->assertSame(200, $response->status());
    }
}
