<?php

declare(strict_types=1);

namespace Tests\Feature;

use SunsetReverie\Auth\Middleware\Authenticate;
use SunsetReverie\Foundation\Application;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use Tests\TestCase;

/**
 * 认证中间件端到端测试。
 */
final class AuthTest extends TestCase
{
    /** @var string 测试密钥 */
    private const SECRET = '0123456789abcdef0123456789abcdef';

    /**
     * 配置认证并注册受保护路由。
     *
     * @return void
     */
    protected function setUp(): void
    {
        Application::reset();
        $app = Application::boot(dirname(__DIR__, 2));

        $app->config()->set('auth', [
            'default' => 'jwt',
            'guards' => [
                'jwt' => ['driver' => 'jwt', 'secret' => self::SECRET, 'ttl' => 3600],
            ],
        ]);

        $app->router()->get('/me', static function (Request $request): array {
            $user = $request->attribute(Authenticate::ATTRIBUTE);

            return ['id' => $user->id, 'roles' => $user->claim('roles', [])];
        })->middleware([Authenticate::class]);
    }

    /**
     * 未携带 Token 应返回 401。
     *
     * @return void
     */
    public function testRejectsWithoutToken(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $response = $app->handleRequest(Request::create('GET', '/me'));

        $this->assertSame(401, $response->status());

        $payload = (array) json_decode($response->body(), true);
        $this->assertSame(20001, $payload['code']);
    }

    /**
     * 有效 Token 应通过并把用户写入请求属性。
     *
     * @return void
     */
    public function testAcceptsValidToken(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $token = $app->auth()->jwt()->issue('42', ['roles' => ['admin']]);

        $response = $app->handleRequest(Request::create(
            'GET',
            '/me',
            [],
            '',
            ['Authorization' => 'Bearer ' . $token]
        ));

        $this->assertSame(200, $response->status());

        $payload = (array) json_decode($response->body(), true);
        $this->assertSame('42', $payload['data']['id']);
        $this->assertSame(['admin'], $payload['data']['roles']);
    }

    /**
     * 过期 Token 应返回 20002。
     *
     * @return void
     */
    public function testRejectsExpiredTokenWithDedicatedCode(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $codec = new \SunsetReverie\Auth\Jwt\JwtCodec(self::SECRET, 0);
        $token = $codec->encode(['sub' => '42'], -100);

        $response = $app->handleRequest(Request::create(
            'GET',
            '/me',
            [],
            '',
            ['Authorization' => 'Bearer ' . $token]
        ));

        $this->assertSame(401, $response->status());

        $payload = (array) json_decode($response->body(), true);
        $this->assertSame(20002, $payload['code']);
    }

    /**
     * 非 Bearer 格式应视为未认证。
     *
     * @return void
     */
    public function testRejectsNonBearerScheme(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $response = $app->handleRequest(Request::create(
            'GET',
            '/me',
            [],
            '',
            ['Authorization' => 'Basic abc']
        ));

        $this->assertSame(401, $response->status());
    }
}
